import { expect, test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, realpathSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * The two scripts the workflows run to say what happened. They compose the sentences a reviewer
 * reads in a pull request and the outputs a calling workflow branches on, so each state they
 * distinguish is pinned here rather than discovered on a real run.
 */
const ci = new URL('../../ci', import.meta.url).pathname;

test('the CI linter receives the whole ignore pattern as one argument', () => {
    const dir = mkdtempSync(join(tmpdir(), 'phv-actionlint-'));
    const calls = join(dir, 'calls');
    writeFileSync(join(dir, 'docker'), `#!/usr/bin/env node
require('fs').writeFileSync(process.env.CALLS, JSON.stringify(process.argv.slice(2)));
`, { mode: 0o755 });
    const workflow = readFileSync(new URL('../../../../.github/workflows/tests.yml', import.meta.url), 'utf8');
    const step = workflow.split('      - name: Lint the workflows\n')[1].split('      - name: Set up Node\n')[0];
    expect(step).toContain('        run: |\n');
    const script = step.split('        run: |\n')[1].split('\n').map((line) => line.replace(/^ {10}/, '')).join('\n');
    const run = spawnSync('bash', ['-e', '-o', 'pipefail', '-c', script], {
        cwd: dir,
        env: { PATH: `${dir}:${process.env.PATH ?? ''}`, CALLS: calls },
        encoding: 'utf8',
    });

    expect(run.status, run.stderr).toBe(0);
    const args = JSON.parse(readFileSync(calls, 'utf8')) as string[];
    expect(args.slice(args.indexOf('-ignore'))).toEqual(['-ignore', 'property "workflow_sha" is not defined in object type']);
});

test('the workflow treats shell syntax in remediation inputs as literal commit arguments', () => {
    const dir = mkdtempSync(join(tmpdir(), 'phv-input-'));
    const marker = join(dir, 'executed');
    const calls = join(dir, 'calls');
    writeFileSync(join(dir, 'git'), `#!/usr/bin/env node
require('fs').appendFileSync(process.env.CALLS, JSON.stringify(process.argv.slice(2)) + '\\n');
`, { mode: 0o755 });
    const workflow = readFileSync(new URL('../../../../.github/workflows/remediate.yml', import.meta.url), 'utf8');
    const step = workflow.split('      - name: Push the branch\n')[1].split('\n  # Waits for the baseline')[0];
    const script = step.split('        run: |\n')[1].split('\n').map((line) => line.replace(/^ {10}/, '')).join('\n');
    const version = `5.8.15 $(touch "${marker}")`;
    const run = spawnSync('bash', ['-e', '-o', 'pipefail', '-c', script], {
        cwd: dir,
        env: { PATH: `${dir}:${process.env.PATH ?? ''}`, CALLS: calls, BRANCH: 'security/patch-1', PACKAGE: 'craftcms/cms', VERSION: version, PATCH_ID: '1' },
        encoding: 'utf8',
    });

    expect(run.status, run.stderr).toBe(0);
    expect(existsSync(marker)).toBe(false);
    expect(readFileSync(calls, 'utf8').trim().split('\n').map((line) => JSON.parse(line))).toContainEqual([
        'commit', '-m', `Security: craftcms/cms to ${version} (Phone Home patch 1)`,
    ]);
});

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
        expect(body).toContain('It did not exercise forms, the control panel, queue jobs, console commands');
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

    test('no environment at all is a different kind of pull request, not a failed verification', () => {
        const body = prBody({ ...verified, ORIGIN: '', BASELINE: '', BASELINE_SUMMARY: '', DEPLOYED: '', DEPLOY_JOB: 'skipped', DEPLOY_RUN_URL: '', VERIFY_JOB: 'skipped', VERIFICATION: '', VERIFICATION_SUMMARY: '', ENVIRONMENT: '' });

        expect(body).toContain('**Not verified.** This site has no environment to verify on');
        expect(body).toContain('Review it as you would any dependency update.');
        expect(body).not.toContain('could not be captured');
        expect(body).not.toContain('was not deployed with this branch');
        expect(body).not.toContain('screenshots');
        expect(body).not.toContain('[!WARNING]');
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
        const body = prBody({ ...verified, CONTEXT: '', DASHBOARD_ORIGIN: 'https://ph.example/' });

        expect(body).toContain('Phone Home could not be reached for the assessment behind [patch 7](https://ph.example/patches/7)');
    });

    test('the patch is a link to Phone Home, never a bare #number that GitHub links to an unrelated issue', () => {
        // On zaengle/v4.1.zaengle.com#291 "patch #90" linked to that repository's own #90.
        const context = JSON.stringify({ patch: { title: 'Formie 3.1.43', url: 'https://ph.example/patches/90' }, reviews: [] });
        const answered = prBody({ ...verified, PATCH_ID: '90', CONTEXT: context, DASHBOARD_ORIGIN: 'https://elsewhere.example' });
        const unreachable = prBody({ ...verified, PATCH_ID: '90', CONTEXT: '', DASHBOARD_ORIGIN: 'https://ph.example' });
        const nowhere = prBody({ ...verified, PATCH_ID: '90', CONTEXT: '' });

        // The page's own URL wins over one built from the origin.
        expect(answered).toContain('Prepared by Phone Home for [patch 90](https://ph.example/patches/90).');
        expect(unreachable).toContain('Prepared by Phone Home for [patch 90](https://ph.example/patches/90).');
        expect(nowhere).toContain('Prepared by Phone Home for patch 90.');

        for (const body of [answered, unreachable, nowhere]) {
            expect(body).not.toMatch(/(^|[^\w&/])#\d+/m);
        }
    });

    test('a command with quotes in what moved does not break the body', () => {
        const body = prBody({ ...verified, MOVED: 'vendor/pkg "^2" -> 2.0.0' });

        expect(body).toContain('vendor/pkg "^2" -> 2.0.0');
    });
});

