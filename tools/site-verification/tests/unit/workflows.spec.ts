import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

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

test('both halves of the verification build the same run id, new for every attempt', () => {
    // Built in each job rather than passed out of `check`: "Re-run failed jobs" does not repeat a
    // job that succeeded, so an id minted there kept the first attempt's number on the pilot's
    // re-run. Every job in one attempt shares `github.run_attempt`, so the halves still agree.
    const passed = [...remediate.matchAll(/^ {6}run_id: (.*)$/gm)].map((match) => match[1]);

    expect(passed).toEqual([
        'remediation-${{ inputs.patch_id }}-${{ github.run_id }}-${{ github.run_attempt }}',
        'remediation-${{ inputs.patch_id }}-${{ github.run_id }}-${{ github.run_attempt }}',
    ]);
    expect(job('baseline')).toContain(passed[0]);
    expect(job('verify')).toContain(passed[1]);
    expect(remediate).not.toContain('outputs.run_id');
});

test('the comparison only runs against a baseline whose capture passed', () => {
    expect(verifyCondition).toContain("needs.baseline.outputs.overall == 'passed'");
});

test('the comparison only runs after the deploy succeeded', () => {
    expect(verifyCondition).toContain("needs.deploy.result == 'success'");
    expect(verifyCondition).toContain("needs.deploy.outputs.deployed == '1'");
});

