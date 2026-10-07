import { expect, test } from '@playwright/test';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    decide,
    fingerprintLock,
    lockMismatches,
    notDeployedAbort,
    readExpectedLock,
    readLockVersions,
    readReportedRevision,
    waitForLock,
    type ExpectedRevision,
    type ReportedRevision,
} from '../../src/deployed';

/**
 * The wait between a push and the comparison of what it deployed. On the pilot the comparison ran
 * before the host had deployed the merge, and passed only because the old code happened to match;
 * these pin that a mismatched environment keeps the runner waiting and that giving up is
 * inconclusive, naming what it waited for.
 */
const lock = readLockVersions({
    packages: [
        { name: 'craftcms/cms', version: '5.8.15' },
        { name: 'verbb/formie', version: 'v3.0.4' },
        { name: 'symfony/yaml', version: 'v7.1.0' },
    ],
    'packages-dev': [{ name: 'craftcms/generator', version: '2.1.0' }],
});

/** A clock that only moves when the loop sleeps, so the timeout is reached without waiting. */
function fakeClock() {
    let time = 0;
    const slept: number[] = [];

    return {
        slept,
        now: () => time,
        sleep: async (ms: number) => {
            slept.push(ms);
            time += ms;
        },
    };
}

test.describe('readLockVersions', () => {
    test('reads packages and dev packages by name', () => {
        expect(lock).toEqual({ 'craftcms/cms': '5.8.15', 'verbb/formie': 'v3.0.4', 'symfony/yaml': 'v7.1.0', 'craftcms/generator': '2.1.0' });
    });

    test('a lock file it cannot read yields nothing rather than a guess', () => {
        expect(readLockVersions(null)).toEqual({});
        expect(readLockVersions({ packages: 'nope' })).toEqual({});
    });
});

test.describe('lockMismatches', () => {
    test('compares only what the environment reports, ignoring a leading v', () => {
        expect(lockMismatches(lock, { 'craftcms/cms': '5.8.15', 'verbb/formie': '3.0.4' })).toEqual([]);
    });

    test('older plugins reported by handle do not block a matching Craft deployment', () => {
        expect(lockMismatches(lock, { 'craftcms/cms': '5.8.15', formie: '3.0.4' })).toEqual([]);
    });

    test('a report missing Craft cannot prove that the lock file is deployed', () => {
        expect(lockMismatches(lock, { 'verbb/formie': '3.0.4' })).toEqual([
            { name: 'craftcms/cms', wanted: '5.8.15', reported: null },
        ]);
    });

    test('names a package at the wrong version, and one the commit removed', () => {
        expect(lockMismatches(lock, { 'craftcms/cms': '5.8.14', 'old/plugin': '1.0.0' })).toEqual([
            { name: 'craftcms/cms', wanted: '5.8.15', reported: '5.8.14' },
            { name: 'old/plugin', wanted: null, reported: '1.0.0' },
        ]);
    });
});

const COMMIT = 'dca2ad2f0c6b8e1a4b3c5d7e9f0a1b2c3d4e5f60';

/** The same lock and fingerprint as tests/unit/RevisionResolverTest.php in the plugin. */
const FIXTURE_LOCK = {
    'content-hash': 'a1b2',
    packages: [
        { name: 'verbb/formie', version: '3.1.43', source: { reference: 'abc123' } },
        { name: 'craftcms/cms', version: '5.11.1', dist: { reference: 'def456' } },
    ],
    'packages-dev': [{ name: 'craftcms/generator', version: '2.1.0' }],
};
const FIXTURE_FINGERPRINT = 'cf1210f2911d689f1bed30f33456f7710405cd69ed55eef06702a37ba9f56ade';
const OTHER_COMMIT = '1111111111111111111111111111111111111111';
const HASH = '3f2b6c0e8d1a4b5c9e7f60718293a4b53f2b6c0e8d1a4b5c9e7f60718293a4b5';
const OTHER_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

const expected: ExpectedRevision = { versions: lock, lockHash: HASH, commit: COMMIT };
const matchingVersions = { 'craftcms/cms': '5.8.15', 'verbb/formie': '3.0.4' };
const oldVersions = { 'craftcms/cms': '5.8.14', 'verbb/formie': '3.0.4' };

