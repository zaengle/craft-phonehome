<?php

namespace zaengle\phonehome\helpers;

use Closure;
use Throwable;

/**
 * Works out what the environment is running, so a deployment can be confirmed by the environment
 * itself rather than by whoever deployed it.
 *
 * Two facts are reported. The lock hash is the `content-hash` Composer writes into composer.lock,
 * which fingerprints the dependency set and needs neither git nor a host's API. The commit is the
 * deployed commit's SHA, read from the first source in a fixed order that answers: an environment
 * variable or file the site names in its config, then `PHONE_HOME_REVISION`, then a `REVISION`
 * file at the project root (which Envoyer and Capistrano-style deploys write), then `.git/HEAD`
 * when the release is a clone (which Forge produces).
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

    /**
     * @param string $root The project root, where `REVISION` and `.git` are looked for.
     * @param string $lockPath composer.lock, whose content-hash is reported.
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
            $hash = is_array($lock) ? ($lock['content-hash'] ?? null) : null;

            return is_string($hash) && preg_match('/^[0-9a-f]{32}\z/i', $hash) === 1 ? strtolower($hash) : null;
        } catch (Throwable) {
            return null;
        }
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
        $head = $this->readSmallFile('.git/HEAD');

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

        $loose = $this->readSmallFile('.git/' . $ref);

        if ($loose !== null) {
            return $loose;
        }

        $packed = is_file($this->path('.git/packed-refs')) ? file($this->path('.git/packed-refs'), FILE_IGNORE_NEW_LINES) : false;

        foreach ($packed ?: [] as $line) {
            $parts = explode(' ', trim($line), 2);

            if (count($parts) === 2 && $parts[1] === $ref) {
                return $parts[0];
            }
        }

        return null;
    }

    /** Reads a file under the root. A revision file holds one short line; anything large is not one. */
    protected function readSmallFile(string $relative): ?string
    {
        $path = $this->path($relative);

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