test('the deploy requires a sealed baseline stored on Phone Home', () => {
    expect(job('deploy')).toContain("needs.baseline.outputs.overall == 'passed'");
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
    expect(remediate).toContain('RUNNER_REF: ${{ inputs.runner_ref || job.workflow_sha }}');
    expect(remediate).toContain('runner_ref=$RUNNER_REF');
    expect(remediate).not.toContain('job_workflow_sha');
    // Every checkout of the runner (prepare, deploy and pull_request) takes the pinned commit.
    expect(remediate.match(/^\s+repository: zaengle\/craft-phonehome$/gm)?.length ?? 0).toBe(3);
    expect(remediate.match(/^\s+repository: zaengle\/craft-phonehome\n\s+ref: \$\{\{ needs\.check\.outputs\.runner_ref \}\}$/gm)?.length ?? 0).toBe(3);
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

    expect(check).toContain('gh pr list --head "$BRANCH" --state closed');
    expect(check).toContain('git push origin --delete "$BRANCH"');
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

test('a pushed branch gets its pull request even when Phone Home cannot be reached', () => {
    expect(job('pull_request')).toContain('site-token.sh || true');
    expect(job('pull_request')).toContain("if: steps.pr.outputs.url != '' && env.PHONEHOME_TOKEN != ''");
});

test('the nested verification workflow is taken from the remediation workflow commit', () => {
    expect(remediate.match(/uses: \.\/\.github\/workflows\/site-verification\.yml/g)?.length ?? 0).toBe(2);
    expect(remediate).not.toMatch(/site-verification\.yml@/);
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
    expect(pullRequest).toContain('PR_URL: ${{ steps.pr.outputs.url }}');
    expect(pullRequest).toContain('{pull_request_url: $url, site_id: $site_id}');
});

test('a re-run of a run redoes the verification even though its pull request is open', () => {
    // A new dispatch still leaves an open pull request alone. Without the attempt check, "Re-run all
    // jobs" found the pull request the first attempt opened and skipped every job.
    const check = job('check');

    expect(check).toContain('if [ "${open:-0}" -gt 0 ] && [ "$RUN_ATTEMPT" = "1" ]; then');
    expect(check.indexOf('$RUN_ATTEMPT" = "1"')).toBeLessThan(check.indexOf('--state closed'));
});

test('caller input is passed through the environment instead of inserted into shell source', () => {
    for (const workflow of [remediate, verification]) {
        const lines = workflow.split('\n');
        for (let index = 0; index < lines.length; index++) {
            const run = lines[index].match(/^ {8}run: (.*)$/);
            if (!run) continue;
            let script = run[1];
            if (script === '|') {
                while (index + 1 < lines.length && (/^ {10}/.test(lines[index + 1]) || lines[index + 1] === '')) {
                    script += `\n${lines[++index]}`;
                }
            }
            expect(script).not.toContain('${{');
        }
    }
});

test('the example callers pin the shared workflows to one release tag, never a branch', () => {
    // The pilot's callers named the feature branch, which has since been released and deleted. A
    // site copies these files, so what they reference is what a new site runs.
    const examples = ['remediate.yml', 'verify-on-deploy.yml'].map((name) => readFileSync(new URL(`../../examples/${name}`, import.meta.url).pathname, 'utf8'));
    const refs = examples.flatMap((text) => [...text.matchAll(/uses: zaengle\/craft-phonehome\/\.github\/workflows\/[a-z-]+\.yml@(\S+)/g)].map((match) => match[1]));

    expect(refs).toHaveLength(3);
    expect(new Set(refs)).toEqual(new Set(['1.8.3']));
});

test('every step that touches composer.json or composer.lock runs inside working_directory', () => {
    // A site that keeps its Craft application in `src/` has no Composer files at the repository
    // root, so any step left at the root would fail there or write a lock file nobody deploys.
    const steps = job('prepare').split(/\n {6}- /).slice(1);
    const touching = steps.filter((step) => /composer|resolve-change\.sh/.test(step) && !step.startsWith('uses: shivammathur'));

    expect(touching.map((step) => step.match(/^name: (.*)/)?.[1])).toEqual(['Resolve the change', 'Push the branch']);

    for (const step of touching) {
        expect(step).toContain('working-directory: ${{ inputs.working_directory }}');
    }

    expect(job('prepare')).toContain('bash "$GITHUB_WORKSPACE/.phonehome-runner/tools/site-verification/ci/resolve-change.sh"');
    expect(job('prepare')).toContain('git add composer.lock composer.json');
    expect(remediate).toMatch(/\n {6}working_directory:\n(?: {8}.*\n)*? {8}default: '\.'\n/);
    // The comparison waits for the lock file in the same directory.
    expect(job('verify')).toContain('lock_path: ${{ needs.check.outputs.lock_path }}');
});

test('the lock file path is spelled so the comparison checkout can match it', () => {
    // Taken from the workflow and run, because `./composer.lock` or `src//composer.lock` match no
    // file as a sparse-checkout pattern and the comparison would wait on a lock file it never read.
    const script = job('check').match(/- name: Locate the lock file\n(?:.*\n)*? {8}run: \|\n((?: {10}.*\n)+)/)?.[1].replace(/^ {10}/gm, '') ?? '';

    expect(script).not.toBe('');

    const lockPath = (dir: string) => {
        const outputs = join(mkdtempSync(join(tmpdir(), 'phv-lock-')), 'outputs');
        writeFileSync(outputs, '');
        spawnSync('bash', ['-c', script], { env: { PATH: process.env.PATH ?? '', GITHUB_OUTPUT: outputs, WORKING_DIRECTORY: dir } });

        return readFileSync(outputs, 'utf8');
    };

    for (const dir of ['.', './', '']) {
        expect(lockPath(dir)).toBe('lock_path=composer.lock\n');
    }

    for (const dir of ['src', 'src/', './src']) {
        expect(lockPath(dir)).toBe('lock_path=src/composer.lock\n');
    }
});

test('how far the Composer update reached is carried into the pull request body', () => {
    expect(job('prepare')).toContain('scope: ${{ steps.resolve.outputs.scope }}');
    expect(job('prepare')).toContain('scope_reason: ${{ steps.resolve.outputs.scope_reason }}');
    expect(job('pull_request')).toContain('SCOPE: ${{ needs.prepare.outputs.scope }}');
    expect(job('pull_request')).toContain('SCOPE_REASON: ${{ needs.prepare.outputs.scope_reason }}');
});

test('a branch whose update has to be applied locally is not deployed', () => {
    // Its new migrations write project config only the local run produces, so a deploy would leave
    // the environment in a state the eventual commit does not describe.
    expect(job('deploy').match(/\n {4}if: (.*)\n/)?.[1]).toContain("&& needs.prepare.outputs.apply_locally == ''");
});

test('what the schema check could not do is carried into the pull request body', () => {
    expect(job('prepare')).toContain('schema_unknown: ${{ steps.resolve.outputs.schema_unknown }}');
    expect(job('prepare')).toContain('schema_error: ${{ steps.resolve.outputs.schema_error }}');
    expect(job('pull_request')).toContain('SCHEMA_UNKNOWN: ${{ needs.prepare.outputs.schema_unknown }}');
    expect(job('pull_request')).toContain('SCHEMA_ERROR: ${{ needs.prepare.outputs.schema_error }}');
});

test('the pull request step can link the patch even when Phone Home could not be reached', () => {
    expect(job('pull_request')).toContain('DASHBOARD_ORIGIN: ${{ inputs.dashboard_origin }}');
    expect(remediate).not.toMatch(/patch #\$/);
});

test('the comparison is told the commit it waits for, and says how the deploy was confirmed', () => {
    expect(verification).toContain('PHV_EXPECT_COMMIT: ${{ steps.commit.outputs.sha }}');
    expect(verification).toContain('CHECKED_OUT: ${{ steps.lock.outputs.commit }}');

    for (const output of ['deploy_confirmed_by', 'deploy_confirmation']) {
        expect(verification).toContain(`value: \${{ jobs.verify.outputs.${output} }}`);
        expect(verification).toContain(`${output}: \${{ steps.summary.outputs.${output} }}`);
    }

    expect(job('pull_request')).toContain('DEPLOY_CONFIRMED_BY: ${{ needs.verify.outputs.deploy_confirmed_by }}');
    expect(job('pull_request')).toContain('DEPLOY_CONFIRMATION: ${{ needs.verify.outputs.deploy_confirmation }}');
    // A deploy workflow that failed is still a failed deploy, whatever the environment reports.
    expect(job('verify')).toContain("needs.deploy.result == 'success'");
});

test('the Playwright image matches the Playwright the runner installs', () => {
    // The runner launches the browser the container image ships, so the image and package.json
    // must name the same Playwright release. Dependabot bumps package.json alone; CI never
    // launches a browser, so only this test notices when the image is left behind.
    const pkg = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url).pathname, 'utf8')) as { devDependencies: Record<string, string> };
    const version = pkg.devDependencies['@playwright/test'];
    const compose = readFileSync(new URL('../../ddev/docker-compose.playwright.yaml', import.meta.url).pathname, 'utf8');

    expect(version).toMatch(/^\d+\.\d+\.\d+$/);

    for (const text of [verification, compose]) {
        const images = [...text.matchAll(/mcr\.microsoft\.com\/playwright:v([\d.]+)-/g)].map((match) => match[1]);

        expect(images.length).toBeGreaterThan(0);
        expect(new Set(images)).toEqual(new Set([version]));
    }
});

