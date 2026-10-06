import { existsSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { createRequire } from 'node:module';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { dirname, join } from 'node:path';

/** A page as the plugin publishes it, after the plugin's own normalisation. */
export interface ManifestPage {
    id: string;
    path: string;
    assert: { visible: string };
    /** Selectors excluded from comparison. Absent on bundles frozen before masks existed. */
    mask?: string[];
}

export interface Manifest {
    schema_version: number;
    supported: boolean;
    enabled: boolean;
    valid: boolean;
    pages: ManifestPage[];
    errors: string[];
    /**
     * Conditions that narrowed the manifest without invalidating it -- automatic page selection
     * failing back to the explicit list, most of all. Absent on bundles frozen before warnings
     * existed. Recorded rather than acted on: the run is real, it just covers less than the site
     * asked for, and without this only the site's own log would know.
     */
    warnings?: string[];
}

/** The manifest contract version this runner understands. */
export const SUPPORTED_SCHEMA_VERSION = 1;

/**
 * Why a run could not start. Written to the bundle so the reporter can explain an empty run
 * instead of reporting a suite of zero checks as a pass.
 */
export interface Abort {
    reason: string;
    detail: string[];
}

export function bundlePaths(bundleDir: string) {
    return {
        manifest: join(bundleDir, 'manifest.json'),
        abort: join(bundleDir, 'abort.json'),
        result: join(bundleDir, 'result.json'),
        drift: join(bundleDir, 'drift.json'),
        change: join(bundleDir, 'change.json'),
        environment: join(bundleDir, 'environment.json'),
        capture: join(bundleDir, 'capture.json'),
        pendingCapture: join(bundleDir, 'capture.pending.json'),
        attempts: join(bundleDir, 'attempts'),
        masking: join(bundleDir, 'masking'),
        snapshots: join(bundleDir, 'snapshots'),
    };
}

/**
 * POSTs to the plugin API and returns the parsed body.
 *
 * `rejectUnauthorized` is threaded through this one request rather than set on the process, so that
 * pointing the runner at a local DDEV origin cannot quietly relax certificate checking for anything
 * else the run does.
 */
function postJson(url: string, token: string, insecureTls: boolean, body = '{}'): Promise<{ status: number; body: string }> {
    const target = new URL(url);
    const send = target.protocol === 'https:' ? httpsRequest : httpRequest;

    return new Promise((resolve, reject) => {
        const req = send(
            target,
            {
                method: 'POST',
                timeout: 15_000,
                rejectUnauthorized: !insecureTls,
                headers: {
                    'X-Auth-Token': token,
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(body),
                },
            },
            (res) => {
                let body = '';
                res.setEncoding('utf8');
                res.on('data', (chunk: string) => (body += chunk));
                res.on('end', () => resolve({ status: res.statusCode ?? 0, body }));
            },
        );

        req.on('timeout', () => req.destroy(new Error('timed out after 15s')));
        req.on('error', reject);
        req.end(body);
    });
}

/**
 * Reports a finished result to Phone Home.
 *
 * Authenticated with the site's own Phone Home token -- the same one the runner already holds in
 * order to read the manifest -- so reporting introduces no second credential.
 *
 * Never throws. A run is evidence about the site, and it is already written to the bundle by the
 * time this is called; an unreachable dashboard must not turn a real result into a failed run.
 * The outcome is returned so the caller can say what happened rather than stay silent.
 */
export async function reportRun(
    dashboardOrigin: string,
    token: string,
    report: unknown,
    insecureTls: boolean,
): Promise<{ ok: boolean; detail: string; runId: number | null }> {
    try {
        const response = await postJson(`${dashboardOrigin}/api/verification-runs`, token, insecureTls, JSON.stringify(report));
        const runId = parseRunId(response.body);

        if (response.status === 201) {
            return { ok: true, detail: 'recorded', runId };
        }

        // The endpoint records an attempt once and acknowledges a repeat, so a retry after a
        // timeout is a success, not a duplicate. No id is returned for upload, because the images
        // for that run were posted the first time.
        if (response.status === 200) {
            return { ok: true, detail: 'already recorded', runId: null };
        }

        return { ok: false, detail: `HTTP ${response.status} ${response.body.slice(0, 200)}`, runId: null };
    } catch (error) {
        return { ok: false, detail: error instanceof Error ? error.message : String(error), runId: null };
    }
}

function parseRunId(body: string): number | null {
    try {
        const parsed = JSON.parse(body) as { id?: unknown };

        return typeof parsed.id === 'number' ? parsed.id : null;
    } catch {
        return null;
    }
}

/**
 * A request to the dashboard, with the site token attached.
 *
 * Shared by every baseline call so the transport, the timeout and the TLS decision are written
 * once. Bodies come back as a Buffer because half of these are PNGs.
 */
function dashboardRequest(
    url: string,
    token: string,
    insecureTls: boolean,
    options: { method?: string; body?: Buffer | string; headers?: Record<string, string | number> } = {},
): Promise<{ status: number; body: Buffer }> {
    const target = new URL(url);
    const send = target.protocol === 'https:' ? httpsRequest : httpRequest;
    const body = options.body;

    return new Promise((resolve, reject) => {
        const req = send(
            target,
            {
                method: options.method ?? 'GET',
                timeout: 30_000,
                rejectUnauthorized: !insecureTls,
                headers: {
                    'X-Auth-Token': token,
                    Accept: 'application/json',
                    ...(body === undefined ? {} : { 'Content-Length': Buffer.byteLength(body) }),
                    ...options.headers,
                },
            },
            (res) => {
                const chunks: Buffer[] = [];
                res.on('data', (chunk: Buffer) => chunks.push(chunk));
                res.on('end', () => resolve({ status: res.statusCode ?? 0, body: Buffer.concat(chunks) }));
            },
        );

        req.on('timeout', () => req.destroy(new Error('timed out after 30s')));
        req.on('error', reject);
        req.end(body);
    });
}

/**
 * Sends a completed bundle to the dashboard: the frozen manifest, its provenance, and every
 * snapshot.
 *
 * Sealed last and separately, so a push that dies halfway leaves something obviously unfinished
 * rather than a baseline quietly missing a page. The dashboard refuses to serve an unsealed one.
 */
export async function pushBaseline(
    dashboardOrigin: string,
    token: string,
    runId: string,
    bundleDir: string,
    insecureTls: boolean,
    replace: boolean,
): Promise<{ ok: boolean; detail: string }> {
    const paths = bundlePaths(bundleDir);

    if (!existsSync(paths.manifest) || !existsSync(paths.capture)) {
        return { ok: false, detail: 'no completed capture to push' };
    }

    try {
        const opened = await dashboardRequest(`${dashboardOrigin}/api/verification-baselines`, token, insecureTls, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                run_id: runId,
                manifest: JSON.parse(readFileSync(paths.manifest, 'utf8')) as unknown,
                record: JSON.parse(readFileSync(paths.capture, 'utf8')) as unknown,
                // Carried through rather than assumed. A capture that reached this point cleared
                // whatever was on disk, but the dashboard's copy is the one a CI job would read,
                // and replacing that is a decision somebody has to have made.
                replace,
            }),
        });

        if (opened.status !== 201) {
            return { ok: false, detail: `HTTP ${opened.status} ${opened.body.toString('utf8').slice(0, 200)}` };
        }

        const { id } = JSON.parse(opened.body.toString('utf8')) as { id: number };
        const names = existsSync(paths.snapshots) ? readdirSync(paths.snapshots) : [];

        for (const name of names) {
            const response = await dashboardRequest(`${dashboardOrigin}/api/verification-baselines/${id}/files`, token, insecureTls, {
                method: 'POST',
                headers: { 'Content-Type': 'application/octet-stream', 'X-Baseline-File': name },
                body: readFileSync(join(paths.snapshots, name)),
            });

            if (response.status !== 201) {
                return { ok: false, detail: `${name}: HTTP ${response.status}` };
            }
        }

        const sealed = await dashboardRequest(`${dashboardOrigin}/api/verification-baselines/${id}/seal`, token, insecureTls, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: '{}',
        });

        return sealed.status === 200
            ? { ok: true, detail: `${names.length} snapshot(s) stored` }
            : { ok: false, detail: `seal: HTTP ${sealed.status}` };
    } catch (error) {
        return { ok: false, detail: error instanceof Error ? error.message : String(error) };
    }
}

