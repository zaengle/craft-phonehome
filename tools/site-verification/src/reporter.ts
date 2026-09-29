import type { FullResult, Reporter, TestCase, TestResult } from '@playwright/test/reporter';
import { existsSync, mkdirSync, readFileSync, readdirSync, renameSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { runConfig } from './config';
import { bundlePaths, findArtifacts, postArtifact, reportRun, type CaptureRecord } from './manifest';

/**
 * Outcomes are reported as four distinct states rather than pass/fail.
 *
 * `changes_detected` needs a person to interpret it and does not by itself mean the site is broken;
 * `inconclusive` means the run could not establish anything either way. Collapsing either into
 * "failed" would train an operator to ignore the report, and collapsing either into "passed" would
 * make the report actively misleading.
 */
type Outcome = 'passed' | 'changes_detected' | 'failed' | 'inconclusive';

/**
 * Ordered worst-first, with both observations ahead of the absence of one.
 *
 * `failed` and `changes_detected` are things the run saw. `inconclusive` is the run admitting it
 * saw nothing. Ranking the admission above the observation meant a page whose text had visibly
 * changed was reported as "could not establish anything", which an operator reasonably reads as
 * "re-run it later" -- and the change they needed to look at went unread.
 */
const PRECEDENCE: Outcome[] = ['failed', 'changes_detected', 'inconclusive', 'passed'];

/** Outcomes that are evidence, and so are never downgraded by an incomplete run. */
const OBSERVED: Outcome[] = ['failed', 'changes_detected'];

const INCONCLUSIVE = 'INCONCLUSIVE:';

interface CheckResult {
    id: string;
    kind: 'assert' | 'screenshot' | 'text' | 'gate';
    outcome: Outcome;
    durationMs: number;
    diagnostic: string | null;
}

export default class VerificationReporter implements Reporter {
    private readonly checks: CheckResult[] = [];

    private readonly config = runConfig();

    private readonly paths = bundlePaths(runConfig().bundleDir);

    private readonly attemptId = this.config.attemptId;

    onTestEnd(test: TestCase, result: TestResult): void {
        const [rawKind, ...rest] = test.title.split(':');
        const kind = rawKind === 'assert' || rawKind === 'screenshot' || rawKind === 'text' ? rawKind : 'gate';
        const message = result.errors.map((error) => error.message ?? '').join('\n');

        this.checks.push({
            id: rest.join(':') || test.title,
            kind,
            outcome: this.classify(kind, result, message),
            durationMs: result.duration,
            diagnostic: message === '' ? null : message.split('\n').slice(0, 4).join(' ').trim(),
        });
    }

    async onEnd(result: FullResult): Promise<void> {
        const sidecarErrors: string[] = [];
        const pending = this.readRecord(this.paths.pendingCapture) ?? this.readRecord(this.paths.capture);
        const expected = pending?.expected_checks ?? [];
        const ran = this.checks.map((check) => `${check.kind}:${check.id}`);
        const missing = expected.filter((id) => !ran.includes(id));

        const worst = PRECEDENCE.find((candidate) => this.checks.some((check) => check.outcome === candidate)) ?? 'inconclusive';

        const change = this.readJson<unknown>(this.paths.change, sidecarErrors, 'change');
        const drift = this.readJson<string[]>(this.paths.drift, sidecarErrors, 'manifest_drift') ?? [];

        // A report that summarises only the checks that happened to run will call a filtered or
        // partially-collected run a pass, having never executed the assertion that would have
        // caught the defect. Anything the manifest owed and did not deliver is missing evidence.
        const incomplete =
            this.checks.length === 0 ||
            missing.length > 0 ||
            sidecarErrors.length > 0 ||
            result.status === 'interrupted' ||
            result.status === 'timedout';

        // An incomplete run downgrades a pass, never an observation. A check that ran and saw
        // something is evidence; burying it because some other check never ran loses the one
        // result the operator most needs. What went missing stays in `missing_checks` either way.
        const overall: Outcome = OBSERVED.includes(worst) ? worst : incomplete ? 'inconclusive' : worst;

        const report = {
            run_id: this.config.runId,
            attempt_id: this.attemptId,
            mode: this.config.mode,
            origin: this.config.origin,
            full_page: this.config.fullPage,
            finished_at: new Date().toISOString(),
            overall,
            missing_checks: missing,
            counts: PRECEDENCE.reduce<Record<string, number>>((counts, candidate) => {
                counts[candidate] = this.checks.filter((check) => check.outcome === candidate).length;
                return counts;
            }, {}),
            // Reported by the site alongside its manifest: the pages below are real, but they are
            // fewer than the site intended to publish.
            manifest_warnings: this.readManifestWarnings(),
            // What the run chose not to look at. A clean result is only as meaningful as the
            // coverage behind it, and a mask is coverage deliberately given up.
            masks: this.readMasks(),
            // How much of each page's text those masks actually removed. The selector list says
            // what was given up; only the run can say how much, and "we mask one ticker" reads
            // very differently once it turns out to be most of the page.
            masked_text_share: this.readMaskedShares(),
            // What the run was verified across, when the loop applied it. A comparison whose
            // change never landed is a pass about nothing, so it is recorded next to the verdict.
            change,
            // Recorded, not acted on: the run used the frozen definitions either way, and an
            // operator reading a clean result needs to know the site has since been redefined.
            manifest_drift: drift,
            // Named so an unreadable sidecar is a reported condition rather than a lost run.
            sidecar_errors: sidecarErrors,
            checks: this.checks,
        };

        const body = `${JSON.stringify(report, null, 2)}\n`;
        const attemptDir = join(this.paths.attempts, this.attemptId);

        mkdirSync(attemptDir, { recursive: true });
        writeFileSync(join(attemptDir, 'result.json'), body);
        writeFileSync(this.paths.result, body);

        this.settleCaptureRecord(overall, pending);

        process.stdout.write(`\n  ${this.config.mode}: ${overall.toUpperCase()}  →  ${this.paths.result}\n`);

        if (missing.length > 0) {
            process.stdout.write(`  ${missing.length} expected check(s) never ran: ${missing.join(', ')}\n`);
        }

        await this.report(report);
    }

    /**
     * Sends the finished result to Phone Home, if this run was told where that is.
     *
     * Deliberately the last thing that happens, and deliberately unable to fail the run. The result
     * is already on disk; a dashboard that is down, misconfigured or simply not part of this setup
     * must not turn a real verdict into a failed run. What it does instead is say so on stdout,
     * because a result silently not arriving is the failure mode worth avoiding here.
     */
    private async report(report: unknown): Promise<void> {
        if (this.config.dashboardOrigin === null) {
            return;
        }

        const outcome = await reportRun(this.config.dashboardOrigin, this.config.token, report, this.config.dashboardInsecureTls);

        process.stdout.write(
            outcome.ok
                ? `  reported to ${this.config.dashboardOrigin} (${outcome.detail})\n`
                : `  ⚠  could not report to ${this.config.dashboardOrigin}: ${outcome.detail}\n`,
        );

        if (outcome.runId !== null) {
            await this.sendArtifacts(outcome.runId);
        }
    }

    /**
     * Sends the screenshots behind a changed or failed check.
     *
     * Comparisons only. A capture writes an `-actual.png` for every page as it establishes each
     * baseline, and those are not a change -- sending them would double the stored bytes on every
     * capture and file the baseline itself under "what changed". Within a comparison Playwright
     * writes these only where a screenshot check failed, so a run where nothing moved sends
     * nothing.
     *
     * Like the result itself this cannot fail the run: the images are already on disk next to the
     * result, and the report they belong to has already arrived.
     */
    private async sendArtifacts(runId: number): Promise<void> {
        if (this.config.mode !== 'compare') {
            return;
        }

        const artifacts = findArtifacts(join(this.paths.attempts, this.attemptId, 'artifacts'));

        if (artifacts.length === 0) {
            return;
        }

        let sent = 0;
        const failures: string[] = [];

        for (const artifact of artifacts) {
            try {
                const response = await postArtifact(
                    this.config.dashboardOrigin as string,
                    runId,
                    this.config.token,
                    artifact,
                    this.config.dashboardInsecureTls,
                );

                if (response.status === 200 || response.status === 201) {
                    sent++;
                } else {
                    failures.push(`${artifact.page}/${artifact.variant}: HTTP ${response.status}`);
                }
            } catch (error) {
                failures.push(`${artifact.page}/${artifact.variant}: ${error instanceof Error ? error.message : String(error)}`);
            }
        }

        process.stdout.write(`  sent ${sent} of ${artifacts.length} screenshot(s)\n`);

        if (failures.length > 0) {
            process.stdout.write(`  ⚠  ${failures.slice(0, 3).join('; ')}\n`);
        }
    }

    /**
     * Promotes a capture to a usable baseline, and only on a clean run.
     *
     * Comparing against a baseline whose own capture failed its assertions measures the wrong
     * thing while looking entirely normal, so the completion record is the gate: no record, no
     * comparison.
     */
    private settleCaptureRecord(overall: Outcome, pending: CaptureRecord | null): void {
        if (this.config.mode !== 'capture' || pending === null || !existsSync(this.paths.pendingCapture)) {
            return;
        }

        if (overall !== 'passed') {
            process.stdout.write('  Capture did not complete cleanly; no baseline was recorded.\n');

            return;
        }

        writeFileSync(this.paths.pendingCapture, `${JSON.stringify({ ...pending, completed_at: new Date().toISOString() }, null, 2)}\n`);
        renameSync(this.paths.pendingCapture, this.paths.capture);
    }

    /**
     * @return Record<string, string[]>
     */
    private readMasks(): Record<string, string[]> {
        if (!existsSync(this.paths.manifest)) {
            return {};
        }

        try {
            const manifest = JSON.parse(readFileSync(this.paths.manifest, 'utf8')) as { pages?: { id: string; mask?: string[] }[] };
            const masked: Record<string, string[]> = {};

            for (const page of manifest.pages ?? []) {
                if ((page.mask ?? []).length > 0) {
                    masked[page.id] = page.mask ?? [];
                }
            }

            return masked;
        } catch {
            return {};
        }
    }

    /**
     * Reads a sidecar the run writes alongside itself, recording rather than raising on damage.
     *
     * These files are written by shell, and an unparseable one used to throw out of `onEnd` and
     * take the entire report with it -- the comparison had already run, and its result was lost to
     * a quoting bug in an unrelated field. A run that cannot read its own evidence is
     * inconclusive, not absent.
     */
    private readJson<T>(path: string, errors: string[], label: string): T | null {
        if (!existsSync(path)) {
            return null;
        }

        try {
            return JSON.parse(readFileSync(path, 'utf8')) as T;
        } catch (error) {
            errors.push(`${label}: ${error instanceof Error ? error.message : String(error)}`);

            return null;
        }
    }

    /**
     * @return string[]
     */
    private readManifestWarnings(): string[] {
        if (!existsSync(this.paths.manifest)) {
            return [];
        }

        try {
            const manifest = JSON.parse(readFileSync(this.paths.manifest, 'utf8')) as { warnings?: string[] };

            return manifest.warnings ?? [];
        } catch {
            return [];
        }
    }

    /**
     * @return Record<string, number>
     */
    private readMaskedShares(): Record<string, number> {
        if (!existsSync(this.paths.masking)) {
            return {};
        }

        const shares: Record<string, number> = {};

        try {
            // One file per page, because Playwright's workers are separate processes and a single
            // shared file would record whichever worker happened to finish last.
            for (const entry of readdirSync(this.paths.masking)) {
                if (!entry.endsWith('.json')) {
                    continue;
                }

                const record = JSON.parse(readFileSync(join(this.paths.masking, entry), 'utf8')) as { share?: number };

                if (typeof record.share === 'number') {
                    shares[entry.replace(/\.json$/, '')] = Math.round(record.share * 1000) / 1000;
                }
            }
        } catch {
            return shares;
        }

        return shares;
    }

    private readRecord(path: string): CaptureRecord | null {
        if (!existsSync(path)) {
            return null;
        }

        try {
            return JSON.parse(readFileSync(path, 'utf8')) as CaptureRecord;
        } catch {
            return null;
        }
    }

    private classify(kind: CheckResult['kind'], result: TestResult, message: string): Outcome {
        if (result.status === 'passed') {
            return 'passed';
        }

        if (message.includes(INCONCLUSIVE) || result.status === 'timedOut' || result.status === 'skipped') {
            return 'inconclusive';
        }

        // A baseline that was never captured is missing evidence, not a visual change. Playwright
        // is configured never to create one during a comparison, so this is the state that proves
        // the run was compared against something real.
        if (/snapshot (doesn't|does not) exist/i.test(message)) {
            return 'inconclusive';
        }

        // Classification is positive: each outcome is claimed only when the message says so.
        // Defaulting to changes_detected meant an infrastructure error -- a worker that never ran a
        // test, a crashed page -- was reported as a detected visual change on a page nobody
        // photographed.
        const isSnapshotDiff = /toHaveScreenshot|toMatchSnapshot|Screenshot comparison failed|snapshot .*(does not match|doesn't match)/i.test(message);

        if (isSnapshotDiff) {
            // `changes_detected` only means something when there is a baseline to differ from. A
            // snapshot that fails while capturing one is a failure to produce evidence.
            return this.config.mode === 'compare' ? 'changes_detected' : 'failed';
        }

        // A page that will not render the same way twice is a definite, actionable problem with
        // the page or the manifest -- not an inability to check -- and it must stop the baseline.
        if (message.includes('UNSTABLE:') || /toBeVisible|toBeLessThan/.test(message)) {
            return 'failed';
        }

        return 'inconclusive';
    }
}
