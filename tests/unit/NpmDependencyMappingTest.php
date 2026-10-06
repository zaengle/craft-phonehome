<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\tests\support\ReportProbe;

/**
 * Covers the mapping of declared packages onto resolved lockfile versions, across both npm lockfile
 * layouts: v1 uses a top-level `dependencies` map, while v2 and v3 use `packages` keyed by
 * `node_modules/<name>`.
 */
class NpmDependencyMappingTest extends TestCase
{
    private ReportProbe $report;

    protected function setUp(): void
    {
        $this->report = new ReportProbe();
    }

    /**
     * @return array<mixed>
     */
    private function lock(string $name): array
    {
        $contents = file_get_contents(__DIR__ . '/../fixtures/' . $name);
        self::assertIsString($contents, "Unable to read the $name fixture.");

        return json_decode($contents, true);
    }

    public function testResolvesVersionsFromAV1Lockfile(): void
    {
        $result = $this->report->mapDependencies(
            ['vite' => '^6.0.0', 'vitest' => '^2.0.0'],
            $this->lock('package-lock-v1.json'),
        );

        self::assertSame([
            'vite' => ['constraint' => '^6.0.0', 'version' => '6.0.3'],
            'vitest' => ['constraint' => '^2.0.0', 'version' => '2.1.1'],
        ], $result);
    }

    public function testResolvesVersionsFromAV3Lockfile(): void
    {
        $result = $this->report->mapDependencies(
            ['vite' => '^6.0.0', 'vitest' => '^2.0.0'],
            $this->lock('package-lock-v3.json'),
        );

        self::assertSame([
            'vite' => ['constraint' => '^6.0.0', 'version' => '6.0.3'],
            'vitest' => ['constraint' => '^2.0.0', 'version' => '2.1.1'],
        ], $result);
    }

    public function testResolvesScopedPackagesFromAV1Lockfile(): void
    {
        $result = $this->report->mapDependencies(
            ['@vitejs/plugin-vue' => '^5.2.0'],
            $this->lock('package-lock-v1.json'),
        );

        self::assertSame(['@vitejs/plugin-vue' => ['constraint' => '^5.2.0', 'version' => '5.2.1']], $result);
    }

    public function testResolvesScopedPackagesFromAV3Lockfile(): void
    {
        $result = $this->report->mapDependencies(
            ['@vitejs/plugin-vue' => '^5.2.0'],
            $this->lock('package-lock-v3.json'),
        );

        self::assertSame(['@vitejs/plugin-vue' => ['constraint' => '^5.2.0', 'version' => '5.2.1']], $result);
    }

    /**
     * Both fixtures carry a transitive copy of vite at 5.4.0 nested under vitest. The declared
     * top-level vite must still resolve to 6.0.3.
     */
    public function testANestedTransitiveCopyDoesNotClobberATopLevelPackage(): void
    {
        foreach (['package-lock-v1.json', 'package-lock-v3.json'] as $fixture) {
            $result = $this->report->mapDependencies(['vite' => '^6.0.0'], $this->lock($fixture));

            self::assertSame('6.0.3', $result['vite']['version'], "Wrong version resolved from $fixture.");
        }
    }

    public function testADeclaredPackageMissingFromTheLockfileReportsANullVersion(): void
    {
        $result = $this->report->mapDependencies(
            ['not-installed' => '^1.0.0'],
            $this->lock('package-lock-v3.json'),
        );

        self::assertSame(['not-installed' => ['constraint' => '^1.0.0', 'version' => null]], $result);
    }

    public function testEveryVersionIsNullWhenThereIsNoLockfileToResolveAgainst(): void
    {
        $result = $this->report->mapDependencies(['vite' => '^6.0.0', 'vitest' => '^2.0.0'], null);

        self::assertSame([
            'vite' => ['constraint' => '^6.0.0', 'version' => null],
            'vitest' => ['constraint' => '^2.0.0', 'version' => null],
        ], $result);
    }

    /**
     * A malformed entry must cost only that package, not the whole section.
     */
    public function testAMalformedPackageIsSkippedAndTheRestAreKept(): void
    {
        $result = $this->report->mapDependencies(
            ['vite' => '^6.0.0', 'broken' => ['not' => 'a string'], 'vitest' => '^2.0.0'],
            $this->lock('package-lock-v3.json'),
        );

        self::assertSame([
            'vite' => ['constraint' => '^6.0.0', 'version' => '6.0.3'],
            'vitest' => ['constraint' => '^2.0.0', 'version' => '2.1.1'],
        ], $result);
        self::assertCount(1, $this->report->loggedErrors);
        self::assertStringContainsString('broken', $this->report->loggedErrors[0]);
    }

    /**
     * A lockfile entry whose version is not a string must not throw, it must report null.
     */
    public function testAMalformedLockfileVersionReportsNull(): void
    {
        $result = $this->report->mapDependencies(
            ['vite' => '^6.0.0'],
            ['packages' => ['node_modules/vite' => ['version' => ['6.0.3']]]],
        );

        self::assertSame(['vite' => ['constraint' => '^6.0.0', 'version' => null]], $result);
    }

    public function testCredentialsAreStrippedFromBothConstraintAndResolvedVersion(): void
    {
        $result = $this->report->mapDependencies(
            ['private-pkg' => 'https://user:pass@example.com/private-pkg.tgz'],
            ['packages' => ['node_modules/private-pkg' => ['version' => 'https://user:pass@example.com/private-pkg.tgz']]],
        );

        self::assertSame([
            'private-pkg' => [
                'constraint' => 'https://example.com/private-pkg.tgz',
                'version' => 'https://example.com/private-pkg.tgz',
            ],
        ], $result);
    }
}
