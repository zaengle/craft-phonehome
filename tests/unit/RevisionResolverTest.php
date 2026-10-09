<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\helpers\RevisionResolver;
use zaengle\phonehome\tests\support\VerificationProbe;

/**
 * The revision is what lets a deployment runner confirm a deploy from the environment itself. A
 * wrong commit here would let a comparison run against code it was not asked about, so each source,
 * their order and the guards on what counts as a commit are pinned.
 */
class RevisionResolverTest extends TestCase
{
    private const SHA = 'dca2ad2f0c6b8e1a4b3c5d7e9f0a1b2c3d4e5f60';

    private const OTHER = '1111111111111111111111111111111111111111';

    private string $root;

    /** @var array<string, string> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phonehome-revision-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
        $this->env = [];
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @return array{lock_hash: string|null, commit: string|null, commit_source: string|null}
     */
    private function resolve(?string $configuredEnv = null, ?string $configuredFile = null): array
    {
        return $this->resolveAt($this->root, $configuredEnv, $configuredFile);
    }

    /**
     * Resolves with a Craft root somewhere below the test directory, for the layouts that keep the
     * application in a subdirectory and `.git` above it.
     *
     * @return array{lock_hash: string|null, commit: string|null, commit_source: string|null}
     */
    private function resolveAt(string $root, ?string $configuredEnv = null, ?string $configuredFile = null): array
    {
        if (!is_dir($root)) {
            mkdir($root, 0777, true);
        }

        return (new RevisionResolver(
            root: $root,
            lockPath: $this->root . '/composer.lock',
            configuredEnv: $configuredEnv,
            configuredFile: $configuredFile,
            env: fn(string $name): mixed => $this->env[$name] ?? false,
        ))->resolve();
    }

    public function testNothingToReadIsAllNull(): void
    {
        $this->assertSame(['lock_hash' => null, 'commit' => null, 'commit_source' => null], $this->resolve());
    }

    /** The lock file the parity fixture is built from; the runner's tests pin the same fingerprint. */
    private const FIXTURE_LOCK = [
        'content-hash' => 'a1b2',
        'packages' => [
            ['name' => 'verbb/formie', 'version' => '3.1.43', 'source' => ['reference' => 'abc123']],
            ['name' => 'craftcms/cms', 'version' => '5.11.1', 'dist' => ['reference' => 'def456']],
        ],
        'packages-dev' => [['name' => 'craftcms/generator', 'version' => '2.1.0']],
    ];

    /** tools/site-verification/tests/unit/deployed.spec.ts expects exactly this for the same lock. */
    private const FIXTURE_FINGERPRINT = 'cf1210f2911d689f1bed30f33456f7710405cd69ed55eef06702a37ba9f56ade';

    public function testTheLockHashFingerprintsTheLockedPackages(): void
    {
        $this->write('composer.lock', json_encode(self::FIXTURE_LOCK));

        $this->assertSame(self::FIXTURE_FINGERPRINT, $this->resolve()['lock_hash']);
    }

    public function testTheLockHashMovesWhenAVersionMovesEvenThoughContentHashDoesNot(): void
    {
        // A security remediation runs `composer update package:version`, which moves locked
        // versions and leaves composer.json, and so Composer's content-hash, exactly as they were.
        $moved = self::FIXTURE_LOCK;
        $moved['packages'][0]['version'] = '3.1.44';

        $this->assertSame(self::FIXTURE_LOCK['content-hash'], $moved['content-hash']);
        $this->assertNotSame(RevisionResolver::fingerprintLock(self::FIXTURE_LOCK), RevisionResolver::fingerprintLock($moved));
    }

    public function testTheLockHashMovesWithADevBranchReference(): void
    {
        $moved = self::FIXTURE_LOCK;
        $moved['packages'][0]['source']['reference'] = 'fff999';

        $this->assertNotSame(RevisionResolver::fingerprintLock(self::FIXTURE_LOCK), RevisionResolver::fingerprintLock($moved));
    }

    public function testTheLockHashDoesNotDependOnOrderOrFormatting(): void
    {
        $reordered = self::FIXTURE_LOCK;
        $reordered['packages'] = array_reverse($reordered['packages']);
        $reordered['content-hash'] = 'something-else';

        $this->assertSame(self::FIXTURE_FINGERPRINT, RevisionResolver::fingerprintLock($reordered));
    }