/**
 * Whether the dashboard already holds a sealed baseline under this run id.
 *
 * Without this, a capture running where no local bundle exists -- which is every CI job -- would
 * silently replace a sealed reference. That is precisely the failure the local `bundle_exists`
 * refusal prevents: a comparison that found a regression could be made to pass by re-capturing,
 * with nothing recording that the reference moved.
 *
 * A dashboard that cannot be reached answers false. The local refusal still applies, and refusing
 * to capture because a network call failed would be worse than the risk it guards against.
 */
export async function baselineExists(dashboardOrigin: string, token: string, runId: string, insecureTls: boolean): Promise<boolean> {
    try {
        const response = await dashboardRequest(`${dashboardOrigin}/api/verification-baselines/${encodeURIComponent(runId)}`, token, insecureTls);

        return response.status === 200;
    } catch {
        return false;
    }
}

/**
 * Rebuilds a bundle on disk from the dashboard's copy.
 *
 * This is what lets a comparison run somewhere that has never seen the capture. Every file is
 * checked against the checksum the dashboard recorded: a baseline that arrived corrupted would
 * otherwise become the reference every future comparison is held against, and the diff it produced
 * would look like a change to the site.
 */
export async function pullBaseline(
    dashboardOrigin: string,
    token: string,
    runId: string,
    bundleDir: string,
    insecureTls: boolean,
): Promise<{ ok: boolean; detail: string }> {
    const paths = bundlePaths(bundleDir);

    try {
        const response = await dashboardRequest(`${dashboardOrigin}/api/verification-baselines/${encodeURIComponent(runId)}`, token, insecureTls);

        if (response.status !== 200) {
            return { ok: false, detail: `HTTP ${response.status}` };
        }

        const payload = JSON.parse(response.body.toString('utf8')) as {
            manifest: unknown;
            record: unknown;
            files: { name: string; checksum: string; url: string }[];
        };

        mkdirSync(paths.snapshots, { recursive: true });

        for (const file of payload.files) {
            const download = await dashboardRequest(file.url, token, insecureTls);

            if (download.status !== 200) {
                return { ok: false, detail: `${file.name}: HTTP ${download.status}` };
            }

            const checksum = createHash('sha256').update(download.body).digest('hex');

            if (checksum !== file.checksum) {
                return { ok: false, detail: `${file.name}: checksum mismatch` };
            }

            writeFileSync(join(paths.snapshots, file.name), download.body);
        }

        // Written after the snapshots, because the capture record is what makes a bundle look
        // complete. Landing it first would let a comparison start against a half-populated one.
        writeFileSync(paths.manifest, `${JSON.stringify(payload.manifest, null, 2)}\n`);
        writeFileSync(paths.capture, `${JSON.stringify(payload.record, null, 2)}\n`);

        return { ok: true, detail: `${payload.files.length} snapshot(s) restored` };
    } catch (error) {
        return { ok: false, detail: error instanceof Error ? error.message : String(error) };
    }
}

