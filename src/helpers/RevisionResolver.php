<?php

namespace zaengle\phonehome\helpers;

use Closure;
use Throwable;

/**
 * Works out what the environment is running, so a deployment can be confirmed by the environment
 * itself rather than by whoever deployed it.
 *
 * Two facts are reported. The lock hash fingerprints the exact package versions composer.lock
 * pins, and needs neither git nor a host's API. It is not Composer's own `content-hash`, which
 * covers only composer.json and so does not change when an update moves locked versions without
 * touching composer.json, which is exactly what a security remediation does. The commit is the
 * deployed commit's SHA, read from the first source in a fixed order that answers: an environment
 * variable or file the site names in its config, then `PHONE_HOME_REVISION`, then a `REVISION`
 * file at the project root (which Envoyer and Capistrano-style deploys write), then `.git/HEAD`
 * when the release is a clone (which Forge produces). A common Craft layout keeps the application
 * in a subdirectory such as `src/` with `.git` above it, so `.git` is looked for at the root and
 * then in each parent up to three levels above it.
 *
 * Nothing here may throw or slow the report. Every failure is a null for that field, and `.git` is
 * read as files rather than by running git.
 */
class RevisionResolver
{
    /** The environment variable read when the site names no source of its own. */
    public const DEFAULT_ENV = 'PHONE_HOME_REVISION';

    /** The file read at the project root when no earlier source answers. */
    public const DEFAULT_FILE = 'REVISION';

    /** How many directories above the root `.git` is looked for in, after the root itself. */
    public const MAX_GIT_LEVELS_UP = 3;

    /** How much of `packed-refs` is searched before giving up on finding a branch in it. */
    public const MAX_PACKED_REFS_BYTES = 5 * 1024 * 1024;

    /**
     * @param string $root The project root, where `REVISION` and `.git` are looked for.
     * @param string $lockPath composer.lock, whose locked packages are fingerprinted.
     * @param string|null $configuredEnv An environment variable the site names, read first.
     * @param string|null $configuredFile A path relative to the root the site names, read second.
     * @param Closure(string): mixed $env Reads an environment variable, so tests need not set real ones.
     */
    public function __construct(
        private readonly string $root,
        private readonly string $lockPath,
        private readonly ?string $configuredEnv,
        private readonly ?string $configuredFile,
        private readonly Closure $env,
    ) {
    }

    /**
     * @return array{lock_hash: string|null, commit: string|null, commit_source: string|null}
     */
    public function resolve(): array
    {
        [$commit, $source] = $this->commit();

        return [
            'lock_hash' => $this->lockHash(),
            'commit' => $commit,
            'commit_source' => $source,
        ];
    }