test.describe('pr-body.sh on how far the update reached', () => {
    test('an exact update says nothing else was allowed to move', () => {
        expect(prBody({ ...verified, SCOPE: 'exact' })).toContain('Only the requested package was allowed to move.');
    });

    test('a widened update says how far and quotes the conflict that made it widen', () => {
        const body = prBody({ ...verified, SCOPE: 'dependencies', SCOPE_REASON: 'verbb/formie 3.1.43 requires verbb/base ^3.0.17.' });

        expect(body).toContain('its own dependencies were allowed to move as well, with the fewest changes Composer could make. No root requirement, such as Craft, was moved. Composer reported: verbb/formie 3.1.43 requires verbb/base ^3.0.17.');
        expect(prBody({ ...verified, SCOPE: 'all', SCOPE_REASON: 'x' })).toContain('root requirements such as Craft were allowed to move too');
    });

    test('a run that did not resolve the change says nothing about scope', () => {
        expect(prBody({ ...verified, SCOPE: '' })).not.toContain('allowed to move');
    });
});

test.describe('pr-body.sh on a resumed branch', () => {
    test('a branch an earlier run pushed points at its commit rather than showing an empty list', () => {
        const body = prBody({ ...verified, MOVED: '', COUNT: '' });

        expect(body).toContain("so what moved is in that branch's commit rather than repeated here.");
        expect(body).not.toContain('```\n\n```');
    });
});

/**
 * A stand-in `gh` for open-pr.sh, recording each call so a test can say which of create and edit
 * ran. `openUrl` is the pull request already open for the branch, or empty for none.
 */
function fakePrGh(openUrl: string): { dir: string; calls: string } {
    const dir = mkdtempSync(join(tmpdir(), 'phv-gh-'));
    const calls = join(dir, 'calls');

    writeFileSync(calls, '');
    writeFileSync(
        join(dir, 'gh'),
        `#!/usr/bin/env bash
echo "$*" >> '${calls}'
case "$1 $2" in
  "pr list") echo '${openUrl}' ;;
  "pr edit") exit 0 ;;
  "pr create") echo "https://github.com/z/x/pull/26" ;;
  *) echo "unexpected gh $*" >&2; exit 64 ;;
esac`,
    );
    spawnSync('chmod', ['+x', join(dir, 'gh')]);

    return { dir, calls };
}