    public function testAnUnreadableLockFileHasNoHash(): void
    {
        $this->write('composer.lock', '{not json');
        $this->assertNull($this->resolve()['lock_hash']);

        $this->write('composer.lock', json_encode(['content-hash' => 'a1b2', 'packages' => []]));
        $this->assertNull($this->resolve()['lock_hash']);
    }

    public function testEachSourceIsReadWhenItIsTheOnlyOne(): void
    {
        $this->env['DEPLOYED_SHA'] = self::SHA;
        $this->assertSame([self::SHA, 'env:DEPLOYED_SHA'], $this->commit($this->resolve(configuredEnv: 'DEPLOYED_SHA')));

        $this->env = [];
        $this->write('storage/release.txt', self::SHA . "\n");
        $this->assertSame([self::SHA, 'file:storage/release.txt'], $this->commit($this->resolve(configuredFile: 'storage/release.txt')));

        $this->env = ['PHONE_HOME_REVISION' => self::SHA];
        $this->assertSame([self::SHA, 'env:PHONE_HOME_REVISION'], $this->commit($this->resolve()));

        $this->env = [];
        $this->write('REVISION', self::SHA);
        $this->assertSame([self::SHA, 'file:REVISION'], $this->commit($this->resolve()));
    }

    public function testTheFirstSourceThatAnswersWins(): void
    {
        // Every source answers with something different; each is removed in turn to show the next.
        $this->env = ['DEPLOYED_SHA' => self::SHA, 'PHONE_HOME_REVISION' => self::OTHER];
        $this->write('storage/release.txt', self::OTHER);
        $this->write('REVISION', self::OTHER);
        $this->write('.git/HEAD', self::OTHER);

        $this->assertSame([self::SHA, 'env:DEPLOYED_SHA'], $this->commit($this->resolve('DEPLOYED_SHA', 'storage/release.txt')));

        $this->env = ['PHONE_HOME_REVISION' => 'aaaaaaa'];
        $this->write('storage/release.txt', 'bbbbbbb');
        $this->assertSame(['bbbbbbb', 'file:storage/release.txt'], $this->commit($this->resolve('DEPLOYED_SHA', 'storage/release.txt')));

        $this->assertSame(['aaaaaaa', 'env:PHONE_HOME_REVISION'], $this->commit($this->resolve()));

        $this->env = [];
        $this->write('REVISION', 'ccccccc');
        $this->assertSame(['ccccccc', 'file:REVISION'], $this->commit($this->resolve()));

        unlink($this->root . '/REVISION');
        $this->assertSame([self::OTHER, 'git'], $this->commit($this->resolve()));
    }

    public function testOnlyAHexStringOfSevenToFortyCharactersIsACommit(): void
    {
        foreach (['abc123', str_repeat('a', 41), 'main', 'v1.8.3', 'dca2ad2 extra', ''] as $value) {
            $this->env['PHONE_HOME_REVISION'] = $value;
            $this->assertNull($this->resolve()['commit'], var_export($value, true));
        }

        // A source that answers with something that is not a commit falls through to the next.
        $this->env['PHONE_HOME_REVISION'] = 'not-a-sha';
        $this->write('REVISION', "  DCA2AD2  \n");
        $this->assertSame(['dca2ad2', 'file:REVISION'], $this->commit($this->resolve()));
    }

