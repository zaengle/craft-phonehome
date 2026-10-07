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
 * The environment says what it is running in up to three ways, and the strongest one both sides
 * know decides. A plugin from 1.8.3 (API 1.6.0) reports the deployed commit when it can find one,
 * and the `content-hash` of its composer.lock; either settles the question exactly. An older plugin
 * reports only Craft and each installed plugin by version, and then the versions in the lock file
 * are compared, which cannot tell apart two commits that move no reported package. A reported
 * commit is never overridden by a weaker signal: if it differs, the runner keeps waiting whatever
 * the versions say.
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

/** What the environment should report once it is running the commit the comparison is about. */
export interface ExpectedRevision {
    /** Versions by Composer name, from the commit's composer.lock. */
    versions: Record<string, string>;
    /** That lock file's `content-hash`, or null when it has none. */
    lockHash: string | null;
    /** The commit's SHA, or null when the caller did not say. */
    commit: string | null;
}

/** What the environment reported on one poll. */
export interface ReportedRevision {
    versions: Record<string, string>;
    lockHash: string | null;
    commit: string | null;
}

export type Signal = 'commit' | 'lock_hash' | 'versions';

const hex = (value: unknown, pattern: RegExp): string | null =>
    typeof value === 'string' && pattern.test(value.trim()) ? value.trim().toLowerCase() : null;

/** A 7-to-40 character hex SHA, lowercased, or null. */
export const asCommit = (value: unknown): string | null => hex(value, /^[0-9a-f]{7,40}$/i);

/** A Composer content-hash, lowercased, or null. */
export const asLockHash = (value: unknown): string | null => hex(value, /^[0-9a-f]{32}$/i);

/** Reads the expected versions and content-hash from a lock file, with the commit it came from. */
export function readExpectedLock(path: string, commit: string | null): ExpectedRevision {
    const lock = JSON.parse(readFileSync(path, 'utf8')) as unknown;
    const lockHash = lock !== null && typeof lock === 'object' ? asLockHash((lock as Record<string, unknown>)['content-hash']) : null;

    return { versions: readLockVersions(lock), lockHash, commit: asCommit(commit) };
}

/** The `revision` object a 1.8.3 plugin reports, read defensively; an older plugin has none. */
export function readReportedRevision(payload: Record<string, unknown>): { lockHash: string | null; commit: string | null } {
    const revision = payload.revision;

    if (revision === null || typeof revision !== 'object' || Array.isArray(revision)) {
        return { lockHash: null, commit: null };
    }

    return { lockHash: asLockHash((revision as Record<string, unknown>).lock_hash), commit: asCommit((revision as Record<string, unknown>).commit) };
}

/**
 * Two SHAs name the same commit when one is a prefix of the other. A REVISION file may hold an
 * abbreviated SHA where the caller knows the full one; both are at least seven characters.
 */
export const sameCommit = (a: string, b: string): boolean => a.startsWith(b) || b.startsWith(a);

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

/** One poll's verdict: which signal decided it, and whether it matched. */
export interface Decision {
    signal: Signal;
    deployed: boolean;
    /** What was expected and reported under that signal, for the message when the wait times out. */
    expected: string | null;
    reported: string | null;
    /** For the versions signal only: the packages that disagreed. */
    mismatches: LockMismatch[];
}

/**
 * Decides one poll by the strongest signal both sides have. The commit decides when the
 * environment reports one and the caller named one; otherwise the lock hash when both have it;
 * otherwise the versions, which is what an older plugin offers.
 */
export function decide(expected: ExpectedRevision, reported: ReportedRevision): Decision {
    if (reported.commit !== null && expected.commit !== null) {
        return { signal: 'commit', deployed: sameCommit(reported.commit, expected.commit), expected: expected.commit, reported: reported.commit, mismatches: [] };
    }

    if (reported.lockHash !== null && expected.lockHash !== null) {
        return { signal: 'lock_hash', deployed: reported.lockHash === expected.lockHash, expected: expected.lockHash, reported: reported.lockHash, mismatches: [] };
    }

    const mismatches = lockMismatches(expected.versions, reported.versions);

    return { signal: 'versions', deployed: mismatches.length === 0, expected: null, reported: null, mismatches };
}

export type WaitOutcome =
    | { deployed: true; polls: number; confirmedBy: Signal; value: string | null }
    | { deployed: false; polls: number; last: Decision | null; lastError: string | null };

export interface WaitOptions {
    timeoutMs: number;
    intervalMs: number;
    /** Injected so a test can run the loop without waiting on a real clock. */
    now?: () => number;
    sleep?: (ms: number) => Promise<void>;
}

/**
 * Polls until the environment reports what the commit's lock file records, or the timeout passes.
 *
 * `poll` returns what the environment reports, or a sentence saying why it could not be read. A
 * site mid-deploy commonly answers with an error page for a while, so an unreadable report is a
 * reason to keep waiting, not to stop.
 */
export async function waitForLock(
    expected: ExpectedRevision,
    poll: () => Promise<ReportedRevision | string>,
    options: WaitOptions,
): Promise<WaitOutcome> {
    const now = options.now ?? Date.now;
    const sleep = options.sleep ?? ((ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms)));
    const deadline = now() + options.timeoutMs;
    let polls = 0;
    let last: Decision | null = null;
    let lastError: string | null = null;

    for (;;) {
        polls++;
        const reported = await poll();

        if (typeof reported === 'string') {
            lastError = reported;
        } else if (Object.keys(reported.versions).length === 0 && reported.commit === null && reported.lockHash === null) {
            lastError = 'The environment reported no package versions and no revision.';
        } else {
            lastError = null;
            last = decide(expected, reported);

            if (last.deployed) {
                return { deployed: true, polls, confirmedBy: last.signal, value: last.expected };
            }
        }

        if (now() + options.intervalMs > deadline) {
            return { deployed: false, polls, last, lastError };
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
    const last = outcome.last;

    if (last?.signal === 'commit') {
        detail.push(`Waiting for commit ${last.expected} (reported ${last.reported}).`);
    } else if (last?.signal === 'lock_hash') {
        detail.push(`Waiting for lock file hash ${last.expected} (reported ${last.reported}).`);
    } else if (last !== null && last.mismatches.length > 0) {
        detail.push(
            `Waiting for ${last.mismatches
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

/** How a confirmed deploy is recorded beside the run, for the reporter and the CI summary. */
export interface DeployConfirmation {
    confirmed_by: Signal;
    /** The commit or lock hash that matched; null when the versions did. */
    value: string | null;
}
