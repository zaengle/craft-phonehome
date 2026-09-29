/**
 * Run configuration, read once from the environment.
 *
 * The origin and the API token are supplied here rather than by the manifest, so that a site can
 * describe what to check without being able to say where the runner points or what credentials it
 * carries.
 */
export type Mode = 'capture' | 'compare';

export interface RunConfig {
    mode: Mode;
    /** Origin the manifest's relative paths resolve against, e.g. https://example.ddev.site */
    origin: string;
    /** Origin of the Phone Home plugin API. Defaults to the site origin. */
    apiOrigin: string;
    token: string;
    /** Identifies the frozen bundle. `compare` must be given the id its baseline was captured under. */
    runId: string;
    bundleDir: string;
    /** Replace a bundle that already holds a completed capture. Off unless asked for explicitly. */
    replace: boolean;
    /** Identifies this attempt within the bundle. Resolved once, so every writer agrees on it. */
    attemptId: string;
    /** Accept a self-signed certificate. For a local DDEV origin only; never for a real site. */
    insecureTls: boolean;
    /** Capture the whole scrollable document rather than the viewport. Off by default. */
    fullPage: boolean;
    /** Refuse a full-page capture taller than this, in CSS pixels. */
    maxFullPageHeight: number;
    /** How many times capture renders each page to establish it is reproducible. 1 disables. */
    stabilitySamples: number;
    /**
     * Phone Home's origin, if the finished result should be reported to it. Unset means the result
     * stays local, which is the default: a run is useful on its own and must not depend on a
     * dashboard being reachable.
     */
    dashboardOrigin: string | null;
    /**
     * Accept a self-signed certificate when reporting. Separate from `insecureTls`, which is about
     * the site being rendered: relaxing certificate checking for one must not silently relax it for
     * the other, and the two are commonly different environments.
     */
    dashboardInsecureTls: boolean;
}

function required(name: string): string {
    const value = process.env[name];

    if (!value || value.trim() === '') {
        throw new Error(`${name} is required.`);
    }

    return value.trim();
}

let cached: RunConfig | null = null;

/**
 * Resolves the attempt id once per run rather than once per caller.
 *
 * This is read by the config, the spec and the reporter, and Playwright loads all three again in
 * each worker. Generating a timestamp at every call scatters one run's evidence across several
 * directories, so the first caller publishes the id into the environment, which workers inherit.
 */
function attemptId(mode: Mode): string {
    if (!process.env.PHV_ATTEMPT_ID) {
        process.env.PHV_ATTEMPT_ID = `${mode}-${new Date().toISOString().replace(/[:.]/g, '-')}`;
    }

    return process.env.PHV_ATTEMPT_ID;
}

export function runConfig(): RunConfig {
    if (cached !== null) {
        return cached;
    }

    const mode = (process.env.PHV_MODE ?? 'capture') as Mode;

    if (mode !== 'capture' && mode !== 'compare') {
        throw new Error(`PHV_MODE must be "capture" or "compare", got "${mode}".`);
    }

    const origin = required('PHV_ORIGIN').replace(/\/+$/, '');

    // A compare run must reuse the baseline it was captured against. Generating an id here would
    // silently start a new bundle and compare a run against itself.
    const runId = mode === 'capture' ? (process.env.PHV_RUN_ID ?? new Date().toISOString().replace(/[:.]/g, '-')) : required('PHV_RUN_ID');

    // Bundles are namespaced by the site's host. The runner directory is commonly shared -- it
    // lives in the plugin repository, which is mounted into more than one project -- so a bare run
    // id like "local" would otherwise let one site's baseline sit where another site's run expects
    // to find it, and a comparison across the two would look like an ordinary pass.
    const siteKey = new URL(origin).host.replace(/[^a-z0-9.-]/gi, '_');

    cached = {
        mode,
        origin,
        apiOrigin: (process.env.PHV_API_ORIGIN ?? origin).replace(/\/+$/, ''),
        token: required('PHV_TOKEN'),
        runId,
        insecureTls: process.env.PHV_INSECURE_TLS === '1',
        fullPage: process.env.PHV_FULL_PAGE === '1',
        maxFullPageHeight: Number(process.env.PHV_MAX_FULL_PAGE_HEIGHT ?? 20_000),
        // Five, not two. A page that renders two distinct ways across eight loads -- which a real
        // one on this pilot does -- passes a two-sample check most of the time, and then produces
        // a spurious change weeks later when nobody is expecting it.
        stabilitySamples: Math.max(1, Number(process.env.PHV_STABILITY_SAMPLES ?? 5)),
        replace: process.env.PHV_REPLACE === '1',
        dashboardOrigin: process.env.PHV_DASHBOARD_ORIGIN?.replace(/\/+$/, '') || null,
        dashboardInsecureTls: process.env.PHV_DASHBOARD_INSECURE_TLS === '1',
        // Resolved here rather than at each use. Two callers each defaulting to Date.now() disagree
        // by a millisecond and quietly write one run's evidence into two directories.
        attemptId: attemptId(mode),
        bundleDir: new URL(`../runs/${siteKey}/${runId}/`, import.meta.url).pathname,
    };

    return cached;
}