function openPr(openUrl: string) {
    const gh = fakePrGh(openUrl);
    const outputs = join(gh.dir, 'outputs');
    writeFileSync(outputs, '');

    const run = spawnSync('bash', [join(ci, 'open-pr.sh')], {
        env: {
            PATH: `${gh.dir}:${process.env.PATH ?? ''}`,
            GITHUB_OUTPUT: outputs,
            BRANCH: 'security/patch-31-site-2',
            BASE: 'main',
            TITLE: 'Security: craftcms/cms to 5.8.15',
            BODY_FILE: '/tmp/pr-body.md',
        },
        encoding: 'utf8',
    });

    return { status: run.status, stderr: run.stderr, outputs: readFileSync(outputs, 'utf8'), calls: readFileSync(gh.calls, 'utf8').trim().split('\n') };
}

test.describe('open-pr.sh', () => {
    test('with no pull request open, a draft is created and its URL published', () => {
        const result = openPr('');

        expect(result.status, result.stderr).toBe(0);
        expect(result.calls).toEqual([
            'pr list --head security/patch-31-site-2 --state open --json url --jq .[0].url // empty',
            'pr create --draft --base main --head security/patch-31-site-2 --title Security: craftcms/cms to 5.8.15 --body-file /tmp/pr-body.md',
        ]);
        expect(result.outputs).toBe('url=https://github.com/z/x/pull/26\n');
    });

    test('a re-run updates the open pull request instead of asking for a second one', () => {
        // The pilot's re-run: #25 was already open for the branch, GitHub refused a second pull
        // request, the job failed, and #25 kept the first attempt's inconclusive verdict.
        const result = openPr('https://github.com/z/x/pull/25');

        expect(result.status, result.stderr).toBe(0);
        expect(result.calls).toEqual([
            'pr list --head security/patch-31-site-2 --state open --json url --jq .[0].url // empty',
            'pr edit https://github.com/z/x/pull/25 --body-file /tmp/pr-body.md',
        ]);
        expect(result.outputs).toBe('url=https://github.com/z/x/pull/25\n');
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

/**
 * A stand-in `gh` on PATH, answering the four calls the deploy script makes. The real one is not
 * available outside a job, and the script's first version used a flag gh does not accept, which
 * only running it could reveal.
 */
function fakeGh(behaviour: { runAppears: boolean; watchExit: number }): string {
    const dir = mkdtempSync(join(tmpdir(), 'phv-gh-'));
    const runs = behaviour.runAppears ? '[{"databaseId":42,"createdAt":"2999-01-01T00:00:00Z"},{"databaseId":41,"createdAt":"2000-01-01T00:00:00Z"}]' : '[]';

    writeFileSync(
        join(dir, 'gh'),
        `#!/usr/bin/env bash
case "$1 $2" in
  "workflow run") exit 0 ;;
  "run list") echo '${runs}' ;;
  "run view") echo "https://github.com/z/x/actions/runs/$3" ;;
  "run watch") exit ${behaviour.watchExit} ;;
  *) echo "unexpected gh $*" >&2; exit 64 ;;
esac`,
    );
    spawnSync('chmod', ['+x', join(dir, 'gh')]);

    return dir;
}

function deployWatch(env: Record<string, string>, behaviour: { runAppears: boolean; watchExit: number }) {
    const outputs = join(mkdtempSync(join(tmpdir(), 'phv-deploy-')), 'outputs');
    writeFileSync(outputs, '');

    const run = spawnSync('bash', [join(ci, 'deploy-watch.sh')], {
        env: { PATH: `${fakeGh(behaviour)}:${process.env.PATH ?? ''}`, GITHUB_OUTPUT: outputs, DEPLOY_WATCH_ATTEMPTS: '2', DEPLOY_WATCH_INTERVAL: '0', BRANCH: 'security/patch-1-site-1', ...env },
        encoding: 'utf8',
    });

    return { status: run.status, stderr: run.stderr, stdout: run.stdout, outputs: readFileSync(outputs, 'utf8') };
}

test.describe('deploy-watch.sh', () => {
    test('a successful deploy run publishes deployed=1 and where it ran', () => {
        const result = deployWatch({ DEPLOY_WORKFLOW: 'deploy-staging.yml' }, { runAppears: true, watchExit: 0 });

        expect(result.status, result.stderr).toBe(0);
        expect(result.outputs).toContain('run_url=https://github.com/z/x/actions/runs/42\n');
        expect(result.outputs).toContain('deployed=1\n');
    });

    test('a failed deploy run fails the step and says so', () => {
        const result = deployWatch({ DEPLOY_WORKFLOW: 'deploy-staging.yml' }, { runAppears: true, watchExit: 1 });

        expect(result.status).toBe(1);
        expect(result.outputs).toContain('deployed=0\n');
        expect(result.stdout).toContain('::error::The deploy run failed');
    });

    test('a dispatch that never produces a run fails rather than waiting forever', () => {
        const result = deployWatch({ DEPLOY_WORKFLOW: 'deploy-staging.yml' }, { runAppears: false, watchExit: 0 });

        expect(result.status).toBe(1);
        expect(result.stdout).toContain('no run appeared');
    });

    test('no deploy workflow named is reported, not failed', () => {
        const result = deployWatch({ DEPLOY_WORKFLOW: '' }, { runAppears: true, watchExit: 0 });

        expect(result.status).toBe(0);
        expect(result.outputs).toContain('deployed=0\n');
        expect(result.stdout).toContain('::warning::');
    });
});

function summarise(report: unknown, unstoredBaseline: string | null = null): { stdout: string; outputs: string } {
    const dir = mkdtempSync(join(tmpdir(), 'phv-summarise-'));
    const result = join(dir, 'result.json');
    const outputs = join(dir, 'outputs');

    writeFileSync(result, JSON.stringify(report));
    writeFileSync(outputs, '');
    if (unstoredBaseline !== null) {
        writeFileSync(join(dir, 'baseline-unstored.txt'), unstoredBaseline);
    }

    const run = spawnSync('node', [join(ci, 'summarise.mjs'), result], {
        env: { PATH: process.env.PATH ?? '', GITHUB_OUTPUT: outputs },
        encoding: 'utf8',
    });

    expect(run.status, run.stderr).toBe(0);

    return { stdout: run.stdout, outputs: readFileSync(outputs, 'utf8') };
}

test.describe('summarise.mjs', () => {
    test('a capture whose baseline was not stored is inconclusive, however its checks went', () => {
        const { stdout, outputs } = summarise(
            { overall: 'passed', mode: 'capture', checks: [{ id: 'home', kind: 'assert', outcome: 'passed', diagnostic: null }] },
            'about.png: HTTP 500',
        );

        expect(outputs).toContain('overall=inconclusive');
        expect(outputs).toContain('summary=The baseline could not be stored on Phone Home (about.png: HTTP 500)');
        expect(stdout).toContain('## Verification: INCONCLUSIVE');
    });

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

    test('a comparison re-run without its baseline leads with Re-run all jobs', () => {
        const { stdout, outputs } = summarise({
            overall: 'inconclusive',
            mode: 'compare',
            checks: [
                {
                    id: 'gate',
                    kind: 'gate',
                    outcome: 'inconclusive',
                    diagnostic: 'Error: INCONCLUSIVE: no_baseline — No baseline was captured under remediation-31-1-2. … Use "Re-run all jobs" so the baseline is captured again under this attempt\'s run id.',
                },
            ],
        });

        expect(outputs).toContain('overall=inconclusive\n');
        expect(outputs).toContain('summary=No baseline was captured under this attempt\'s run id, because only the failed jobs were re-run. Use "Re-run all jobs" to capture one.');
        expect(stdout).toContain('Use "Re-run all jobs" so the baseline is captured again.');
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

/** How the stand-in `composer` answers each of the three update steps the script may try. */
type Step = 'exact' | 'dependencies' | 'all';
type Answers = Partial<Record<Step, number>>;

const CONFLICT = `Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - Root composer.json requires verbb/formie 3.1.43 -> satisfiable by verbb/formie[3.1.43].
    - verbb/formie 3.1.43 requires verbb/base ^3.0.17 -> found verbb/base[3.0.17] but the package is fixed to 3.0.12 (lock file version) by a partial update and that version does not match. Make sure you list it as an argument for the update command.
`;

/**
 * A site whose Craft application lives in `src/`, with a stand-in `composer` on PATH. It records
 * every call, and answers each update step with the exit status the test gives it: 0 moves
 * Formie in the lock file it finds where it is run, as the real one would, and 2 prints a
 * dependency conflict after scribbling on the lock file, which the script must undo before its
 * next attempt.
 */
function siteInSubdirectory(answers: Answers = {}): { root: string; app: string; bin: string; calls: string } {
    // Resolved, because the temporary directory is reached through a symlink on macOS and the
    // stand-in reports the real path it ran in.
    const root = realpathSync(mkdtempSync(join(tmpdir(), 'phv-site-')));
    const app = join(root, 'src');
    const bin = join(root, 'bin');
    const calls = join(root, 'calls');

    spawnSync('mkdir', ['-p', app, bin]);
    writeFileSync(calls, '');
    writeFileSync(join(app, 'composer.json'), '{"require":{"craftcms/cms":"^5.8"}}');
    writeFileSync(
        join(app, 'composer.lock'),
        JSON.stringify({ packages: [{ name: 'verbb/formie', version: '3.1.42' }, { name: 'craftcms/cms', version: '5.11.1' }] }),
    );
    writeFileSync(
        join(bin, 'composer'),
        `#!/usr/bin/env bash
echo "$* in $(pwd)" >> '${calls}'
case "$*" in
  *--with-all-dependencies*) code=${answers.all ?? 0} ;;
  *--with-dependencies*) code=${answers.dependencies ?? 0} ;;
  *) code=${answers.exact ?? 0} ;;
esac
if [ "$code" = 2 ]; then
  echo '{"packages":[{"name":"half/written","version":"0.0.1"}]}' > composer.lock
  printf '%s' '${CONFLICT}' >&2
  exit 2
fi
[ "$code" = 0 ] || { echo "The 'https://composer.example/packages.json' URL required authentication." >&2; exit "$code"; }
php -r '$l = json_decode(file_get_contents("composer.lock"), true); $l["packages"][0]["version"] = "3.1.43"; file_put_contents("composer.lock", json_encode($l));'`,
    );
    spawnSync('chmod', ['+x', join(bin, 'composer')]);

    return { root, app, bin, calls };
}

function resolveChange(cwd: string, site: { bin: string; calls: string }) {
    const outputs = join(mkdtempSync(join(tmpdir(), 'phv-resolve-')), 'outputs');
    writeFileSync(outputs, '');

    const run = spawnSync('bash', [join(ci, 'resolve-change.sh')], {
        cwd,
        env: { PATH: `${site.bin}:${process.env.PATH ?? ''}`, GITHUB_OUTPUT: outputs, PACKAGE: 'verbb/formie', VERSION: '3.1.43' },
        encoding: 'utf8',
    });

    return {
        status: run.status,
        stdout: run.stdout,
        stderr: run.stderr,
        outputs: readFileSync(outputs, 'utf8'),
        calls: readFileSync(site.calls, 'utf8').trim().split('\n').filter(Boolean),
    };
}

const REASON = 'verbb/formie 3.1.43 requires verbb/base ^3.0.17 -> found verbb/base[3.0.17] but the package is fixed to 3.0.12 (lock file version) by a partial update and that version does not match.';

test.describe('resolve-change.sh', () => {
    test('tries the exact update first, and stops there when it resolves', () => {
        const site = siteInSubdirectory();
        const result = resolveChange(site.app, site);

        expect(result.status, result.stderr).toBe(0);
        expect(result.calls).toEqual([`update verbb/formie:3.1.43 --no-interaction --no-scripts in ${site.app}`]);
        expect(result.outputs).toBe('count=1\nscope=exact\nscope_reason=\nmoved<<MOVED\nverbb/formie 3.1.42 -> 3.1.43\nMOVED\n');
        // Nothing is written at the repository root, and the working files are cleaned up.
        expect(existsSync(join(site.root, 'composer.lock'))).toBe(false);
        expect(existsSync(join(site.app, 'composer.lock.before'))).toBe(false);
        expect(existsSync(join(site.app, 'composer.err'))).toBe(false);
    });

    test('a conflict widens to the package\'s own dependencies, from the original lock file, and says why', () => {
        // #291 on the first real site moved 48 packages for one Formie release because the update
        // always started from every dependency.
        const site = siteInSubdirectory({ exact: 2 });
        const result = resolveChange(site.app, site);

        expect(result.status, result.stderr).toBe(0);
        expect(result.calls).toEqual([
            `update verbb/formie:3.1.43 --no-interaction --no-scripts in ${site.app}`,
            `update verbb/formie:3.1.43 --with-dependencies --minimal-changes --no-interaction --no-scripts in ${site.app}`,
        ]);
        expect(result.outputs).toContain('scope=dependencies\n');
        expect(result.outputs).toContain(`scope_reason=${REASON}\n`);
        // The failed attempt's half-written lock file was put back before the next one.
        expect(result.outputs).toContain('count=1\n');
        expect(result.outputs).not.toContain('half/written');
    });

    test('only when that also conflicts may root requirements move, still with minimal changes', () => {
        const site = siteInSubdirectory({ exact: 2, dependencies: 2 });
        const result = resolveChange(site.app, site);

        expect(result.status, result.stderr).toBe(0);
        expect(result.calls.at(-1)).toBe(`update verbb/formie:3.1.43 --with-all-dependencies --minimal-changes --no-interaction --no-scripts in ${site.app}`);
        expect(result.calls).toHaveLength(3);
        expect(result.outputs).toContain('scope=all\n');
        expect(result.outputs).toContain(`scope_reason=${REASON}\n`);
    });

    test('a conflict at every step fails before anything is pushed', () => {
        const site = siteInSubdirectory({ exact: 2, dependencies: 2, all: 2 });
        const result = resolveChange(site.app, site);

        expect(result.status).toBe(2);
        expect(result.calls).toHaveLength(3);
        expect(result.stdout).toContain(`::error::verbb/formie could not be moved to 3.1.43 even with every dependency allowed to move: ${REASON}`);
        expect(result.outputs).toBe('');
    });

    test('a failure that is not a conflict is not retried more broadly', () => {
        const site = siteInSubdirectory({ exact: 1 });
        const result = resolveChange(site.app, site);

        expect(result.status).toBe(1);
        expect(result.calls).toHaveLength(1);
        expect(result.stdout).toContain('which is not a dependency conflict, so no broader update was tried');
        expect(result.stderr).toContain('required authentication');
    });

    test('run from a root with no Composer files, it names working_directory rather than inventing a lock file', () => {
        const site = siteInSubdirectory();
        const result = resolveChange(site.root, site);

        expect(result.status).toBe(1);
        expect(result.stdout).toContain('set working_directory to it in the calling workflow');
        expect(result.calls).toEqual([]);
        expect(existsSync(join(site.root, 'composer.lock'))).toBe(false);
    });
});
