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
        return (new RevisionResolver(
            root: $this->root,
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

    public function testTheLockHashIsComposersContentHash(): void
    {
        $this->write('composer.lock', json_encode(['content-hash' => 'A1B2C3D4E5F60718293A4B5C6D7E8F90', 'packages' => []]));

        $this->assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90', $this->resolve()['lock_hash']);
    }

    public function testAnUnreadableLockFileHasNoHash(): void
    {
        $this->write('composer.lock', '{not json');
        $this->assertNull($this->resolve()['lock_hash']);

        $this->write('composer.lock', json_encode(['content-hash' => 'not-a-hash']));
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
