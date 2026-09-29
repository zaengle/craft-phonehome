<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\services\Verification;

/**
 * Covers the template-coverage measure.
 *
 * Counting pages says almost nothing: five pages out of twenty thousand entries sounds like no
 * coverage and may be fine, while five pages that all render through the same template sounds like
 * coverage and is not. What predicts whether a break gets caught is whether each template the site
 * renders has at least one page watching it.
 */
class VerificationCoverageTest extends TestCase
{
    private Verification $verification;

    /** @var list<string> */
    private array $templates = ['_pages/_page.twig', 'blog/_entry.twig', 'team/_entry'];

    /** @var array<string, string> */
    private array $matched = [
        '__home__' => '_pages/_page.twig',
        'about' => '_pages/_page.twig',
        'contact' => '_pages/_page.twig',
        'blog/one' => 'blog/_entry.twig',
        'team/ana' => 'team/_entry',
    ];

    protected function setUp(): void
    {
        $this->verification = new Verification();
    }

    /**
     * The failure this measure exists to expose: a manifest that looks thorough because it names
     * several pages, while every one of them renders through the same template.
     */
    public function testSeveralPagesOnOneTemplateIsStillOneTemplateOfCoverage(): void
    {
        $summary = $this->verification->summariseCoverage($this->templates, $this->matched, ['/', '/about', '/contact']);

        $this->assertSame(3, $summary['templates_total']);
        $this->assertSame(1, $summary['templates_covered']);
        $this->assertSame(['blog/_entry.twig', 'team/_entry'], $summary['uncovered']);
    }

    public function testCoveringEveryTemplateLeavesNothingUncovered(): void
    {
        $summary = $this->verification->summariseCoverage($this->templates, $this->matched, ['/', '/blog/one', '/team/ana']);

        $this->assertSame(3, $summary['templates_covered']);
        $this->assertSame([], $summary['uncovered']);
    }

    public function testTheHomepageResolvesToCraftsOwnSpelling(): void
    {
        $summary = $this->verification->summariseCoverage($this->templates, $this->matched, ['/']);

        $this->assertSame(1, $summary['templates_covered']);
        $this->assertSame([], $summary['unmatched_paths']);
    }

    /**
     * A path that resolves to nothing in scope -- a custom route, or an element type nobody is
     * counting -- is reported rather than ignored. It cannot be credited as covering a template,
     * and silently dropping it would overstate how thorough the manifest is.
     */
    public function testPathsThatResolveToNothingAreReported(): void
    {
        $summary = $this->verification->summariseCoverage($this->templates, $this->matched, ['/', '/search']);

        $this->assertSame(['/search'], $summary['unmatched_paths']);
        $this->assertSame(1, $summary['templates_covered']);
    }

    public function testQueryStringsAndTrailingSlashesResolve(): void
    {
        $summary = $this->verification->summariseCoverage($this->templates, $this->matched, ['/about/', '/contact?utm=x']);

        $this->assertSame([], $summary['unmatched_paths']);
        $this->assertSame(1, $summary['templates_covered']);
    }

    public function testRoutesWithNoTemplateAreNotCounted(): void
    {
        $summary = $this->verification->summariseCoverage(['', 'x.twig'], [], [], 3);

        $this->assertSame(1, $summary['templates_total']);
        $this->assertSame(3, $summary['routable_uris']);
    }

    public function testTheUncoveredListIsBoundedButTheCountIsNot(): void
    {
        $templates = [];

        for ($i = 0; $i < Verification::MAX_UNCOVERED + 5; $i++) {
            $templates[] = "t$i.twig";
        }

        $summary = $this->verification->summariseCoverage($templates, [], []);

        $this->assertSame(Verification::MAX_UNCOVERED + 5, $summary['templates_total']);
        $this->assertSame(0, $summary['templates_covered']);
        $this->assertCount(Verification::MAX_UNCOVERED, $summary['uncovered']);
    }

    public function testAnEmptySiteIsNotAnError(): void
    {
        $summary = $this->verification->summariseCoverage([], [], []);

        $this->assertSame(0, $summary['templates_total']);
        $this->assertSame(0, $summary['templates_covered']);
        $this->assertSame(['entries', 'categories'], $summary['scope']);
    }
}
