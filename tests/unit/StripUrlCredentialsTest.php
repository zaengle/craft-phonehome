<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\tests\support\ReportProbe;

/**
 * Covers redaction of credentials from URL constraints and resolved versions.
 */
class StripUrlCredentialsTest extends TestCase
{
    private ReportProbe $report;

    protected function setUp(): void
    {
        $this->report = new ReportProbe();
    }

    /**
     * @dataProvider valueProvider
     */
    public function testStripsCredentialsWithoutDamagingOtherValues(string $input, string $expected): void
    {
        self::assertSame($expected, $this->report->stripCredentials($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valueProvider(): array
    {
        return [
            'user and password' => [
                'https://user:pass@host/x.tgz',
                'https://host/x.tgz',
            ],
            'password containing an at sign' => [
                'https://user:p@ss@host/x.tgz',
                'https://host/x.tgz',
            ],
            'user with no password' => [
                'https://user@host/x.tgz',
                'https://host/x.tgz',
            ],
            'git over ssh keeps its conventional git user' => [
                'git+ssh://git@github.com/org/repo.git',
                'git+ssh://git@github.com/org/repo.git',
            ],
            'scp style git shorthand is untouched' => [
                'git@github.com:org/repo.git',
                'git@github.com:org/repo.git',
            ],
            'a plain semver constraint is untouched' => [
                '^1.6.3',
                '^1.6.3',
            ],
            'the path is not consumed by the match' => [
                'https://user:pass@host/a@b/x.tgz',
                'https://host/a@b/x.tgz',
            ],
        ];
    }

    public function testNullPassesThrough(): void
    {
        self::assertNull($this->report->stripCredentials(null));
    }
}