    public function testASymbolicHeadIsResolvedThroughItsLooseRef(): void
    {
        $this->write('.git/HEAD', "ref: refs/heads/main\n");
        $this->write('.git/refs/heads/main', self::SHA . "\n");

        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolve()));
    }

    public function testASymbolicHeadIsResolvedThroughPackedRefs(): void
    {
        $this->write('.git/HEAD', "ref: refs/heads/main\n");
        $this->write('.git/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n" . self::OTHER . " refs/heads/develop\n" . self::SHA . " refs/heads/main\n");

        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolve()));
    }

    public function testADetachedHeadIsTheCommitItself(): void
    {
        $this->write('.git/HEAD', self::SHA . "\n");

        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolve()));
    }

    public function testAHeadNamingARefOutsideRefsIsNotFollowed(): void
    {
        $this->write('.git/HEAD', "ref: ../../etc/passwd\n");
        $this->assertNull($this->resolve()['commit']);

        $this->write('.git/HEAD', "ref: refs/heads/../../../secret\n");
        $this->assertNull($this->resolve()['commit']);
    }

    public function testAGitDirectoryAboveTheRootIsFound(): void
    {
        $this->write('.git/HEAD', self::SHA . "\n");

        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolveAt($this->root . '/src')), 'one level up');
        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolveAt($this->root . '/apps/site')), 'two levels up');
        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolveAt($this->root . '/a/b/c')), 'three levels up');
    }

    public function testAGitDirectoryMoreThanThreeLevelsUpIsIgnored(): void
    {
        $this->write('.git/HEAD', self::SHA . "\n");

        $this->assertNull($this->resolveAt($this->root . '/a/b/c/d')['commit']);
    }

    public function testTheNearestGitDirectoryWins(): void
    {
        $this->write('.git/HEAD', self::OTHER . "\n");
        $this->write('src/.git/HEAD', self::SHA . "\n");

        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolveAt($this->root . '/src')));
    }

    public function testASymbolicHeadAboveTheRootIsResolvedFromTheSameGitDirectory(): void
    {
        $this->write('.git/HEAD', "ref: refs/heads/main\n");
        $this->write('.git/packed-refs', self::SHA . " refs/heads/main\n");
        $this->write('src/.git-not-a-clone', '');

        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolveAt($this->root . '/src')));
    }

    public function testAGitFilePointingAtASeparateGitDirectoryIsFollowed(): void
    {
        $this->write('store/HEAD', self::SHA . "\n");
        $this->write('site/.git', "gitdir: ../store\n");

        $this->assertSame([self::SHA, 'git'], $this->commit($this->resolveAt($this->root . '/site/src')));
    }

    public function testAGitFilePointingOutsideTheBoundIsIgnored(): void
    {
        $elsewhere = sys_get_temp_dir() . '/phonehome-revision-elsewhere-' . bin2hex(random_bytes(6));
        mkdir($elsewhere, 0777, true);
        file_put_contents($elsewhere . '/HEAD', self::SHA . "\n");

        try {
            $this->write('a/b/site/.git', 'gitdir: ' . $elsewhere . "\n");

            $this->assertNull($this->resolveAt($this->root . '/a/b/site/src')['commit']);
        } finally {
            exec('rm -rf ' . escapeshellarg($elsewhere));
        }
    }

    public function testAGitFileWithoutAPointerIsNotAClone(): void
    {
        $this->write('.git', "something else\n");

        $this->assertNull($this->resolve()['commit']);
    }

    public function testAnUnsafeConfiguredSourceIsIgnoredRatherThanRead(): void
    {
        $this->write('REVISION', self::SHA);

        $this->assertSame([self::SHA, 'file:REVISION'], $this->commit($this->resolve(configuredEnv: 'NOT A NAME', configuredFile: '../outside')));
    }

    public function testMalformedRevisionSettingsMakeTheManifestInvalid(): void
    {
        $verification = new VerificationProbe();

        foreach ([
            ['revisionEnv' => 42],
            ['revisionEnv' => 'HAS SPACE'],
            ['revisionFile' => ['REVISION']],
            ['revisionFile' => '/var/www/REVISION'],
            ['revisionFile' => '../REVISION'],
        ] as $config) {
            $manifest = $verification->manifestForConfig($config);

            $this->assertFalse($manifest['valid'], json_encode($config));
            $this->assertNotEmpty($manifest['errors']);
        }
    }

    public function testValidRevisionSettingsAloneDoNotEnableVerification(): void
    {
        $manifest = (new VerificationProbe())->manifestForConfig(['revisionEnv' => 'DEPLOYED_SHA', 'revisionFile' => 'storage/release.txt']);

        $this->assertFalse($manifest['enabled']);
        $this->assertTrue($manifest['valid']);
        $this->assertSame([], $manifest['errors']);
    }

    /**
     * @param array{lock_hash: string|null, commit: string|null, commit_source: string|null} $revision
     * @return array{0: string|null, 1: string|null}
     */
    private function commit(array $revision): array
    {
        return [$revision['commit'], $revision['commit_source']];
    }
}
