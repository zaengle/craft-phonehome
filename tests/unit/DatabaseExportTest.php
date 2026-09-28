<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\tests\support\DatabaseExportProbe;

/**
 * Covers which tables are excluded from a database export.
 *
 * The failure worth designing against is silent. A configured name that matches nothing excludes
 * nothing, and a backup still carrying the data is indistinguishable from one that never had it --
 * so the resolution runs against the tables that actually exist rather than trusting the spelling
 * it was given.
 */
class DatabaseExportTest extends TestCase
{
    private DatabaseExportProbe $export;

    /** @var string[] */
    private array $existing = [
        'craft_users',
        'craft_entries',
        'craft_formie_submissions',
        'craft_formie_sentnotifications',
        'craft_freeform_submissions',
        'craft_freeform_submissions_project_1',
        'craft_freeform_submission_notes',
    ];

    protected function setUp(): void
    {
        $this->export = new DatabaseExportProbe();
    }

    /**
     * Craft's own table constants are `{{%name}}` tokens while the schema reports prefixed names.
     * Both have to resolve, or a perfectly reasonable config silently excludes nothing.
     */
    public function testAcceptsTokenPrefixedAndBareSpellings(): void
    {
        $resolved = $this->export->resolveExcludedTables(
            $this->existing,
            ['{{%users}}', 'craft_formie_submissions', 'formie_sentnotifications'],
            [],
        );

        $this->assertSame(
            ['craft_formie_sentnotifications', 'craft_formie_submissions', 'craft_users'],
            $resolved['matched'],
        );
        $this->assertSame([], $resolved['unmatched']);
    }

    public function testWildcardPatternsMatchPerFormTables(): void
    {
        $resolved = $this->export->resolveExcludedTables($this->existing, [], ['freeform_submissions*']);

        $this->assertSame(
            ['craft_freeform_submissions', 'craft_freeform_submissions_project_1'],
            $resolved['matched'],
        );
    }

    /**
     * A pattern is matched against the prefixed and bare spelling alike, so it behaves the same
     * whichever way it was written.
     */
    public function testPatternsMatchWithOrWithoutThePrefix(): void
    {
        $withPrefix = $this->export->resolveExcludedTables($this->existing, [], ['craft_formie_*']);
        $without = $this->export->resolveExcludedTables($this->existing, [], ['formie_*']);

        $this->assertSame($withPrefix, $without);
        $this->assertSame(['craft_formie_sentnotifications', 'craft_formie_submissions'], $withPrefix['matched']);
    }

    /**
     * An entry that matches nothing must come back named. Returning only the matches meant a typo
     * produced a dump that still held the data, with nothing anywhere saying so -- and this test
     * previously asserted that silence was correct.
     */
    public function testEntriesThatMatchNothingAreReported(): void
    {
        $resolved = $this->export->resolveExcludedTables($this->existing, ['{{%nope}}'], ['also_nope*']);

        $this->assertSame([], $resolved['matched']);
        $this->assertSame(['{{%nope}}', 'also_nope*'], $resolved['unmatched']);
    }

    public function testATypoInAnExactNameIsReportedWhileTheRestResolve(): void
    {
        $resolved = $this->export->resolveExcludedTables($this->existing, ['{{%user}}', '{{%users}}'], []);

        $this->assertSame(['craft_users'], $resolved['matched']);
        $this->assertSame(['{{%user}}'], $resolved['unmatched']);
    }

    public function testResultsAreDeduplicatedWhenNameAndPatternOverlap(): void
    {
        $resolved = $this->export->resolveExcludedTables(
            $this->existing,
            ['{{%freeform_submissions}}'],
            ['freeform_submissions*'],
        );

        $this->assertSame(
            ['craft_freeform_submissions', 'craft_freeform_submissions_project_1'],
            $resolved['matched'],
        );
    }

    public function testBlankEntriesAreIgnoredRatherThanMatchingEverything(): void
    {
        $resolved = $this->export->resolveExcludedTables($this->existing, ['', '   '], ['', '  ']);

        $this->assertSame([], $resolved['matched']);
    }

    public function testAnUnprefixedInstallStillResolves(): void
    {
        $this->export->prefix = '';

        $resolved = $this->export->resolveExcludedTables(['users', 'entries'], ['{{%users}}'], []);

        $this->assertSame(['users'], $resolved['matched']);
    }

    /**
     * A bare `*` is the one pattern that could empty an entire backup, so it is worth pinning that
     * it does exactly what it says rather than being quietly ignored.
     */
    public function testAWildcardOnItsOwnMatchesEverything(): void
    {
        $resolved = $this->export->resolveExcludedTables(['craft_users', 'craft_entries'], [], ['*']);

        $this->assertSame(['craft_entries', 'craft_users'], $resolved['matched']);
    }
}
