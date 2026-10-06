import { expect, test } from '@playwright/test';
import { noBaselineAbort } from '../../src/manifest';

/**
 * A comparison re-run without its baseline. The run id is new for every attempt, so "Re-run failed
 * jobs" after a failed comparison asks for a capture nobody took under that id. That must end
 * inconclusive, and the reason must name the remedy rather than leave a reader to infer it.
 */
test.describe('noBaselineAbort', () => {
    test('a later attempt with no baseline says to use Re-run all jobs', () => {
        const refusal = noBaselineAbort('runs/staging.example/remediation-31-37500422173-2/', 'remediation-31-37500422173-2', '2');

        expect(refusal.reason).toBe('no_baseline');
        expect(refusal.detail.join(' ')).toBe(
            'No baseline was captured under remediation-31-37500422173-2. This is attempt 2 of the workflow run, and a re-run of only the failed jobs repeats the comparison without repeating the baseline. ' +
                'Use "Re-run all jobs" so the baseline is captured again under this attempt\'s run id.',
        );
    });

    test('a first attempt, or a run outside a workflow, keeps the plain reason', () => {
        for (const attempt of ['1', undefined]) {
            const refusal = noBaselineAbort('runs/x/', 'trial-001', attempt);

            expect(refusal.reason).toBe('no_baseline');
            expect(refusal.detail.join(' ')).not.toContain('Re-run all jobs');
            expect(refusal.detail[0]).toBe('runs/x/ holds no completed capture.');
        }
    });
});
