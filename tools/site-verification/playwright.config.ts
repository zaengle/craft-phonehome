import { defineConfig } from '@playwright/test';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { runConfig } from './src/config';
import {
    bundleObjections,
    bundlePaths,
    describeDrift,
    expectedChecks,
    fetchReport,
    isAbort,
    readCaptureRecord,
    readFrozenManifest,
    readIdentity,
    renderEnvironment,
    RUNNER_CONTRACT,
    writeBundle,
    type Abort,
    type CaptureRecord,
} from './src/manifest';

/**
 * The manifest is resolved here, at config load, rather than in a globalSetup hook, because the
 * spec generates one test per page and therefore needs the frozen manifest before Playwright
 * collects tests.
 */
const config = runConfig();
const paths = bundlePaths(config.bundleDir);
const VIEWPORT = { width: 1440, height: 900 };

// Playwright evaluates this config file again in every worker, so each of these side effects would
// otherwise run twice: two API fetches, and a second rewrite of the frozen manifest from a later
// response. What landed on disk came from whichever process finished last, and a difference
// between the two fetches surfaced as page checks that never ran being reported as failures.
const isPrimary = process.env.PHV_BUNDLE_OWNER === undefined;

if (isPrimary) {
    process.env.PHV_BUNDLE_OWNER = String(process.pid);

    mkdirSync(config.bundleDir, { recursive: true });
    await prepareBundle();
}

/**
 * Resolves the manifest and settles the bundle's state. Runs in the first process only; every later
 * evaluation reads what this left behind.
 */
async function prepareBundle(): Promise<void> {
    // Lays down a failed result before any work starts. Everything below can throw while Playwright
    // is still loading this file, which is before any reporter exists. Without this the bundle keeps
    // the previous run's report, so a run that died during startup leaves a stale PASSED behind it.
    writeFileSync(
        paths.result,
        `${JSON.stringify(
            {
                run_id: config.runId,
                mode: config.mode,
                origin: config.origin,
                finished_at: null,
                overall: 'inconclusive',
                note: 'The run did not get far enough to report. If this is the final state, it failed during startup.',
                checks: [],
            },
            null,
            2,
        )}\n`,
    );

    // A stale gate file from an earlier attempt would otherwise be read as this run's outcome.
    rmSync(paths.abort, { force: true });

    if (config.mode === 'capture') {
        await prepareCapture();

        return;
    }

    await prepareCompare();
}

async function prepareCapture(): Promise<void> {
    const existing = readCaptureRecord(config.bundleDir);

    if (existing !== null && !config.replace) {
        // A completed baseline is evidence. Silently overwriting one means a comparison that found
        // a regression can be made to pass by re-running capture, and nothing records that the
        // reference moved.
        abort('bundle_exists', [
            `${config.runId} already holds a capture completed at ${existing.completed_at}.`,
            'Use a new run id, or set PHV_REPLACE=1 to deliberately replace it.',
        ]);

        return;
    }

    rmSync(paths.capture, { force: true });
    rmSync(paths.manifest, { force: true });
    rmSync(paths.drift, { force: true });

    const report = await fetchReport(config.apiOrigin, config.token, config.insecureTls);

    if (isAbort(report)) {
        writeBundle(config.bundleDir, report);

        return;
    }

    writeBundle(config.bundleDir, report.manifest);
    process.stdout.write(`Froze ${report.manifest.pages.length} page(s) into ${config.bundleDir}\n`);

    // Promoted by the reporter, and only when every expected check passed. Its presence is what
    // makes a bundle usable; its absence means capture never finished.
    const pending: CaptureRecord = {
        run_id: config.runId,
        completed_at: '',
        runner_contract: RUNNER_CONTRACT,
        site: { ...readIdentity(report.payload), origin: config.origin, api_origin: config.apiOrigin },
        settings: { full_page: config.fullPage, viewport: VIEWPORT, stability_samples: config.stabilitySamples },
        environment: renderEnvironment(),
        expected_checks: expectedChecks(report.manifest),
    };

    writeFileSync(paths.pendingCapture, `${JSON.stringify(pending, null, 2)}\n`);
}

async function prepareCompare(): Promise<void> {
    const record = readCaptureRecord(config.bundleDir);

    if (record === null) {
        abort('no_baseline', [
            `${config.bundleDir} holds no completed capture.`,
            'Either none was taken, or the capture that was taken did not finish successfully.',
        ]);

        return;
    }

    const objections = bundleObjections(record, config.origin, config.apiOrigin, config.fullPage, renderEnvironment());

    if (objections.length > 0) {
        abort('bundle_mismatch', objections);

        return;
    }

    // Confirm the site is actually answering before any page result is believed. A host that has
    // gone away can still serve a router's 404 for every path, which reads as a page-by-page
    // failure when it is really an inability to verify anything at all.
    const current = await fetchReport(config.apiOrigin, config.token, config.insecureTls);

    if (isAbort(current)) {
        writeBundle(config.bundleDir, current);

        return;
    }

    const drift = describeDrift(readFrozenManifest(config.bundleDir), current.manifest);

    writeFileSync(paths.drift, `${JSON.stringify(drift, null, 2)}\n`);

    if (drift.length > 0) {
        process.stdout.write(`Manifest drift since capture:\n  ${drift.join('\n  ')}\n`);
    }
}

function abort(reason: string, detail: string[]): void {
    writeBundle(config.bundleDir, { reason, detail } satisfies Abort);
}

export default defineConfig({
    testDir: './tests',
    // A visual check that is retried until it passes is not evidence, so a run gets one attempt.
    retries: 0,
    fullyParallel: false,
    workers: 1,
    // Comparison must never invent a baseline. `none` fails on a missing snapshot even outside CI,
    // where Playwright would otherwise write one and report the first run as a pass.
    updateSnapshots: config.mode === 'capture' ? 'all' : 'none',
    snapshotPathTemplate: `${paths.snapshots}/{arg}{ext}`,
    // Diff images go beside the attempt that produced them. Playwright's default directory is
    // shared and cleared on the next run, which erases the evidence for the failure just reported.
    outputDir: `${paths.attempts}/${config.attemptId}/artifacts`,
    preserveOutput: 'always',
    reporter: [['list'], ['./src/reporter.ts']],
    timeout: 60_000,
    expect: {
        timeout: 10_000,
        toHaveScreenshot: {
            animations: 'disabled',
            caret: 'hide',
            scale: 'css',
            // An absolute budget, not a ratio. 0.2% of a 1440x900 viewport is 2592 pixels, which is
            // enough to delete a whole sentence from a footer and still pass -- measured, not
            // theorised. Tens of pixels covers antialiasing without covering content.
            maxDiffPixels: 60,
            // Playwright's default per-pixel threshold of 0.2 treats a colour shift as "the same
            // pixel". Changing every glyph on the page from #22303c to #3a4a5a passed at the
            // default; at 0.05 it does not.
            threshold: 0.05,
        },
    },
    use: {
        baseURL: config.origin,
        // Everything below is held fixed so that a difference between two runs is a difference in
        // the site, not in the environment that photographed it.
        viewport: VIEWPORT,
        deviceScaleFactor: 1,
        locale: 'en-US',
        timezoneId: 'UTC',
        colorScheme: 'light',
        reducedMotion: 'reduce',
        // Scoped to the flag that documents it. Left unconditionally true, the browser would
        // accept any certificate while the README claimed the leniency covered one API request.
        ignoreHTTPSErrors: config.insecureTls,
        screenshot: 'off',
        trace: 'off',
    },
    projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
});

