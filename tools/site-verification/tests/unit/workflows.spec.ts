import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';

/**
 * The invariants the remediation workflow is built on, read off the file rather than trusted to
 * a comment. Each one is a way the pipeline could quietly start proving less than it says: the
 * two halves of the verification naming different bundles, a comparison running against a
 * baseline that never sealed, or a comparison of an environment the change never reached.
 */
const workflows = new URL('../../../../.github/workflows/', import.meta.url).pathname;
const remediate = readFileSync(`${workflows}remediate.yml`, 'utf8');
const verification = readFileSync(`${workflows}site-verification.yml`, 'utf8');

/** The text of one top-level job, from its key to the next job's key. */
function job(name: string): string {
    const start = remediate.indexOf(`\n  ${name}:\n`);
    const rest = remediate.slice(start + 1);
    const next = rest.slice(1).search(/\n {2}[a-z_]+:\n/);

    return next === -1 ? rest : rest.slice(0, next + 1);
}

/** The `verify` job's `if:` line. */
const verifyCondition = job('verify').match(/\n {4}if: (.*)\n/)?.[1] ?? '';

test('both halves of the verification name the bundle from one place', () => {
    // The value handed to each half of the verification, as opposed to the `check` job's own
    // output line that publishes it.
    const passed = [...remediate.matchAll(/^ {6}run_id: (.*)$/gm)].map((match) => match[1]).filter((value) => value.includes('needs.'));

    expect(passed).toEqual(['${{ needs.check.outputs.run_id }}', '${{ needs.check.outputs.run_id }}']);
    expect(remediate.match(/run_id=remediation-/g)).toHaveLength(1);
    expect(remediate).not.toMatch(/run_id: remediation-/);
});

test('the comparison only runs against a baseline whose capture passed', () => {
    expect(verifyCondition).toContain("needs.baseline.outputs.overall == 'passed'");
});

test('the comparison only runs after the deploy succeeded', () => {
    expect(verifyCondition).toContain("needs.deploy.result == 'success'");
});

test('the comparison expects the site to have changed', () => {
    expect(job('verify')).toContain('expect_change: true');
});

test('the pull request is opened whatever the verification concluded, as long as a branch was pushed', () => {
    const condition = job('pull_request').match(/\n {4}if: (.*)\n/)?.[1] ?? '';

    expect(condition).toContain('always()');
    expect(condition).toContain("needs.prepare.result == 'success'");
    expect(remediate).not.toContain('continue-on-error');
});

test('the runner follows the workflow file it was released with', () => {
    expect(remediate.match(/github\.job_workflow_sha/g)?.length ?? 0).toBeGreaterThanOrEqual(3);
    expect(verification).toContain('${{ inputs.runner_ref || github.job_workflow_sha }}');
});

test('the wrapper publishes the outcome for callers rather than leaving them a job status', () => {
    for (const output of ['overall', 'summary', 'environment']) {
        expect(verification).toContain(`value: \${{ jobs.verify.outputs.${output} }}`);
        expect(verification).toContain(`${output}: \${{ steps.summary.outputs.${output} }}`);
    }
});

test('a run can prove its identity in place of a secret', () => {
    expect(remediate).toContain('id-token: write');
    expect(verification).toContain('id-token: write');
    expect(verification).toMatch(/phonehome_token:\n(?:.*\n)*? {8}required: false/);
});