/** One screenshot to send: which page it is of, which of the three, and where it is on disk. */
export interface Artifact {
    page: string;
    variant: 'expected' | 'actual' | 'diff';
    file: string;
}

const VARIANTS = ['expected', 'actual', 'diff'] as const;

/**
 * Finds the screenshots Playwright wrote for this attempt.
 *
 * Playwright only writes these when a screenshot check fails, which is exactly when somebody wants
 * to look at them -- a run where nothing moved has nothing to show and sends nothing. The page name
 * is recovered by stripping the variant suffix rather than by splitting on a hyphen, because
 * generated page ids contain hyphens of their own.
 */
export function findArtifacts(outputDir: string): Artifact[] {
    if (!existsSync(outputDir)) {
        return [];
    }

    const found: Artifact[] = [];

    const walk = (dir: string) => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const full = join(dir, entry.name);

            if (entry.isDirectory()) {
                walk(full);
                continue;
            }

            for (const variant of VARIANTS) {
                const suffix = `-${variant}.png`;

                if (entry.name.endsWith(suffix)) {
                    found.push({ page: entry.name.slice(0, -suffix.length), variant, file: full });
                    break;
                }
            }
        }
    };

    walk(outputDir);

    return found;
}

/**
 * Posts one screenshot as a raw PNG body, with its metadata in headers.
 *
 * Raw rather than multipart because the runner has no dependencies, and hand-rolling a multipart
 * encoder to send a single file is more moving parts than the job needs.
 */
