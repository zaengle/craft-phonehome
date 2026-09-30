import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * The two scripts the workflows run to say what happened. They compose the sentences a reviewer
 * reads in a pull request and the outputs a calling workflow branches on, so each state they
 * distinguish is pinned here rather than discovered on a real run.
 */
const ci = new URL('../../ci', import.meta.url).pathname;

function prBody(env: Record<string, string>): string {
    const run = spawnSync('bash', [join(ci, 'pr-body.sh')], {
        env: { PATH: process.env.PATH ?? '', ...env },
        encoding: 'utf8',
    });

    expect(run.status, run.stderr).toBe(0);

    return run.stdout;
}

const verified = {
    PATCH_ID: '7',
    ORIGIN: 'https://staging.example',
    MOVED: 'craftcms/cms 5.8.14 -> 5.8.15',
    COUNT: '1',
    BASELINE: 'passed',
    BASELINE_SUMMARY: '9 checks passed.',
    DEPLOYED: '1',
    DEPLOY_JOB: 'success',
    DEPLOY_RUN_URL: 'https://github.com/z/x/actions/runs/9',
    VERIFY_JOB: 'failure',
    VERIFICATION: 'changes_detected',
    VERIFICATION_SUMMARY: '1 of 9 checks did not pass: screenshot:home changes_detected.',
    ENVIRONMENT: 'Environment: craftcms/cms 5.8.14 → 5.8.15.',
};

test.describe('pr-body.sh', () => {
    test('quotes the verdict the runner reported, not the job status', () => {
        const body = prBody(verified);

        expect(body).toContain('**Verification** — `changes_detected` against https://staging.example. 1 of 9 checks did not pass');
        expect(body).toContain('Deployed to https://staging.example by https://github.com/z/x/actions/runs/9.');
        expect(body).toContain('**Environment: craftcms/cms 5.8.14 → 5.8.15.**');
        expect(body).not.toContain('[!WARNING]');
    });

    test('a baseline that did not pass means nothing was compared', () => {
        const body = prBody({ ...verified, BASELINE: 'failed', BASELINE_SUMMARY: '2 of 9 checks did not pass: assert:home failed.', VERIFY_JOB: 'skipped', VERIFICATION: '' });

        expect(body).toContain('`not run`. The baseline of https://staging.example could not be captured, so nothing was compared. 2 of 9 checks did not pass');
    });

    test('a comparison that never produced a result is not a pass', () => {
        const body = prBody({ ...verified, VERIFY_JOB: 'skipped', VERIFICATION: '', VERIFICATION_SUMMARY: '' });

        expect(body).toContain('`not run`. The baseline was captured but the comparison against https://staging.example never produced a result.');
    });

    test('an undeployed environment is stated as saying nothing about the change', () => {
        const body = prBody({ ...verified, DEPLOYED: '0', DEPLOY_JOB: 'success', DEPLOY_RUN_URL: '' });

        expect(body).toContain('https://staging.example was not deployed with this branch');
        expect(body).toContain('It says nothing about this change.');
    });

    test('a failed deploy is told apart from no deploy', () => {
        const body = prBody({ ...verified, DEPLOYED: '0', DEPLOY_JOB: 'failure' });

        expect(body).toContain('The deploy to https://staging.example failed (https://github.com/z/x/actions/runs/9), so nothing was compared.');
    });

    test('a large move is labelled a dependency bump', () => {
        const body = prBody({ ...verified, COUNT: '54' });

        expect(body).toContain('This moves 54 packages. That is a dependency bump');
    });

    test('the assessment behind the patch is quoted when Phone Home answered', () => {
        const context = JSON.stringify({
            patch: { title: 'Craft CMS 5.8.15 security release', url: 'https://ph.example/patches/7', severity: 'high', severity_rationale: 'Auth bypass.\nEvery site.', assessed_by: 'Ada' },
            reviews: [{ name: 'Grace' }],
            releases: [{ craft_handle: 'cms', version: '5.8.15', ghsa_id: 'GHSA-xxxx' }],
            dispatched_by: 'Jesse',
        });

        const body = prBody({ ...verified, CONTEXT: context });

        expect(body).toContain('**Why** — [Craft CMS 5.8.15 security release](https://ph.example/patches/7), severity high.');
        expect(body).toContain('Fixes cms 5.8.15 (GHSA-xxxx).');
        expect(body).toContain('> Auth bypass.\n> Every site.');
        expect(body).toContain('Assessed by Ada; signed off by Grace; dispatched by Jesse.');
    });

    test('an unreachable dashboard is said so rather than omitted', () => {
        const body = prBody({ ...verified, CONTEXT: '' });

        expect(body).toContain('Phone Home could not be reached for the assessment behind patch #7');
    });

    test('a command with quotes in what moved does not break the body', () => {
        const body = prBody({ ...verified, MOVED: 'vendor/pkg "^2" -> 2.0.0' });

        expect(body).toContain('vendor/pkg "^2" -> 2.0.0');
    });
});