/** What an environment reports: versions always, a revision only from a 1.8.3 plugin. */
const report = (versions: Record<string, string>, revision: Partial<Omit<ReportedRevision, 'versions'>> = {}): ReportedRevision => ({
    versions,
    lockHash: revision.lockHash ?? null,
    commit: revision.commit ?? null,
});

test.describe('readExpectedLock and readReportedRevision', () => {
    test('the expected side fingerprints the locked packages and carries the commit it came from', () => {
        const path = join(mkdtempSync(join(tmpdir(), 'phv-lock-')), 'composer.lock');
        writeFileSync(path, JSON.stringify(FIXTURE_LOCK));

        expect(readExpectedLock(path, COMMIT.toUpperCase())).toEqual({
            versions: { 'verbb/formie': '3.1.43', 'craftcms/cms': '5.11.1', 'craftcms/generator': '2.1.0' },
            lockHash: FIXTURE_FINGERPRINT,
            commit: COMMIT,
        });
        expect(readExpectedLock(path, 'main').commit).toBeNull();
    });

    test('the fingerprint is the one the plugin computes, and is not Composer\'s content-hash', () => {
        // tests/unit/RevisionResolverTest.php pins the same value for the same lock, so the runner
        // and the plugin cannot drift apart without one of the two suites failing.
        expect(fingerprintLock(FIXTURE_LOCK)).toBe(FIXTURE_FINGERPRINT);

        // A security update moves a locked version and leaves composer.json, and so content-hash,
        // alone. The fingerprint must move anyway.
        const moved = structuredClone(FIXTURE_LOCK);
        moved.packages[0].version = '3.1.44';
        expect(moved['content-hash']).toBe(FIXTURE_LOCK['content-hash']);
        expect(fingerprintLock(moved)).not.toBe(FIXTURE_FINGERPRINT);

        const reordered = { ...FIXTURE_LOCK, packages: [...FIXTURE_LOCK.packages].reverse() };
        expect(fingerprintLock(reordered)).toBe(FIXTURE_FINGERPRINT);
        expect(fingerprintLock({ packages: [] })).toBeNull();
    });

    test('a report without a revision object, from an older plugin, has neither field', () => {
        expect(readReportedRevision({ craft_version: '5.8.15' })).toEqual({ lockHash: null, commit: null });
        expect(readReportedRevision({ revision: 'nope' })).toEqual({ lockHash: null, commit: null });
        expect(readReportedRevision({ revision: { lock_hash: HASH, commit: COMMIT, commit_source: 'git' } })).toEqual({ lockHash: HASH, commit: COMMIT });
        expect(readReportedRevision({ revision: { lock_hash: 'short', commit: 'main' } })).toEqual({ lockHash: null, commit: null });
    });
});

test.describe('decide', () => {
    test('a reported commit decides, and a different one keeps the runner waiting whatever the versions say', () => {
        // The case the versions could never catch: a commit that moved no package.
        expect(decide(expected, report(matchingVersions, { commit: OTHER_COMMIT, lockHash: HASH }))).toMatchObject({
            signal: 'commit',
            deployed: false,
            expected: COMMIT,
            reported: OTHER_COMMIT,
        });
        expect(decide(expected, report(oldVersions, { commit: COMMIT }))).toMatchObject({ signal: 'commit', deployed: true });
    });

    test('an abbreviated commit matches the full one it abbreviates', () => {
        expect(decide(expected, report({}, { commit: 'dca2ad2' }))).toMatchObject({ signal: 'commit', deployed: true });
        expect(decide(expected, report({}, { commit: 'dca2ad3' }))).toMatchObject({ signal: 'commit', deployed: false });
    });

    test('with no commit reported, the lock hash decides', () => {
        expect(decide(expected, report(oldVersions, { lockHash: HASH }))).toMatchObject({ signal: 'lock_hash', deployed: true });
        expect(decide(expected, report(matchingVersions, { lockHash: OTHER_HASH }))).toMatchObject({ signal: 'lock_hash', deployed: false, reported: OTHER_HASH });
    });

    test('a commit the caller did not name cannot decide, so the lock hash does', () => {
        expect(decide({ ...expected, commit: null }, report(oldVersions, { commit: COMMIT, lockHash: HASH }))).toMatchObject({ signal: 'lock_hash', deployed: true });
    });

    test('an older plugin with neither falls back to the versions', () => {
        expect(decide(expected, report(matchingVersions))).toMatchObject({ signal: 'versions', deployed: true });
        expect(decide(expected, report(oldVersions))).toMatchObject({
            signal: 'versions',
            deployed: false,
            mismatches: [{ name: 'craftcms/cms', wanted: '5.8.15', reported: '5.8.14' }],
        });
    });
});