export function postArtifact(
    dashboardOrigin: string,
    runId: number,
    token: string,
    artifact: Artifact,
    insecureTls: boolean,
): Promise<{ status: number; body: string }> {
    const target = new URL(`${dashboardOrigin}/api/verification-runs/${runId}/artifacts`);
    const send = target.protocol === 'https:' ? httpsRequest : httpRequest;
    const body = readFileSync(artifact.file);

    return new Promise((resolve, reject) => {
        const req = send(
            target,
            {
                method: 'POST',
                timeout: 30_000,
                rejectUnauthorized: !insecureTls,
                headers: {
                    'X-Auth-Token': token,
                    'X-Artifact-Page': artifact.page,
                    'X-Artifact-Variant': artifact.variant,
                    Accept: 'application/json',
                    'Content-Type': 'image/png',
                    'Content-Length': body.byteLength,
                },
            },
            (res) => {
                let text = '';
                res.setEncoding('utf8');
                res.on('data', (chunk: string) => (text += chunk));
                res.on('end', () => resolve({ status: res.statusCode ?? 0, body: text }));
            },
        );

        req.on('timeout', () => req.destroy(new Error('timed out after 30s')));
        req.on('error', reject);
        req.end(body);
    });
}

/**
 * Fetches the manifest from the plugin API and decides whether a run can proceed.
 *
 * Every refusal is explicit. An older plugin omits the field entirely, a site that has not opted in
 * reports it disabled, and a site with a typo reports it invalid -- none of which may be treated as
 * a suite that ran and passed.
 */
export async function fetchReport(apiOrigin: string, token: string, insecureTls = false): Promise<{ manifest: Manifest; payload: Record<string, unknown> } | Abort> {
    let payload: Record<string, unknown>;

    try {
        const response = await postJson(`${apiOrigin}/actions/phonehome/api`, token, insecureTls);

        if (response.status < 200 || response.status >= 300) {
            return { reason: 'api_unavailable', detail: [`The plugin API answered ${response.status}.`] };
        }

        payload = JSON.parse(response.body) as Record<string, unknown>;
    } catch (error) {
        return { reason: 'api_unavailable', detail: [`Could not reach the plugin API: ${(error as Error).message}`] };
    }

    // The response is validated before anything is read off it. Casting the parsed JSON straight
    // to an interface means a null or a string where an object was expected throws while Playwright
    // is still loading its configuration -- before any reporter exists to record that the run died.
    if (payload === null || typeof payload !== 'object' || Array.isArray(payload)) {
        return { reason: 'api_unavailable', detail: ['The plugin API did not return a JSON object.'] };
    }

    const candidate = (payload as Record<string, unknown>).verification;

    if (candidate !== undefined && (candidate === null || typeof candidate !== 'object' || Array.isArray(candidate))) {
        return { reason: 'unsupported', detail: ['The plugin API returned a verification field that is not an object.'] };
    }

    const manifest = candidate as Manifest | undefined;

    if (!manifest || manifest.supported !== true) {
        return { reason: 'unsupported', detail: ['This plugin version does not publish a verification manifest.'] };
    }

    if (manifest.schema_version !== SUPPORTED_SCHEMA_VERSION) {
        return {
            reason: 'unsupported',
            detail: [`The site publishes manifest schema ${manifest.schema_version}; this runner understands ${SUPPORTED_SCHEMA_VERSION}.`],
        };
    }

    if (!manifest.enabled) {
        return { reason: 'disabled', detail: ['Verification is not configured on this site.'] };
    }

    if (!manifest.valid) {
        return { reason: 'invalid_manifest', detail: manifest.errors };
    }

    if (!Array.isArray(manifest.pages)) {
        return { reason: 'invalid_manifest', detail: ['The manifest does not list pages.'] };
    }

    if (manifest.pages.length === 0) {
        return { reason: 'invalid_manifest', detail: ['The manifest is valid but names no pages.'] };
    }

    return { manifest, payload: payload as Record<string, unknown> };
}

