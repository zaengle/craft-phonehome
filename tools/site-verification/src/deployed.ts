import { readFileSync } from 'node:fs';
import type { Abort } from './manifest';

/**
 * Waiting for the environment to be running the commit a comparison is about.
 *
 * A comparison started by a push to `main` runs as soon as the push lands, and a host that deploys on
 * its own (Forge watching the branch, for one) may not have deployed it yet. On the pilot the whole
 * pair ran and reported while the environment was still serving the remediation branch; it passed
 * only because that branch held the same code. The comparison has to wait for the environment, not
 * for the push, so before the after side is captured the runner polls the plugin's report until the
 * environment reports the versions the commit's `composer.lock` records, and gives up as
 * inconclusive when it never does.
 *
 * What this can see is bounded by what the plugin reports, which is Craft and each installed plugin
 * by version. A commit that changes no reported package (a template edit, say) is indistinguishable
 * from the commit before it, so the guard is satisfied at once; it is a guard for the case that
 * matters to a remediation, where the lock file always moves.
 */

/** Versions by Composer name, as a lock file records them. */
export function readLockVersions(lock: unknown): Record<string, string> {
    const versions: Record<string, string> = {};

    if (lock === null || typeof lock !== 'object' || Array.isArray(lock)) {
        return versions;
    }

    for (const key of ['packages', 'packages-dev'] as const) {
        const list = (lock as Record<string, unknown>)[key];

        if (!Array.isArray(list)) {
            continue;
        }

        for (const entry of list as { name?: unknown; version?: unknown }[]) {
            if (typeof entry?.name === 'string' && typeof entry.version === 'string') {
                versions[entry.name] = entry.version;
            }
        }
    }

    return versions;
}

export function readLockFile(path: string): Record<string, string> {
    return readLockVersions(JSON.parse(readFileSync(path, 'utf8')) as unknown);
}

export interface LockMismatch {
    name: string;
    /** What the lock file records, or null when the lock file no longer has the package. */
    wanted: string | null;
    /** What the environment reports, or null when it reports nothing under that name. */
    reported: string | null;
}

/** A leading `v` is a tagging convention, not a different version. */
const normalise = (version: string) => version.replace(/^v(?=\d)/i, '');

/**
 * The reported packages that disagree with the lock file.
 *
 * Only names the environment reports can be compared, so a lock entry the plugin cannot see (a
 * library, or a plugin reported by handle on an older plugin) is not waited for. A package the
 * environment reports and the lock file does not have is a mismatch: the environment is still
 * running something the commit removed. Craft itself is always reported, so `craftcms/cms` is
 * always compared.
 */
export function lockMismatches(lock: Record<string, string>, reported: Record<string, string>): LockMismatch[] {
    // A handle from an older plugin API cannot be looked up in a Composer lock file. Craft is
    // mandatory evidence even if a malformed report omitted it; other Composer names are
    // compared when reported, including packages removed from the lock file.
    const names = new Set(Object.keys(reported).filter((name) => name.includes('/')));
    if (lock['craftcms/cms'] !== undefined) names.add('craftcms/cms');

    return [...names]
        .sort()
        .filter((name) => lock[name] === undefined || reported[name] === undefined || normalise(lock[name]) !== normalise(reported[name]))
        .map((name) => ({ name, wanted: lock[name] ?? null, reported: reported[name] ?? null }));
}

export type WaitOutcome =
    | { deployed: true; polls: number }
    | { deployed: false; polls: number; mismatches: LockMismatch[]; lastError: string | null };

export interface WaitOptions {
    timeoutMs: number;
    intervalMs: number;
    /** Injected so a test can run the loop without waiting on a real clock. */
    now?: () => number;
    sleep?: (ms: number) => Promise<void>;
}

/**
 * Polls until the environment reports what the lock file records, or the timeout passes.
 *
 * `poll` returns the versions the environment reports, or a sentence saying why it could not be
 * read. A site mid-deploy commonly answers with an error page for a while, so an unreadable report
 * is a reason to keep waiting, not to stop.
 */
export async function waitForLock(
    lock: Record<string, string>,
    poll: () => Promise<Record<string, string> | string>,
    options: WaitOptions,
): Promise<WaitOutcome> {
    const now = options.now ?? Date.now;
    const sleep = options.sleep ?? ((ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms)));
    const deadline = now() + options.timeoutMs;
    let polls = 0;
    let mismatches: LockMismatch[] = [];
    let lastError: string | null = null;

    for (;;) {
        polls++;
        const reported = await poll();

        if (typeof reported === 'string') {
            lastError = reported;
        } else if (Object.keys(reported).length === 0) {
            lastError = 'The environment reported no package versions.';
        } else {
            lastError = null;
            mismatches = lockMismatches(lock, reported);

            if (mismatches.length === 0) {
                return { deployed: true, polls };
            }
        }

        if (now() + options.intervalMs > deadline) {
            return { deployed: false, polls, mismatches, lastError };
        }

        await sleep(options.intervalMs);
    }
}

/**
 * Why a comparison that waited in vain is inconclusive, naming the versions it was waiting for.
 *
 * Inconclusive and never passed: whatever the pages look like at this point, they are not known to
 * be the pages of the commit the comparison is about.
 */
export function notDeployedAbort(outcome: Extract<WaitOutcome, { deployed: false }>, origin: string, lockRef: string, timeoutMs: number): Abort {
    const waited = `${Math.round(timeoutMs / 1000)}s`;
    const detail = [`${origin} was not running ${lockRef} after ${waited}, so the comparison would not have been of that commit.`];

    if (outcome.mismatches.length > 0) {
        detail.push(
            `Waiting for ${outcome.mismatches
                .map((entry) =>
                    entry.wanted === null
                        ? `${entry.name} to be removed (still ${entry.reported})`
                        : `${entry.name} ${entry.wanted} (reported ${entry.reported ?? 'nothing'})`,
                )
                .join(', ')}.`,
        );
    }

    if (outcome.lastError !== null) {
        detail.push(`The last report could not be read: ${outcome.lastError}`);
    }

    return { reason: 'not_deployed', detail };
}