test.describe('waitForLock', () => {
    test('keeps waiting through other commits and unreadable reports, and says what confirmed it', async () => {
        const clock = fakeClock();
        const reports = [report(matchingVersions, { commit: OTHER_COMMIT }), 'api_unavailable: The plugin API answered 502.', report(matchingVersions, { commit: COMMIT })];
        let polled = 0;

        const outcome = await waitForLock(expected, async () => reports[polled++], { timeoutMs: 60_000, intervalMs: 10_000, ...clock });

        expect(outcome).toEqual({ deployed: true, polls: 3, confirmedBy: 'commit', value: COMMIT });
        expect(clock.slept).toEqual([10_000, 10_000]);
    });

    test('an older plugin is still waited for by its versions', async () => {
        const clock = fakeClock();
        const reports = [report(oldVersions), report(matchingVersions)];
        let polled = 0;

        const outcome = await waitForLock(expected, async () => reports[polled++], { timeoutMs: 60_000, intervalMs: 10_000, ...clock });

        expect(outcome).toEqual({ deployed: true, polls: 2, confirmedBy: 'versions', value: null });
    });

    test('times out naming the commit it waited for and the one reported', async () => {
        const clock = fakeClock();
        let polled = 0;

        const outcome = await waitForLock(
            expected,
            async () => {
                polled++;

                return report(matchingVersions, { commit: OTHER_COMMIT });
            },
            { timeoutMs: 30_000, intervalMs: 10_000, ...clock },
        );

        expect(outcome.deployed).toBe(false);
        expect(polled).toBe(4);
        expect(clock.now()).toBeLessThanOrEqual(30_000);

        if (outcome.deployed) {
            return;
        }

        const refusal = notDeployedAbort(outcome, 'https://staging.example', 'dca2ad2', 30_000);

        expect(refusal.reason).toBe('not_deployed');
        expect(refusal.detail.join(' ')).toBe(
            'https://staging.example was not running dca2ad2 after 30s, so the comparison would not have been of that commit. ' +
                `Waiting for commit ${COMMIT} (reported ${OTHER_COMMIT}).`,
        );
    });

    test('times out naming the lock file hash, or the versions for an older plugin', async () => {
        for (const [reported, sentence] of [
            [report(oldVersions, { lockHash: OTHER_HASH }), `Waiting for lock file hash ${HASH} (reported ${OTHER_HASH}).`],
            [report(oldVersions), 'Waiting for craftcms/cms 5.8.15 (reported 5.8.14).'],
        ] as const) {
            const outcome = await waitForLock(expected, async () => reported, { timeoutMs: 10_000, intervalMs: 10_000, ...fakeClock() });

            expect(outcome.deployed).toBe(false);

            if (!outcome.deployed) {
                expect(notDeployedAbort(outcome, 'https://staging.example', 'main', 10_000).detail).toContain(sentence);
            }
        }
    });

    test('an environment that never answers is waited on, and the last error is kept', async () => {
        const clock = fakeClock();

        const outcome = await waitForLock(expected, async () => 'api_unavailable: The plugin API answered 503.', { timeoutMs: 20_000, intervalMs: 10_000, ...clock });

        expect(outcome).toEqual({ deployed: false, polls: 3, last: null, lastError: 'api_unavailable: The plugin API answered 503.' });

        if (!outcome.deployed) {
            expect(notDeployedAbort(outcome, 'https://staging.example', 'main', 20_000).detail).toContain(
                'The last report could not be read: api_unavailable: The plugin API answered 503.',
            );
        }
    });
});