/**
 * Identity of the site a bundle was captured against, and the settings it was captured with.
 *
 * A frozen page list on its own does not say which site produced it. Two sites with the same
 * templates yield interchangeable-looking bundles, and comparing one against the other reports a
 * confident pass about the wrong system.
 */
/**
 * What rendered the baseline.
 *
 * A screenshot is only comparable against one taken by the same renderer. Bumping the Playwright
 * image changes font rasterisation and image decoding, so every baseline silently starts comparing
 * against differently-drawn pixels -- and because the bundle still validates on every other axis,
 * the resulting diffs look like changes to the site.
 *
 * Read from the installed packages rather than from a running browser, so recording it costs
 * nothing and cannot itself fail.
 */
export interface RenderEnvironment {
    playwright: string;
    chromium: string;
    chromium_revision: string;
    platform: string;
}

export function renderEnvironment(): RenderEnvironment {
    const require = createRequire(import.meta.url);

    const readJson = (specifier: string, relative = ''): Record<string, unknown> => {
        try {
            // Resolved through the package root and read from disk rather than required directly.
            // playwright-core's `exports` map does not expose browsers.json, so requiring that path
            // throws and the revision silently becomes unknown.
            const root = require.resolve(`${specifier}/package.json`);

            return JSON.parse(readFileSync(relative === '' ? root : join(dirname(root), relative), 'utf8')) as Record<string, unknown>;
        } catch {
            return {};
        }
    };

    const playwright = readJson('@playwright/test').version;
    const browsers = readJson('playwright-core', 'browsers.json').browsers;
    const chromium = Array.isArray(browsers)
        ? (browsers.find((entry: { name?: string }) => entry.name === 'chromium') as { revision?: string; browserVersion?: string } | undefined)
        : undefined;

    // 'unknown' rather than a guess, and an unknown still counts as a mismatch: two baselines whose
    // renderer cannot be established are not known to be comparable.
    return {
        playwright: typeof playwright === 'string' ? playwright : 'unknown',
        chromium: typeof chromium?.browserVersion === 'string' ? chromium.browserVersion : 'unknown',
        chromium_revision: typeof chromium?.revision === 'string' ? chromium.revision : 'unknown',
        platform: `${process.platform}-${process.arch}`,
    };
}

export interface CaptureRecord {
    run_id: string;
    completed_at: string;
    runner_contract: number;
    site: {
        origin: string;
        api_origin: string;
        build_id: string | null;
        craft_version: string | null;
        environment: string | null;
        /**
         * Craft and every plugin, by version, as the site reported them at capture. Absent on
         * bundles frozen before this was recorded, which a comparison treats as "unknown" rather
         * than "unchanged".
         */
        packages?: Record<string, string>;
    };
    settings: {
        full_page: boolean;
        viewport: { width: number; height: number };
        stability_samples?: number;
    };
    environment: RenderEnvironment;
    expected_checks: string[];
}

/** Bumped when a bundle's shape changes in a way that makes older bundles unusable. */
export const RUNNER_CONTRACT = 3;

/**
 * Reads the identity fields out of a report payload. Absent values stay null rather than being
 * guessed at; `build_id` in particular is unset on most sites today.
 */
