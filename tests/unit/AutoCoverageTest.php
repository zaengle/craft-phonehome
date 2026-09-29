<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
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

    public function testASiteWithNoRoutableTemplatesGeneratesNothing(): void
    {
        $this->verification->representatives = [];

        $this->assertSame([], $this->verification->autoCover([]));
    }
}
