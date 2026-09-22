<?php

namespace zaengle\phonehome\tests\unit;

use Craft;
use PHPUnit\Framework\TestCase;
use zaengle\phonehome\enums\NpmStatus;
use zaengle\phonehome\tests\support\ReportProbe;

/**
 * Covers the collection status reported for each state the project root can be in. The section must
 * always be an object so that a consumer can tell a site with no npm dependencies apart from a site
 * whose dependencies could not be read.
 */
class NpmInfoStatusTest extends TestCase
{
    private ReportProbe $report;
    private string $root;

    protected function setUp(): void
    {
        $this->report = new ReportProbe();
        $this->root = sys_get_temp_dir() . '/phonehome-npm-' . uniqid();
        mkdir($this->root);
        Craft::setAlias('@root', $this->root);
    }

    protected function tearDown(): void
    {
        foreach ((array)glob($this->root . '/*') as $file) {
            unlink((string)$file);
        }
        rmdir($this->root);
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->root . '/' . $name, $contents);
    }

    private function writeManifest(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => ['vite' => '^6.0.0'],
            'devDependencies' => ['vitest' => '^2.0.0'],
        ]));
    }

    public function testNoManifestReportsNoManifestWithEmptyMaps(): void
    {
        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::NO_MANIFEST->value, $info['status']);
        self::assertNull($info['package_manager']);
        self::assertNull($info['lock_updated']);
        self::assertEquals(new \stdClass(), $info['dependencies']);
        self::assertEquals(new \stdClass(), $info['dev_dependencies']);
    }

    public function testAnUnparseableManifestReportsUnreadableManifest(): void
    {
        $this->write('package.json', '{ this is not json');

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::UNREADABLE_MANIFEST->value, $info['status']);
        self::assertNull($info['package_manager']);
        self::assertNotEmpty($this->report->loggedErrors);
    }

    public function testAManifestWithNoLockfileReportsNoLockfileAndStillListsPackages(): void
    {
        $this->writeManifest();

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::NO_LOCKFILE->value, $info['status']);
        self::assertNull($info['package_manager']);
        self::assertNull($info['lock_updated']);
        self::assertSame(
            ['vite' => ['constraint' => '^6.0.0', 'version' => null]],
            (array)$info['dependencies'],
        );
    }

    public function testAYarnLockfileIsNamedButNotParsed(): void
    {
        $this->writeManifest();
        $this->write('yarn.lock', "# yarn lockfile v1\n");

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::UNSUPPORTED_LOCKFILE->value, $info['status']);
        self::assertSame('yarn', $info['package_manager']);
        self::assertNotNull($info['lock_updated']);
        self::assertSame(
            ['vite' => ['constraint' => '^6.0.0', 'version' => null]],
            (array)$info['dependencies'],
        );
    }

    public function testAPnpmLockfileIsNamedButNotParsed(): void
    {
        $this->writeManifest();
        $this->write('pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::UNSUPPORTED_LOCKFILE->value, $info['status']);
        self::assertSame('pnpm', $info['package_manager']);
        self::assertSame(
            ['vitest' => ['constraint' => '^2.0.0', 'version' => null]],
            (array)$info['dev_dependencies'],
        );
    }

    public function testAnUnparseableNpmLockfileReportsUnreadableLockfile(): void
    {
        $this->writeManifest();
        $this->write('package-lock.json', '{ this is not json');

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::UNREADABLE_LOCKFILE->value, $info['status']);
        self::assertSame('npm', $info['package_manager']);
        self::assertSame(
            ['vite' => ['constraint' => '^6.0.0', 'version' => null]],
            (array)$info['dependencies'],
        );
        self::assertNotEmpty($this->report->loggedErrors);
    }

    public function testAReadableManifestAndLockfileReportOk(): void
    {
        $this->writeManifest();
        $this->write('package-lock.json', json_encode([
            'lockfileVersion' => 3,
            'packages' => [
                'node_modules/vite' => ['version' => '6.0.3'],
                'node_modules/vitest' => ['version' => '2.1.1'],
            ],
        ]));

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::OK->value, $info['status']);
        self::assertSame('npm', $info['package_manager']);
        self::assertNotNull($info['lock_updated']);
        self::assertSame(['vite' => ['constraint' => '^6.0.0', 'version' => '6.0.3']], (array)$info['dependencies']);
        self::assertSame(['vitest' => ['constraint' => '^2.0.0', 'version' => '2.1.1']], (array)$info['dev_dependencies']);
        self::assertEmpty($this->report->loggedErrors);
    }

    /**
     * A project can carry more than one lockfile. package-lock.json is the one that is parsed.
     */
    public function testAnNpmLockfileTakesPrecedenceOverAYarnLockfile(): void
    {
        $this->writeManifest();
        $this->write('yarn.lock', "# yarn lockfile v1\n");
        $this->write('package-lock.json', json_encode([
            'packages' => ['node_modules/vite' => ['version' => '6.0.3']],
        ]));

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::OK->value, $info['status']);
        self::assertSame('npm', $info['package_manager']);
    }

    /**
     * A malformed dependency map must cost only that map, not the whole section.
     */
    public function testAMalformedDependencyMapDoesNotLoseTheSection(): void
    {
        $this->write('package.json', json_encode([
            'dependencies' => 'not a map',
            'devDependencies' => ['vitest' => '^2.0.0'],
        ]));

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::NO_LOCKFILE->value, $info['status']);
        self::assertEquals(new \stdClass(), $info['dependencies']);
        self::assertSame(
            ['vitest' => ['constraint' => '^2.0.0', 'version' => null]],
            (array)$info['dev_dependencies'],
        );
    }

    /**
     * An unresolvable project root is a manifest-phase failure, not a lockfile one. Craft::getAlias
     * throws rather than returning false, so this exercises the manifest catch.
     */
    public function testAnUnresolvableProjectRootIsReportedAsAnUnreadableManifest(): void
    {
        Craft::setAlias('@root', null);

        $info = $this->report->npmInfo();

        self::assertSame(NpmStatus::UNREADABLE_MANIFEST->value, $info['status']);
        self::assertNotEmpty($this->report->loggedErrors);
        self::assertStringContainsString('manifest', $this->report->loggedErrors[0]);
    }

    /**
     * Empty maps must serialise as {} rather than [], so the consumer sees one shape.
     */
    public function testEmptyMapsSerialiseAsObjects(): void
    {
        $json = json_encode($this->report->npmInfo());

        self::assertStringContainsString('"dependencies":{}', (string)$json);
        self::assertStringContainsString('"dev_dependencies":{}', (string)$json);
    }
}
