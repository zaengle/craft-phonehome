<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\services\Verification;
use zaengle\phonehome\tests\support\VerificationProbe;

/**
 * Covers automatic page selection.
 *
 * The failure this exists to prevent is a manifest that looks thorough while leaving a template
 * unwatched. Hand-picking pages made coverage a property of whoever wrote the list, and the list
 * stopped being true as soon as content moved.
 */
class AutoCoverageTest extends TestCase
{
    private VerificationProbe $verification;

    protected function setUp(): void
    {
        $this->verification = new VerificationProbe();
        $this->verification->representatives = [
            '_pages/_page.twig' => 'about',
            'blog/_entry.twig' => 'blog/one',
            'team/_entry' => 'team/ana',
        ];
    }

    public function testOnePageIsGeneratedForEveryTemplate(): void
    {
        $pages = $this->verification->autoCover([]);

        $this->assertSame(
            ['auto-pages-page-twig', 'auto-blog-entry-twig', 'auto-team-entry'],
            array_column($pages, 'id'),
        );
        $this->assertSame(['/about', '/blog/one', '/team/ana'], array_column($pages, 'path'));
    }

    /**
     * An explicit page is the site saying "check this one, this way". A generated page for the same
     * template would duplicate the work and could contradict the selector the site chose.
     */
    public function testATemplateCoveredExplicitlyGetsNoGeneratedPage(): void
    {
        $this->verification->explicitTemplates = ['contact' => '_pages/_page.twig'];

        $pages = $this->verification->autoCover([
            ['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => 'form.fui-form']],
        ]);

        $this->assertSame(['contact', 'auto-blog-entry-twig', 'auto-team-entry'], array_column($pages, 'id'));
    }

    public function testExplicitPagesArePreservedUntouched(): void
    {
        $explicit = ['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => 'form.fui-form']];

        $pages = $this->verification->autoCover([$explicit]);

        $this->assertSame($explicit, $pages[0]);
    }

    public function testGeneratedPagesCarryTheDefaultAssertionAndMasks(): void
    {
        $pages = $this->verification->autoCover([], ['visible' => 'main'], ['.ticker']);

        $this->assertSame(['visible' => 'main'], $pages[0]['assert']);
        $this->assertSame(['.ticker'], $pages[0]['mask']);
    }

    public function testMasksAreOmittedWhenNoneAreConfigured(): void
    {
        $pages = $this->verification->autoCover([]);

        $this->assertArrayNotHasKey('mask', $pages[0]);
    }

    /**
     * A generated id colliding with an explicit one would be rejected downstream as a duplicate,
     * which would invalidate the whole manifest -- turning a naming coincidence into a site with no
     * verification at all.
     */
    public function testAGeneratedIdNeverCollidesWithAnExplicitOne(): void
    {
        $pages = $this->verification->autoCover([
            ['id' => 'auto-team-entry', 'path' => '/somewhere', 'assert' => ['visible' => 'h1']],
        ]);

        $ids = array_column($pages, 'id');

        $this->assertSame($ids, array_unique($ids));
        $this->assertContains('auto-team-entry-2', $ids);
    }

    /**
     * Generated ids must satisfy the same pattern the manifest validator enforces, or the site
     * would be rejected for names it never chose.
     */
    public function testGeneratedIdsSatisfyTheManifestIdPattern(): void
    {
        $this->verification->representatives = ['Odd/Name_WITH.chars.twig' => 'x'];

        $pages = $this->verification->autoCover([]);

        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9-]*\z/', $pages[0]['id']);
    }

    /**
     * A failed lookup must not look like a site that deliberately verifies little. Falling back to
     * the explicit pages keeps whatever the site asked for and logs the reason.
     */
    public function testAFailedSelectionFallsBackToTheExplicitPages(): void
    {
        $this->verification->failSelection = true;
        $explicit = [['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => 'h1']]];

        $this->assertSame($explicit, $this->verification->autoCover($explicit));
        $this->assertNotSame([], $this->verification->loggedErrors);
    }

    /**
     * The plugin log is not somewhere anyone looks after a clean run. Without this, a narrowed
     * manifest and an intended one are the same document, and the runner reports a pass over
     * quietly fewer pages.
     */
    public function testAFailedSelectionIsReportedAsAWarningOnTheManifest(): void
    {
        $this->verification->failSelection = true;

        $this->verification->autoCover([['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => 'h1']]]);

        $this->assertSame(
            ['Automatic page selection failed, so only the explicitly configured pages are verified.'],
            $this->verification->reportedWarnings(),
        );
    }

    /**
     * Craft stores the homepage's URI as `__home__` and then 404s a request for that literal path.
     * Generating `/__home__` would make the homepage's template permanently unbaselineable, and
     * because a generated page is often the only thing watching a template, the run would fail
     * outright rather than lose one page.
     */
    public function testTheHomepageIsGeneratedAsARootPath(): void
    {
        $this->verification->representatives = ['_pages/_home.twig' => '__home__'];

        $pages = $this->verification->autoCover([]);

        $this->assertSame('/', $pages[0]['path']);
    }

    /**
     * The homepage declared explicitly is the same page by another spelling, so it must suppress
     * the generated one rather than being watched twice under two ids.
     */
    public function testAnExplicitHomepageSuppressesTheGeneratedOne(): void
    {
        $this->verification->representatives = ['_pages/_home.twig' => '__home__'];
        $this->verification->explicitTemplates = ['__home__' => '_pages/_home.twig'];

        $pages = $this->verification->autoCover([
            ['id' => 'home', 'path' => '/', 'assert' => ['visible' => 'h1']],
        ]);

        $this->assertSame(['home'], array_column($pages, 'id'));
    }

    /**
     * The defect this exists to prevent: a section template that dispatches on entry type is one
     * file rendering several, so crediting the whole section to one entry left the pilot's homepage
     * unwatched while coverage read complete.
     */
    public function testEachEntryTypeBehindOneTemplateGetsItsOwnPage(): void
    {
        $this->verification->representatives = [
            '_pages/_page.twig#landingPage' => '__home__',
            '_pages/_page.twig#forms' => 'contact',
            '_pages/_page.twig#textPage' => 'privacy',
        ];
        $this->verification->explicitTemplates = ['contact' => '_pages/_page.twig#forms'];

        $pages = $this->verification->autoCover([
            ['id' => 'contact', 'path' => '/contact', 'assert' => ['visible' => 'form.fui-form']],
        ]);

        $this->assertSame(['/contact', '/', '/privacy'], array_column($pages, 'path'));
    }

    /**
     * Generating past the manifest's page limit fails validation, which would leave a large site
     * with no verification at all rather than partial verification. What did not fit has no page,
     * so the coverage census reports it as uncovered.
     */
    public function testGenerationStopsAtThePageLimitInsteadOfInvalidatingTheManifest(): void
    {
        $this->verification->representatives = [];

        for ($i = 0; $i < Verification::MAX_PAGES + 5; $i++) {
            $this->verification->representatives["template-$i.twig"] = "page-$i";
        }

        $pages = $this->verification->autoCover([]);

        $this->assertCount(Verification::MAX_PAGES, $pages);
    }

    /**
     * Explicit pages are the site's own choices and are never displaced by generated ones, even
     * when there is no room left.
     */
    public function testTheLimitIsSpentOnExplicitPagesFirst(): void
    {
        $explicit = [];

        for ($i = 0; $i < Verification::MAX_PAGES; $i++) {
            $explicit[] = ['id' => "chosen-$i", 'path' => "/chosen-$i", 'assert' => ['visible' => 'h1']];
        }

        $pages = $this->verification->autoCover($explicit);

        $this->assertSame($explicit, $pages);
    }

    /**
     * Category templates are counted in the coverage denominator, so leaving them out of selection
     * meant a site with a category group could not reach full coverage however its manifest was
     * written. The pilot read 9 of 10 for exactly this reason.
     */
    public function testCategoryTemplatesAreOfferedToSelectionToo(): void
    {
        $this->verification->representatives = ['blog/_entry.twig#blog' => 'blog/one'];
        $this->verification->categoryRepresentatives = ['blog/index.twig' => 'blog/tag/craft'];

        $pages = $this->verification->autoCover([]);

        $this->assertSame(['/blog/one', '/blog/tag/craft'], array_column($pages, 'path'));
    }

    /**
     * A shared template is one render target, and the entry side owns it: its representative is
     * chosen with the live-entry rules that a category has no equivalent of.
     */
    public function testAnEntryTargetKeepsATemplateACategoryGroupAlsoRenders(): void
    {
        $this->verification->representatives = ['shared.twig' => 'from-entries'];
        $this->verification->categoryRepresentatives = ['shared.twig' => 'from-categories'];

        $pages = $this->verification->autoCover([]);

        $this->assertSame(['/from-entries'], array_column($pages, 'path'));
    }

    public function testASiteWithNoRoutableTemplatesGeneratesNothing(): void
    {
        $this->verification->representatives = [];

        $this->assertSame([], $this->verification->autoCover([]));
    }

    public function testMalformedAutoCoverageOptionsProduceAnInvalidManifest(): void
    {
        foreach ([
            ['autoCoverTemplates' => 'true'],
            ['autoCoverTemplates' => true, 'pages' => 'about'],
            ['autoCoverTemplates' => true, 'defaultAssert' => 'h1'],
            ['autoCoverTemplates' => true, 'masks' => '.ticker'],
            ['autoCoverTemplates' => true, 'pages' => null],
        ] as $config) {
            $manifest = $this->verification->manifestForConfig($config);

            $this->assertTrue($manifest['enabled']);
            $this->assertFalse($manifest['valid']);
            $this->assertSame([], $manifest['pages']);
            $this->assertNotEmpty($manifest['errors']);
        }
    }

    public function testValidAutoCoverageConfigStillGeneratesAManifest(): void
    {
        $manifest = $this->verification->manifestForConfig(['autoCoverTemplates' => true, 'pages' => []]);

        $this->assertTrue($manifest['valid']);
        $this->assertCount(3, $manifest['pages']);
    }
}