test('the example pair mints a run id Phone Home can link, unique per run', () => {
    // Phone Home reads the commit from the start of the run id; the GitHub run id keeps a push and a
    // run started by hand on the same commit apart. The first onboarded site hit both problems.
    const example = readFileSync(new URL('../../examples/verify-on-deploy.yml', import.meta.url).pathname, 'utf8');

    expect(example).toContain('run_id: ${{ github.sha }}-${{ github.run_id }}-${{ github.run_attempt }}');
    expect(example).toMatch(/run_id: \$\{\{ github\.sha \}\}-/);
    expect(example).toContain('${{ github.event.deployment.sha }}-deploy-${{ github.event.deployment.id }}');
    expect(example).not.toMatch(/`deploy-\$\{\{ github\.event\.deployment\.id \}\}`/);
});

test('the remediation title and commit message keep the format sites rely on', () => {
    // Sites recognise a remediation merge from these strings and read the patch, package and version
    // from them. A change here must be deliberate and called out as breaking, not discovered by a site.
    expect(job('pull_request')).toContain("TITLE: 'Security: ${{ inputs.package }} to ${{ inputs.version }}'");
    expect(job('prepare')).toContain('git commit -m "Security: $PACKAGE to $VERSION (Phone Home patch $PATCH_ID)"');
    expect(readFileSync(new URL('../../README.md', import.meta.url).pathname, 'utf8')).toContain('`security/patch-<patch>-site-<site>`');
});
