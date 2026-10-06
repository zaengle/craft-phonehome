import { expect, test } from '@playwright/test';
import { lockMismatches, notDeployedAbort, readLockVersions, waitForLock } from '../../src/deployed';

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

test.describe('waitForLock', () => {
    test('keeps waiting while the environment reports other versions, and stops once it matches', async () => {
        const clock = fakeClock();
        const reports = [{ 'craftcms/cms': '5.8.14' }, 'api_unavailable: The plugin API answered 502.', { 'craftcms/cms': '5.8.15' }];
        let polled = 0;

        const outcome = await waitForLock(lock, async () => reports[polled++], { timeoutMs: 60_000, intervalMs: 10_000, ...clock });

        expect(outcome).toEqual({ deployed: true, polls: 3 });
        expect(clock.slept).toEqual([10_000, 10_000]);
    });

    test('times out when the environment never reports the lock file, naming what it waited for', async () => {
        const clock = fakeClock();
        let polled = 0;

        const outcome = await waitForLock(
            lock,
            async () => {
                polled++;

                return { 'craftcms/cms': '5.8.14', 'verbb/formie': '3.0.4' };
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
                'Waiting for craftcms/cms 5.8.15 (reported 5.8.14).',
        );
    });

    test('an environment that never answers is waited on, and the last error is kept', async () => {
        const clock = fakeClock();

        const outcome = await waitForLock(lock, async () => 'api_unavailable: The plugin API answered 503.', { timeoutMs: 20_000, intervalMs: 10_000, ...clock });

        expect(outcome).toEqual({ deployed: false, polls: 3, mismatches: [], lastError: 'api_unavailable: The plugin API answered 503.' });

        if (!outcome.deployed) {
            expect(notDeployedAbort(outcome, 'https://staging.example', 'main', 20_000).detail).toContain(
                'The last report could not be read: api_unavailable: The plugin API answered 503.',
            );
        }
    });
});