export function readIdentity(payload: Record<string, unknown>): CaptureRecord['site'] {
    const text = (key: string): string | null => (typeof payload[key] === 'string' ? (payload[key] as string) : null);

    return {
        origin: '',
        api_origin: '',
        build_id: text('build_id'),
        craft_version: text('craft_version'),
        environment: text('environment'),
        packages: readPackages(payload),
    };
}

/**
 * Craft and every plugin the site reports, by version.
 *
 * Craft core is keyed by its Composer name because that is how a remediation names it; plugins are
 * keyed by handle, which is all the plugin API carries for them.
 */
export function readPackages(payload: Record<string, unknown>): Record<string, string> {
    const packages: Record<string, string> = {};

    if (typeof payload.craft_version === 'string') {
        packages['craftcms/cms'] = payload.craft_version;
    }

    const plugins = payload.plugins;

    if (plugins !== null && typeof plugins === 'object' && !Array.isArray(plugins)) {
        for (const [handle, info] of Object.entries(plugins as Record<string, unknown>)) {
            const plugin = info as { version?: unknown; package_name?: unknown } | null;
            const version = plugin?.version;

            if (typeof version === 'string') {
                // Keyed by Composer name where the plugin reports one, because that is how a
                // remediation names what it asked to move; by handle on older plugin versions.
                const name = typeof plugin?.package_name === 'string' && plugin.package_name !== '' ? plugin.package_name : handle;

                packages[name] = version;
            }
        }
    }

    return packages;
}

/**
 * How the site's installed versions moved between the baseline and now.
 *
 * A comparison only proves something about a change if the change reached the environment being
 * compared. The lock file moving on a branch says nothing about that; the versions the site itself
 * reports do. `known` is false when the baseline predates this record, and a caller must treat that
 * as "cannot say" rather than "nothing moved".
 */
export interface EnvironmentDelta {
    known: boolean;
    changed: { name: string; before: string | null; after: string | null }[];
    /** Every version the site reports now, so a caller can ask about a package that did not move. */
    current: Record<string, string>;
}

export function describeEnvironmentDelta(before: Record<string, string> | undefined, after: Record<string, string>): EnvironmentDelta {
    // A baseline that recorded nothing is as uninformative as one that recorded no field at all:
    // an empty map is a site that reported no versions, not a site with no packages.
    if (before === undefined || Object.keys(before).length === 0) {
        return { known: false, changed: [], current: after };
    }

    const names = [...new Set([...Object.keys(before), ...Object.keys(after)])].sort();
    const changed = names
        .filter((name) => before[name] !== after[name])
        .map((name) => ({ name, before: before[name] ?? null, after: after[name] ?? null }));

    return { known: true, changed, current: after };
}

/**
 * Why a comparison found no baseline to compare against.
 *
 * In a workflow re-run this is usually not a lost capture. The run id carries the attempt number,
 * so a re-run that repeats the comparison without repeating the baseline asks for a capture nobody
 * took. That is the honest outcome -- reusing the earlier attempt's capture is what the id exists
 * to prevent -- but the remedy is "Re-run all jobs", and the reason says so.
 */
export function noBaselineAbort(bundleDir: string, runId: string, runAttempt: string | undefined): Abort {
    const attempt = Number(runAttempt ?? '1');

    if (Number.isInteger(attempt) && attempt > 1) {
        return {
            reason: 'no_baseline',
            detail: [
                `No baseline was captured under ${runId}. This is attempt ${attempt} of the workflow run, and a re-run of only the failed jobs repeats the comparison without repeating the baseline.`,
                'Use "Re-run all jobs" so the baseline is captured again under this attempt\'s run id.',
            ],
        };
    }

    return {
        reason: 'no_baseline',
        detail: [`${bundleDir} holds no completed capture.`, 'Either none was taken, or the capture that was taken did not finish successfully.'],
    };
}

export function isAbort<T extends object>(value: T | Abort): value is Abort {
    return 'reason' in value;
}

export function writeBundle(bundleDir: string, value: Manifest | Abort): void {
    mkdirSync(bundleDir, { recursive: true });
    const paths = bundlePaths(bundleDir);

    writeFileSync(isAbort(value) ? paths.abort : paths.manifest, `${JSON.stringify(value, null, 2)}\n`);
}