    /**
     * Whether a site-named file path stays inside the project root. Absolute paths, `..` and
     * control characters are refused, so the setting cannot be used to read an arbitrary file.
     */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }

        return !in_array('..', preg_split('#[/\\\\]#', $path) ?: [], true);
    }

    /** Whether a site-named environment variable name is one a shell could set. */
    public static function isValidEnvName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*\z/', $name) === 1;
    }

    protected function lockHash(): ?string
    {
        try {
            if (!is_file($this->lockPath)) {
                return null;
            }

            $lock = json_decode((string)file_get_contents($this->lockPath), true);

            return is_array($lock) ? self::fingerprintLock($lock) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A SHA-256 of every locked package, as one `name version reference` line each, sorted.
     *
     * The deployment runner computes the same fingerprint from the lock file it expects
     * (`fingerprintLock` in tools/site-verification/src/deployed.ts), so the two must stay in step.
     * The reference is the source or dist commit, which is what moves when a `dev-` branch
     * version is updated without its version string changing. Null when nothing is locked.
     *
     * @param array<mixed> $lock
     */
    public static function fingerprintLock(array $lock): ?string
    {
        $lines = [];

        foreach (['packages', 'packages-dev'] as $key) {
            foreach (is_array($lock[$key] ?? null) ? $lock[$key] : [] as $package) {
                if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
                    continue;
                }

                $reference = $package['source']['reference'] ?? $package['dist']['reference'] ?? '';
                $lines[] = $package['name'] . ' ' . $package['version'] . ' ' . (is_string($reference) ? $reference : '');
            }
        }

        if ($lines === []) {
            return null;
        }

        sort($lines, SORT_STRING);

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * @return array{0: string|null, 1: string|null} The commit and the source that supplied it.
     */
    protected function commit(): array
    {
        $sources = [];

        if ($this->configuredEnv !== null && self::isValidEnvName($this->configuredEnv)) {
            $sources[] = ['env:' . $this->configuredEnv, fn() => $this->readEnv($this->configuredEnv)];
        }

        if ($this->configuredFile !== null && self::isSafeRelativePath($this->configuredFile)) {
            $sources[] = ['file:' . $this->configuredFile, fn() => $this->readSmallFile($this->configuredFile)];
        }

        $sources[] = ['env:' . self::DEFAULT_ENV, fn() => $this->readEnv(self::DEFAULT_ENV)];
        $sources[] = ['file:' . self::DEFAULT_FILE, fn() => $this->readSmallFile(self::DEFAULT_FILE)];
        $sources[] = ['git', fn() => $this->readGitHead()];

        foreach ($sources as [$label, $read]) {
            try {
                $sha = self::sha($read());
            } catch (Throwable) {
                $sha = null;
            }

            if ($sha !== null) {
                return [$sha, $label];
            }
        }

        return [null, null];
    }

    /** A 7-to-40 character hex string, lowercased, or null for anything else. */
    protected static function sha(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[0-9a-f]{7,40}\z/i', $value) === 1 ? strtolower($value) : null;
    }

    protected function readEnv(string $name): ?string
    {
        $value = ($this->env)($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Resolves `.git/HEAD` without running git. A detached HEAD holds the SHA itself; a symbolic
     * one names a branch, whose SHA is in its loose ref or, after `git gc`, in `packed-refs`.
     */
    protected function readGitHead(): ?string
    {
        $gitDir = $this->gitDir();

        if ($gitDir === null) {
            return null;
        }

        $head = $this->readSmallFileAt($gitDir . DIRECTORY_SEPARATOR . 'HEAD');

        if ($head === null) {
            return null;
        }

        $head = trim($head);

        if (!str_starts_with($head, 'ref: ')) {
            return $head;
        }

        $ref = trim(substr($head, 5));

        if (!str_starts_with($ref, 'refs/') || !self::isSafeRelativePath($ref)) {
            return null;
        }

        $loose = $this->readSmallFileAt($gitDir . DIRECTORY_SEPARATOR . $ref);

        if ($loose !== null) {
            return $loose;
        }

        return $this->readPackedRef($gitDir, $ref);
    }

    /**
     * The git directory this release was checked out from, or null when there is none in reach.
     *
     * `.git` is looked for at the root first, then in each parent up to MAX_GIT_LEVELS_UP above
     * it, stopping at the filesystem root. The first found wins. A `.git` that is a file rather
     * than a directory is what a worktree or a submodule checkout has; it holds one line,
     * `gitdir: <path>`, and that path is followed, but only to a directory within the same bound,
     * so a pointer cannot lead the report anywhere a clone above the root could not.
     */
    protected function gitDir(): ?string
    {
        $dir = realpath($this->root);

        if ($dir === false) {
            return null;
        }

        $top = $dir;
        $levels = [$dir];

        for ($level = 0; $level < self::MAX_GIT_LEVELS_UP; $level++) {
            $parent = dirname($top);

            if ($parent === $top) {
                break;
            }

            $top = $parent;
            $levels[] = $parent;
        }

        foreach ($levels as $candidate) {
            $git = $candidate . DIRECTORY_SEPARATOR . '.git';

            if (is_dir($git)) {
                return $git;
            }

            if (is_file($git)) {
                return $this->followGitDirPointer($git, $candidate, $top);
            }
        }

        return null;
    }

    /** Follows a `gitdir: <path>` pointer, relative to the file's directory, within the bound. */
    protected function followGitDirPointer(string $file, string $base, string $top): ?string
    {
        $contents = $this->readSmallFileAt($file);

        if ($contents === null || !str_starts_with(trim($contents), 'gitdir:')) {
            return null;
        }

        $target = trim(substr(trim($contents), 7));

        if ($target === '') {
            return null;
        }

        if (!str_starts_with($target, '/') && !preg_match('~^[A-Za-z]:[\\\\/]~', $target)) {
            $target = $base . DIRECTORY_SEPARATOR . $target;
        }

        $resolved = realpath($target);

        if ($resolved === false || !is_dir($resolved)) {
            return null;
        }

        $bound = rtrim($top, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if ($resolved !== rtrim($top, DIRECTORY_SEPARATOR) && !str_starts_with($resolved . DIRECTORY_SEPARATOR, $bound)) {
            return null;
        }

        return $resolved;
    }

    /**
     * Finds one ref in `packed-refs`, line by line. A long-lived clone with many tags can have a
     * packed-refs file of several megabytes, so it is streamed rather than loaded, and reading
     * stops after a fixed amount rather than slowing the report.
     */
    protected function readPackedRef(string $gitDir, string $ref): ?string
    {
        $path = $gitDir . DIRECTORY_SEPARATOR . 'packed-refs';
        $handle = is_file($path) ? fopen($path, 'rb') : false;

        if ($handle === false) {
            return null;
        }

        try {
            $read = 0;

            while ($read < self::MAX_PACKED_REFS_BYTES && ($line = fgets($handle, 4096)) !== false) {
                $read += strlen($line);
                $parts = explode(' ', trim($line), 2);

                if (count($parts) === 2 && $parts[1] === $ref) {
                    return $parts[0];
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /** Reads a file under the root. A revision file holds one short line; anything large is not one. */
    protected function readSmallFile(string $relative): ?string
    {
        return $this->readSmallFileAt($this->path($relative));
    }

    /** Reads a file by its full path, under the same size bound as readSmallFile(). */
    protected function readSmallFileAt(string $path): ?string
    {
        if (!is_file($path) || filesize($path) > 1024) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    protected function path(string $relative): string
    {
        return rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR . $relative;
    }
}
