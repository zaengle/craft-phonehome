#!/usr/bin/env node
/**
 * Turns a run's result.json into a job summary and workflow outputs.
 *
 * Called by .github/workflows/site-verification.yml after the runner finishes. Lives here rather
 * than inline in the workflow so it can be run and tested without a GitHub runner: the inline
 * version shipped with a bug (`require()` of a relative path) that only a real job would have hit.
 *
 *   node ci/summarise.mjs runs/<host>/<run>/result.json
 *
 * Writes the summary to $GITHUB_STEP_SUMMARY and `overall`, `summary` and `environment` to
 * $GITHUB_OUTPUT when those are set, and prints the summary to stdout either way.
 */
import { appendFileSync, existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';

/** @param {unknown} delta */
export function describeEnvironment(delta) {
    if (!delta || typeof delta !== 'object') {
        return '';
    }

    const { known, changed } = /** @type {{ known?: boolean; changed?: { name: string; before: string | null; after: string | null }[] }} */ (delta);

    if (!known) {
        return 'Environment: unknown, the baseline predates package snapshots.';
    }

    const moved = (changed ?? []).map((entry) => `${entry.name} ${entry.before ?? 'absent'} → ${entry.after ?? 'removed'}`);

    if (moved.length === 0) {
        return 'Environment: the site reports the same Craft and plugin versions as at baseline.';
    }

    return `Environment: ${moved.slice(0, 8).join(', ')}${moved.length > 8 ? ', …' : ''}.`;
}

/**
 * @param {Record<string, any>} report
 * @return {{ overall: string; summary: string; environment: string; markdown: string }}
 */
export function summarise(report, unstoredBaseline = null) {
    const checks = Array.isArray(report.checks) ? report.checks : [];
    const failing = checks.filter((check) => check.outcome !== 'passed');
    // A capture whose baseline could not be stored is not a usable reference, whatever its checks
    // found, so it is published as inconclusive and the reason leads the summary.
    const overall = unstoredBaseline !== null ? 'inconclusive' : typeof report.overall === 'string' ? report.overall : '';
    const environment = describeEnvironment(report.environment_delta);

    const named = failing
        .slice(0, 6)
        .map((check) => `${check.kind}:${check.id} ${check.outcome}`)
        .join(', ');
    let summary =
        failing.length === 0
            ? `${checks.length} checks passed.`
            : `${failing.length} of ${checks.length} checks did not pass: ${named}${failing.length > 6 ? ', …' : ''}.`;

    const lines = [`## Verification: ${overall.toUpperCase() || 'UNKNOWN'}`, ''];

    if (unstoredBaseline !== null) {
        summary = `The baseline could not be stored on Phone Home (${unstoredBaseline}), so nothing can be compared against this capture. ${summary}`;
        lines.push(`**The baseline could not be stored on Phone Home:** ${unstoredBaseline}. Nothing can be compared against this capture.`, '');
    }
    const missing = Array.isArray(report.missing_checks) ? report.missing_checks : [];
    const warnings = Array.isArray(report.manifest_warnings) ? report.manifest_warnings : [];

    if (missing.length > 0) {
        lines.push(`**${missing.length} check(s) the manifest owed never ran**, so this result is not the run that was asked for: ${missing.join(', ')}`, '');
    }

    if (warnings.length > 0) {
        lines.push(`**The site reported a narrowed manifest:** ${warnings.join(' ')}`, '');
    }

    if (environment !== '') {
        lines.push(`**${environment}**`, '');
    }

    const rows = failing.map((check) => `| ${check.id} | ${check.kind} | ${check.outcome} | ${String(check.diagnostic ?? '').slice(0, 160)} |`);

    lines.push(rows.length > 0 ? ['| Page | Check | Outcome | Diagnostic |', '|---|---|---|---|', ...rows].join('\n') : 'Every check passed.');

    return { overall, summary, environment, markdown: `${lines.join('\n')}\n` };
}

/** Everything a workflow output must not contain, folded to a space. */
const oneLine = (value) => String(value).replace(/[\r\n]+/g, ' ');

function main() {
    const path = process.argv[2] ?? '';
    const stepSummary = process.env.GITHUB_STEP_SUMMARY;
    const outputs = process.env.GITHUB_OUTPUT;

    if (path === '' || !existsSync(resolve(path))) {
        const markdown = 'The run produced no result file, which means it did not get far enough to report.\n';

        process.stdout.write(markdown);
        if (stepSummary) appendFileSync(stepSummary, markdown);
        if (outputs) appendFileSync(outputs, 'overall=\nsummary=The run produced no result, so nothing was verified.\nenvironment=\n');

        return;
    }

    const report = JSON.parse(readFileSync(resolve(path), 'utf8'));
    const unstored = join(dirname(resolve(path)), 'baseline-unstored.txt');
    const result = summarise(report, existsSync(unstored) ? readFileSync(unstored, 'utf8').trim() : null);

    process.stdout.write(result.markdown);
    if (stepSummary) appendFileSync(stepSummary, result.markdown);
    if (outputs) appendFileSync(outputs, `overall=${oneLine(result.overall)}\nsummary=${oneLine(result.summary)}\nenvironment=${oneLine(result.environment)}\n`);
}

if (process.argv[1] && resolve(process.argv[1]) === new URL(import.meta.url).pathname) {
    main();
}
