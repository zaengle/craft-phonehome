<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\services\Verification;

/**
 * Covers normalisation of the opt-in verification manifest.
 *
 * The manifest is the only part of the deployment-verification contract a site controls, and a
 * runner trusts it enough to navigate where it points. Two properties matter more than the rest:
 * an invalid definition must never produce a runnable page list, because reduced coverage that
 * still passes is worse than an outright failure; and a path must never be able to send the runner
 * to another origin.
 */
class VerificationManifestTest extends TestCase
{
    private Verification $verification;

    protected function setUp(): void
    {
        $this->verification = new Verification();
    }

    public function testEmptyConfigReportsDisabledRatherThanAnEmptySuite(): void
    {
        $manifest = $this->verification->normalize([]);

        $this->assertTrue($manifest['supported']);
        $this->assertFalse($manifest['enabled']);
        $this->assertTrue($manifest['valid']);
        $this->assertSame([], $manifest['pages']);
        $this->assertSame([], $manifest['errors']);
    }

    public function testValidPagesAreNormalised(): void
    {
        $manifest = $this->verification->normalize([
            'pages' => [
                ['id' => 'home', 'path' => '/', 'assert' => ['visible' => '  main  ']],
                ['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => '[data-testid="form"]']],
            ],
        ]);

        $this->assertTrue($manifest['enabled']);
        $this->assertTrue($manifest['valid']);
        $this->assertSame(Verification::SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertSame([
            ['id' => 'home', 'path' => '/', 'assert' => ['visible' => 'main']],
            ['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => '[data-testid="form"]']],
        ], $manifest['pages']);
    }

    /**
     * One bad page invalidates the whole manifest. Returning the remaining pages would let a
     * mistyped definition quietly shrink the suite while every executed check still passed.
     */
    public function testOneInvalidPageInvalidatesTheWholeManifest(): void
    {
        $manifest = $this->verification->normalize([
            'pages' => [
                ['id' => 'home', 'path' => '/', 'assert' => ['visible' => 'main']],
                ['id' => 'contact', 'path' => '/contact'],
            ],
        ]);

        $this->assertTrue($manifest['enabled']);
        $this->assertFalse($manifest['valid']);
        $this->assertSame([], $manifest['pages']);
        $this->assertNotSame([], $manifest['errors']);
    }

    /**
     * @dataProvider offSitePaths
     */
    public function testPathsThatLeaveTheSiteAreRejected(string $path): void
    {
        $manifest = $this->verification->normalize([
            'pages' => [
                ['id' => 'away', 'path' => $path, 'assert' => ['visible' => 'main']],
            ],
        ]);

        $this->assertFalse($manifest['valid']);
        $this->assertSame([], $manifest['pages']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function offSitePaths(): array
    {
        return [
            'absolute url' => ['https://example.com/'],
            'protocol relative' => ['//example.com/'],
            'scheme inside path' => ['/redirect?to=https://example.com'],
            'backslash' => ['/\\example.com'],
            'parent traversal' => ['/a/../../etc'],
            'relative' => ['contact'],
            'empty' => [''],
            // A browser strips these and re-reads what is left, turning each of them into
            // //evil.example.com -- a protocol-relative URL pointing at another host.
            'newline between slashes' => ["/\n/evil.example.com"],
            'tab between slashes' => ["/\t/evil.example.com"],
            'carriage return between slashes' => ["/\r/evil.example.com"],
            'null byte' => ["/ok\0/evil.example.com"],
        ];
    }

    public function testDuplicateIdsAreRejected(): void
    {
        $manifest = $this->verification->normalize([
            'pages' => [
                ['id' => 'home', 'path' => '/', 'assert' => ['visible' => 'main']],
                ['id' => 'home', 'path' => '/other', 'assert' => ['visible' => 'main']],
            ],
        ]);

        $this->assertFalse($manifest['valid']);
        $this->assertContains('Page id "home" is used more than once.', $manifest['errors']);
    }

    /**
     * A key the contract does not recognise is a typo, not an extension point. Ignoring it is how
     * `assertions` in place of `assert` becomes a page with nothing asserted.
     */
    public function testUnknownKeysAreRejected(): void
    {
        $manifest = $this->verification->normalize([
            'pages' => [
                ['id' => 'home', 'path' => '/', 'assert' => ['visible' => 'main'], 'assertions' => []],
            ],
        ]);

        $this->assertFalse($manifest['valid']);
        $this->assertContains('Page 0 has an unknown key "assertions".', $manifest['errors']);

        $topLevel = $this->verification->normalize(['page' => []]);

        $this->assertFalse($topLevel['valid']);
        $this->assertContains('Unknown verification key "page".', $topLevel['errors']);
    }

    public function testBlankSelectorIsRejected(): void
    {
        $manifest = $this->verification->normalize([
            'pages' => [
                ['id' => 'home', 'path' => '/', 'assert' => ['visible' => '   ']],
            ],
        ]);

        $this->assertFalse($manifest['valid']);
        $this->assertSame([], $manifest['pages']);
    }

    public function testPageCountIsBounded(): void
    {
        $pages = [];

        for ($i = 0; $i <= Verification::MAX_PAGES; $i++) {
            $pages[] = ['id' => 'page-' . $i, 'path' => '/p' . $i, 'assert' => ['visible' => 'main']];
        }

        $manifest = $this->verification->normalize(['pages' => $pages]);

        $this->assertFalse($manifest['valid']);
        $this->assertSame([], $manifest['pages']);
    }

    public function testMalformedPagesListIsRejected(): void
    {
        foreach ([['pages' => 'home'], ['pages' => []], ['pages' => ['home' => []]]] as $raw) {
            $manifest = $this->verification->normalize($raw);

            $this->assertFalse($manifest['valid']);
            $this->assertSame([], $manifest['pages']);
        }
    }
}