/**
 * The checks a bundle's manifest requires, by name.
 *
 * Recorded at capture so a comparison can establish that every check it owed actually ran. A report
 * summarising only the checks that happened to execute will call a filtered or partially-collected
 * run a pass, having never run the assertion that would have caught the defect.
 */
export function expectedChecks(manifest: Manifest): string[] {
    return manifest.pages.flatMap((page) => [`assert:${page.id}`, `screenshot:${page.id}`, `text:${page.id}`]).sort();
}

export function readCaptureRecord(bundleDir: string): CaptureRecord | null {
    const path = bundlePaths(bundleDir).capture;

    if (!existsSync(path)) {
        return null;
    }

    try {
        return JSON.parse(readFileSync(path, 'utf8')) as CaptureRecord;
    } catch {
        return null;
    }
}

/**
 * Checks a bundle against the run about to use it.
 *
 * Every mismatch here means the comparison would be measuring something other than what the
 * baseline recorded, so each returns a reason rather than a boolean.
 *
 * @return list<string> Reasons the bundle cannot be used, empty when it can
 */
export function bundleObjections(
    record: CaptureRecord,
    origin: string,
    apiOrigin: string,
    fullPage: boolean,
    environment: RenderEnvironment,
): string[] {
    const objections: string[] = [];

    if (record.runner_contract !== RUNNER_CONTRACT) {
        objections.push(`The baseline was captured by runner contract ${record.runner_contract}; this runner is ${RUNNER_CONTRACT}.`);
    }

    if (record.site.origin !== origin) {
        objections.push(`The baseline was captured against ${record.site.origin}, not ${origin}.`);
    }

    if (record.site.api_origin !== apiOrigin) {
        objections.push(`The baseline read its manifest from ${record.site.api_origin}, not ${apiOrigin}.`);
    }

    if (record.settings.full_page !== fullPage) {
        objections.push(`The baseline was captured with full_page=${record.settings.full_page}; this run has full_page=${fullPage}.`);
    }

    // Compared field by field so the objection names what moved. A bundle recorded before this was
    // tracked has no environment at all, which is itself a reason to refuse it.
    const before = record.environment;

    if (before === undefined) {
        objections.push('The baseline does not record what rendered it, so it cannot be compared against.');
    } else {
        for (const key of ['playwright', 'chromium', 'chromium_revision', 'platform'] as const) {
            if (before[key] !== environment[key]) {
                objections.push(`The baseline was rendered with ${key} ${before[key]}; this run has ${environment[key]}.`);
            }
        }
    }

    return objections;
}

/**
 * Describes how the site's current definitions differ from the frozen ones.
 *
 * Drift is recorded, never acted on. Adopting the current manifest mid-pair is how a page that was
 * deleted between capture and compare would silently leave the suite instead of failing.
 */
export function describeDrift(frozen: Manifest, current: Manifest): string[] {
    const key = (page: ManifestPage) => `${page.id} ${page.path} ${page.assert.visible} ${(page.mask ?? []).join('|')}`;
    const frozenKeys = new Map(frozen.pages.map((page) => [page.id, key(page)]));
    const currentKeys = new Map(current.pages.map((page) => [page.id, key(page)]));
    const drift: string[] = [];

    for (const [id, value] of frozenKeys) {
        if (!currentKeys.has(id)) {
            drift.push(`Page "${id}" is no longer in the site's manifest.`);
        } else if (currentKeys.get(id) !== value) {
            drift.push(`Page "${id}" is defined differently now than when the baseline was captured.`);
        }
    }

    for (const id of currentKeys.keys()) {
        if (!frozenKeys.has(id)) {
            drift.push(`Page "${id}" was added to the site's manifest after the baseline was captured.`);
        }
    }

    return drift;
}

export function readFrozenManifest(bundleDir: string): Manifest {
    return JSON.parse(readFileSync(bundlePaths(bundleDir).manifest, 'utf8')) as Manifest;
}