test.describe('site-token.sh', () => {
    const run = (env: Record<string, string>) => spawnSync('bash', [join(ci, 'site-token.sh')], { env: { PATH: process.env.PATH ?? '', ...env }, encoding: 'utf8' });

    test('a token the repository already holds is used as-is', () => {
        const result = run({ PHV_TOKEN: 'secret-from-repo', PHV_DASHBOARD_ORIGIN: 'https://ph.example' });

        expect(result.status).toBe(0);
        expect(result.stdout).toBe('secret-from-repo');
    });

    test('with no token and no dashboard there is nowhere to obtain one from', () => {
        const result = run({});

        expect(result.status).toBe(1);
        expect(result.stderr).toContain('no dashboard origin');
    });

    test('outside a job that can request an identity token, the missing permission is named', () => {
        const result = run({ PHV_DASHBOARD_ORIGIN: 'https://ph.example' });

        expect(result.status).toBe(1);
        expect(result.stderr).toContain("id-token: write");
    });
});

function summarise(report: unknown): { stdout: string; outputs: string } {
    const dir = mkdtempSync(join(tmpdir(), 'phv-summarise-'));
    const result = join(dir, 'result.json');
    const outputs = join(dir, 'outputs');

    writeFileSync(result, JSON.stringify(report));
    writeFileSync(outputs, '');

    const run = spawnSync('node', [join(ci, 'summarise.mjs'), result], {
        env: { PATH: process.env.PATH ?? '', GITHUB_OUTPUT: outputs },
        encoding: 'utf8',
    });

    expect(run.status, run.stderr).toBe(0);

    return { stdout: run.stdout, outputs: readFileSync(outputs, 'utf8') };
}

test.describe('summarise.mjs', () => {
    test('publishes the outcome and names the checks that did not pass', () => {
        const { stdout, outputs } = summarise({
            overall: 'changes_detected',
            checks: [
                { id: 'home', kind: 'assert', outcome: 'passed', diagnostic: null },
                { id: 'home', kind: 'text', outcome: 'changes_detected', diagnostic: 'Snapshot comparison failed' },
            ],
        });

        expect(outputs).toContain('overall=changes_detected\n');
        expect(outputs).toContain('summary=1 of 2 checks did not pass: text:home changes_detected.\n');
        expect(stdout).toContain('## Verification: CHANGES_DETECTED');
        expect(stdout).toContain('| home | text | changes_detected |');
    });

    test('an unchanged environment is stated, and an unknown one is not called unchanged', () => {
        expect(summarise({ overall: 'inconclusive', checks: [], environment_delta: { known: true, changed: [] } }).outputs).toContain(
            'environment=Environment: the site reports the same Craft and plugin versions as at baseline.',
        );
        expect(summarise({ overall: 'passed', checks: [], environment_delta: { known: false, changed: [] } }).outputs).toContain(
            'environment=Environment: unknown, the baseline predates package snapshots.',
        );
        expect(
            summarise({ overall: 'passed', checks: [], environment_delta: { known: true, changed: [{ name: 'craftcms/cms', before: '5.8.14', after: '5.8.15' }] } }).outputs,
        ).toContain('environment=Environment: craftcms/cms 5.8.14 → 5.8.15.');
    });

    test('a capture has no environment line', () => {
        expect(summarise({ overall: 'passed', checks: [] }).outputs).toContain('environment=\n');
    });

    test('a missing result file is reported as no run, not as a pass', () => {
        const outputs = join(mkdtempSync(join(tmpdir(), 'phv-summarise-')), 'outputs');
        writeFileSync(outputs, '');

        const run = spawnSync('node', [join(ci, 'summarise.mjs'), ''], { env: { PATH: process.env.PATH ?? '', GITHUB_OUTPUT: outputs }, encoding: 'utf8' });

        expect(run.status).toBe(0);
        expect(readFileSync(outputs, 'utf8')).toContain('overall=\nsummary=The run produced no result, so nothing was verified.\n');
    });

    test('a newline in a diagnostic cannot break a workflow output', () => {
        const { outputs } = summarise({
            overall: 'failed',
            checks: [{ id: 'home', kind: 'assert', outcome: 'failed', diagnostic: 'line one\nline two' }],
        });

        expect(outputs.split('\n').filter((line) => line.startsWith('summary=')).length).toBe(1);
    });
});
