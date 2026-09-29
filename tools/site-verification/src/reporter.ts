import type { FullResult, Reporter, TestCase, TestResult } from '@playwright/test/reporter';
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { runConfig } from './config';
import { bundlePaths, type CaptureRecord } from './manifest';

/**
 * Outcomes are reported as four distinct states rather than pass/fail.
 *
 * `changes_detected` needs a person to interpret it and does not by itself mean the site is broken;
 * `inconclusive` means the run could not establish anything either way. Collapsing either into
 * "failed" would train an operator to ignore the report, and collapsing either into "passed" would
 * make the report actively misleading.
 */
type Outcome = 'passed' | 'changes_detected' | 'failed' | 'inconclusive';

const PRECEDENCE: Outcome[] = ['failed', 'inconclusive', 'changes_detected', 'passed'];

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
        const pending = this.readRecord(this.paths.pendingCapture) ?? this.readRecord(this.paths.capture);
        const expected = pending?.expected_checks ?? [];
        const ran = this.checks.map((check) => `${check.kind}:${check.id}`);
        const missing = expected.filter((id) => !ran.includes(id));

        const worst = PRECEDENCE.find((candidate) => this.checks.some((check) => check.outcome === candidate)) ?? 'inconclusive';

        // A report that summarises only the checks that happened to run will call a filtered or
        // partially-collected run a pass, having never executed the assertion that would have
        // caught the defect. Anything the manifest owed and did not deliver is missing evidence.
        const incomplete = this.checks.length === 0 || missing.length > 0 || result.status === 'interrupted' || result.status === 'timedout';

        // An incomplete run downgrades a pass, never an observed failure. A check that ran and
        // failed is evidence; burying it because some other check never ran loses the one result
        // the operator most needs.
        const overall: Outcome = worst === 'failed' ? 'failed' : incomplete ? 'inconclusive' : worst;

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
            // Recorded, not acted on: the run used the frozen definitions either way, and an
            // operator reading a clean result needs to know the site has since been redefined.
            // What the run was verified across, when the loop applied it. A comparison whose
            // change never landed is a pass about nothing, so it is recorded next to the verdict.
            // What the run chose not to look at. A clean result is only as meaningful as the
            // coverage behind it, and a mask is coverage deliberately given up.
            masks: this.readMasks(),
            change: existsSync(this.paths.change) ? (JSON.parse(readFileSync(this.paths.change, 'utf8')) as unknown) : null,
            manifest_drift: existsSync(this.paths.drift) ? (JSON.parse(readFileSync(this.paths.drift, 'utf8')) as string[]) : [],
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
