<?php

namespace zaengle\phonehome\tests\unit;

use Craft;
use PHPUnit\Framework\TestCase;
use zaengle\phonehome\enums\NpmStatus;
use zaengle\phonehome\tests\support\ReportProbe;

/**
 * Covers locating package.json.
 *
 * The npm manifest is not always beside composer.json. A common Craft convention puts the CMS in a
 * subdirectory, so @root resolves one level below the repository root where the manifest and
 * lockfile actually live. Assuming @root reports such a site as having no npm dependencies, which
 * is indistinguishable from a site that genuinely has none.
 */
class NpmManifestLocationTest extends TestCase
{
    private ReportProbe $report;
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->report = new ReportProbe();
        $this->repoRoot = sys_get_temp_dir() . '/phonehome-manifest-' . uniqid();
        mkdir($this->repoRoot . '/src', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/src/nested', '/src', ''] as $dir) {
            $path = $this->repoRoot . $dir;
            if (!is_dir($path)) {
                continue;
            }
            foreach ((array)glob($path . '/*') as $file) {
                if (is_file((string)$file)) {
                    unlink((string)$file);
                }
            }
        }
        foreach (['/src/nested', '/src', ''] as $dir) {
            if (is_dir($this->repoRoot . $dir)) {
                rmdir($this->repoRoot . $dir);
            }
        }
    }

    private function writeManifest(string $dir): void
    {
        file_put_contents($dir . '/package.json', json_encode([
            'dependencies' => ['vite' => '^6.0.0'],
        ]));
    }

    private function writeLock(string $dir, string $version = '6.0.3'): void
    {
        file_put_contents($dir . '/package-lock.json', json_encode([
            'lockfileVersion' => 3,
            'packages' => ['node_modules/vite' => ['version' => $version]],
        ]));
    }

    public function testAManifestAtRootIsFoundAndReportedAsDot(): void
    {
        Craft::setAlias('@root', $this->repoRoot);
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot);

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::OK->value, $info['status']);
        self::assertSame('.', $info['manifest_path']);
        self::assertSame('npm', $info['package_manager']);
        self::assertSame('6.0.3', ((array)$info['dependencies'])['vite']['version']);
    }

    /**
     * The layout that prompted this: Craft lives in src/, so @root is one level below the manifest.
     */
    public function testAManifestOneLevelAboveRootIsFoundAndReportedAsParent(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot);

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::OK->value, $info['status']);
        self::assertSame('..', $info['manifest_path']);
        self::assertSame('npm', $info['package_manager']);
        self::assertSame('6.0.3', ((array)$info['dependencies'])['vite']['version']);
    }

    /**
     * The lockfile must come from the manifest's own directory. Pairing a manifest found by walking
     * up with a lockfile from @root would report versions from an unrelated project.
     */
    public function testTheLockfileIsResolvedFromTheManifestDirectoryNotRoot(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot, '6.0.3');
        // A decoy lockfile beside @root, which must be ignored.
        $this->writeLock($this->repoRoot . '/src', '1.1.1');

        $info = $this->report->npmInfo();

        self::assertSame('..', $info['manifest_path']);
        self::assertSame('6.0.3', ((array)$info['dependencies'])['vite']['version']);
    }

    public function testTheSearchStopsAtTheFirstManifestFound(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');
        // Manifests at both levels: the nearer one wins.
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot, '6.0.3');
        $this->writeManifest($this->repoRoot . '/src');
        $this->writeLock($this->repoRoot . '/src', '9.9.9');

        $info = $this->report->npmInfo();

        self::assertSame('.', $info['manifest_path']);
        self::assertSame('9.9.9', ((array)$info['dependencies'])['vite']['version']);
    }

    public function testNoManifestAnywhereReportsNoManifestWithANullPath(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::NO_MANIFEST->value, $info['status']);
        self::assertNull($info['manifest_path']);
        self::assertNull($info['package_manager']);
        self::assertEquals(new \stdClass(), $info['dependencies']);
    }

    public function testAConfiguredRelativeNpmPathWins(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');
        mkdir($this->repoRoot . '/src/nested');
        $this->writeManifest($this->repoRoot . '/src/nested');
        $this->writeLock($this->repoRoot . '/src/nested', '7.7.7');
        // A manifest the search would otherwise have found first.
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot, '6.0.3');

        $this->report->npmPath = 'nested';
        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::OK->value, $info['status']);
        self::assertSame('nested', $info['manifest_path']);
        self::assertSame('7.7.7', ((array)$info['dependencies'])['vite']['version']);
    }

    public function testAConfiguredAbsoluteNpmPathIsUsed(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot);

        $this->report->npmPath = $this->repoRoot;
        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::OK->value, $info['status']);
        self::assertSame('..', $info['manifest_path']);
    }

    /**
     * A configured path that holds no manifest must not silently fall back to the search, or the
     * setting would appear to work while pointing somewhere wrong.
     */
    public function testAConfiguredNpmPathWithNoManifestReportsNoManifest(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot);

        $this->report->npmPath = 'does-not-exist';
        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::NO_MANIFEST->value, $info['status']);
        self::assertNull($info['manifest_path']);
        self::assertNotEmpty($this->report->loggedErrors);
    }

    /**
     * The reported path is relative, so an absolute server path is never disclosed.
     */
    public function testTheReportedPathIsNeverAbsolute(): void
    {
        Craft::setAlias('@root', $this->repoRoot . '/src');
        $this->writeManifest($this->repoRoot);
        $this->writeLock($this->repoRoot);

        $info = $this->report->npmInfo();

        self::assertIsString($info['manifest_path']);
        self::assertStringNotContainsString($this->repoRoot, $info['manifest_path']);
        self::assertStringStartsNotWith('/', $info['manifest_path']);
    }
}
