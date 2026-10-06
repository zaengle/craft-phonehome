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
    // `job.workflow_sha` is the documented context; `github.job_workflow_sha` does not exist and
    // an earlier version of this file asserted it into place.
    expect(remediate).toContain('runner_ref=${{ inputs.runner_ref || job.workflow_sha }}');
    expect(remediate).not.toContain('job_workflow_sha');
    expect(remediate.match(/^\s+ref: \$\{\{ needs\.check\.outputs\.runner_ref \}\}$/gm)?.length ?? 0).toBe(2);
    expect(remediate.match(/^\s+runner_ref: \$\{\{ needs\.check\.outputs\.runner_ref \}\}$/gm)?.length ?? 0).toBe(2);
    expect(verification).toContain('${{ inputs.runner_ref || job.workflow_sha }}');
});

test('the comparison is told which package must have moved', () => {
    expect(job('verify')).toContain('expect_package: ${{ inputs.package }}');
    expect(job('verify')).toContain('expect_version: ${{ inputs.version }}');
});

test('a branch left by a failed run is picked up rather than declined', () => {
    expect(job('prepare')).toContain("if: needs.check.outputs.existing == '0'");
    expect(job('deploy')).toContain("needs.prepare.result == 'skipped'");
    expect(job('pull_request')).toContain("needs.check.outputs.has_pr == '0'");
});

test('a branch whose pull request already merged or closed is recreated rather than reused', () => {
    // The pilot hit this: the branch from a merged pull request was never deleted, the next dispatch
    // picked it up as a run to resume, and GitHub refused a pull request with no commits between it
    // and main. Only a branch with no finished pull request behind it is resumed.
    const check = job('check');

    expect(check).toContain("gh pr list --head \"${{ inputs.branch }}\" --state closed");
    expect(check).toContain('git push origin --delete "${{ inputs.branch }}"');
    expect(check).toContain('existing=0');
    // The open check still comes first, so a branch with an open pull request is left alone.
    expect(check.indexOf('--state open')).toBeLessThan(check.indexOf('--state closed'));
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

test('a dispatch with no environment skips the pair and still opens the pull request', () => {
    // Phone Home sends an empty origin for a site that cannot deploy. Nothing may then be captured
    // or compared, and the pull request job must not depend on either having run.
    // The input's own block, from its key to the next key at the same depth, so a `required: false`
    // on a later input cannot satisfy this.
    const verifyOrigin = remediate.match(/\n {6}verify_origin:\n((?: {8}.*\n)+)/)?.[1] ?? '';
    expect(verifyOrigin).toContain('required: false');
    expect(job('baseline')).toContain("if: needs.check.outputs.has_pr == '0' && inputs.verify_origin != ''");
    expect(job('pull_request').match(/\n {4}if: (.*)\n/)?.[1]).not.toContain('needs.baseline');
    expect(job('pull_request').match(/\n {4}if: (.*)\n/)?.[1]).not.toContain('needs.verify');
});

test('a re-run gets its own baseline identity', () => {
    expect(remediate).toContain('run_id=remediation-${{ inputs.patch_id }}-${{ github.run_id }}-${{ github.run_attempt }}');
});

test('a pushed branch gets its pull request even when Phone Home cannot be reached', () => {
    expect(job('pull_request')).toContain('site-token.sh || true');
    expect(job('pull_request')).toContain("if: steps.pr.outputs.url != '' && env.PHONEHOME_TOKEN != ''");
});

test('the nested verification workflow names a ref that exists', () => {
    // The plugin repository has no `main`; its default branch is `develop`, and this file is on a
    // feature branch until it is released.
    expect(remediate).not.toContain('site-verification.yml@main');
    expect(remediate.match(/site-verification\.yml@feature\/verification-manifest-poc/g)?.length ?? 0).toBe(2);
});

test('every third-party action is pinned to a full commit SHA', () => {
    // The organization requires it, and a tag can be moved under a workflow; a SHA cannot. The
    // repository's own reusable workflows are referenced by ref and are exempt from that policy.
    const files = ['remediate.yml', 'site-verification.yml', 'tests.yml'].map((name) => readFileSync(`${workflows}${name}`, 'utf8'));
    const examples = readFileSync(new URL('../../examples/deploy-staging.yml', import.meta.url).pathname, 'utf8');

    for (const text of [...files, examples]) {
        for (const match of text.matchAll(/^\s+(?:- )?uses: (\S+)/gm)) {
            const ref = match[1];
            if (ref.startsWith('zaengle/craft-phonehome/') || ref.startsWith('./') || ref.startsWith('docker://')) {
                continue;
            }
            expect(ref, `${ref} is not pinned to a commit SHA`).toMatch(/@[0-9a-f]{40}$/);
        }
    }
});

test('a comparison waits for the environment to be running the commit, whatever started it', () => {
    // On the pilot the pair ran on the push to main and reported before the host had deployed it.
    // The wait lives in the reusable workflow, so no caller can start a comparison without it.
    expect(verification).toMatch(/if: inputs\.mode == 'compare'\n\s+uses: actions\/checkout@[0-9a-f]{40}.*\n\s+with:\n\s+ref: \$\{\{ inputs\.lock_ref \|\| github\.sha \}\}/);
    expect(verification).toContain("PHV_EXPECT_LOCK: ${{ inputs.mode == 'compare' && format('../../../site/{0}', inputs.lock_path) || '' }}");
    expect(verification).toContain('PHV_DEPLOY_TIMEOUT: ${{ inputs.deploy_timeout }}');
    // The remediation's own comparison is of the branch, not of the commit the dispatch ran on.
    expect(job('verify')).toContain('lock_ref: ${{ inputs.branch }}');
});

test('a re-run updates the open pull request rather than failing to open a second', () => {
    const pullRequest = job('pull_request');

    expect(pullRequest).toContain('bash runner/tools/site-verification/ci/open-pr.sh');
    expect(pullRequest).not.toContain('gh pr create');
    // Phone Home is told the URL the script published, whichever of the two it was.
    expect(pullRequest).toContain('"pull_request_url\\":\\"${{ steps.pr.outputs.url }}');
});

test('a re-run of a run redoes the verification even though its pull request is open', () => {
    // A new dispatch still leaves an open pull request alone. Without the attempt check, "Re-run all
    // jobs" found the pull request the first attempt opened and skipped every job.
    const check = job('check');

    expect(check).toContain('if [ "${open:-0}" -gt 0 ] && [ "${{ github.run_attempt }}" = "1" ]; then');
    expect(check.indexOf('github.run_attempt }}" = "1"')).toBeLessThan(check.indexOf('--state closed'));
});
