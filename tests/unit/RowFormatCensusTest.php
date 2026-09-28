<?php

namespace zaengle\phonehome\tests\unit;

use PHPUnit\Framework\TestCase;
use zaengle\phonehome\services\Report;
use zaengle\phonehome\tests\support\ReportProbe;

/**
 * Covers the InnoDB row-format census.
 *
 * A table left in COMPACT or REDUNDANT carries the 8126-byte row-size limit, so a Craft migration
 * that adds a column can fail against it. The failure surfaces part-way through an upgrade, with
 * the database already half-migrated, which makes it exactly the kind of thing worth knowing about
 * before the upgrade rather than during it.
 */
class RowFormatCensusTest extends TestCase
{
    private ReportProbe $report;

    protected function setUp(): void
    {
        $this->report = new ReportProbe();
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private function summarise(array $rows): array
    {
        return $this->report->summariseRowFormats($rows);
    }

    private function row(string $table, ?string $format): array
    {
        return ['tableName' => $table, 'rowFormat' => $format];
    }

    public function testCountsAtRiskTablesAndTalliesEveryFormat(): void
    {
        $summary = $this->summarise([
            $this->row('craft_entrytypes', 'Compact'),
            $this->row('craft_elements', 'Dynamic'),
            $this->row('craft_content', 'Dynamic'),
            $this->row('craft_assets', 'Redundant'),
            $this->row('craft_searchindex', 'Compressed'),
        ]);

        $this->assertSame(2, $summary['at_risk']);
        $this->assertSame(0, $summary['unknown']);
        $this->assertSame(5, $summary['total']);
        $this->assertSame(['Compact' => 1, 'Compressed' => 1, 'Dynamic' => 2, 'Redundant' => 1], $summary['formats']);
        $this->assertSame(['craft_assets', 'craft_entrytypes'], $summary['tables']);
    }

    /**
     * COMPRESSED stores long values off-page like DYNAMIC does, so it is not subject to the limit
     * this census exists to find. Counting it would send every operator chasing a non-problem.
     */
    public function testCompressedAndDynamicAreNotAtRisk(): void
    {
        $summary = $this->summarise([
            $this->row('a', 'Dynamic'),
            $this->row('b', 'Compressed'),
        ]);

        $this->assertSame(0, $summary['at_risk']);
        $this->assertSame([], $summary['tables']);
    }

    public function testAHealthyDatabaseReportsZeroRatherThanNothing(): void
    {
        $summary = $this->summarise([$this->row('a', 'Dynamic')]);

        $this->assertSame(0, $summary['at_risk']);
        $this->assertSame(1, $summary['total']);
    }

    public function testEmptyInputIsNotAnError(): void
    {
        $summary = $this->summarise([]);

        $this->assertSame(0, $summary['at_risk']);
        $this->assertSame(0, $summary['total']);
        $this->assertSame([], $summary['formats']);
    }

    /**
     * An unreadable row still has to register. Counting at-risk tables from the collected names
     * instead of the format tally would let a null table name quietly lower the number.
     */
    public function testARowWithNoReadableNameStillCounts(): void
    {
        $summary = $this->summarise([
            ['tableName' => null, 'rowFormat' => 'Compact'],
            $this->row('craft_entrytypes', 'Compact'),
        ]);

        $this->assertSame(2, $summary['at_risk']);
        $this->assertSame(['craft_entrytypes'], $summary['tables']);
    }

    public function testAnUnknownFormatIsLabelledRatherThanDropped(): void
    {
        $summary = $this->summarise([
            ['tableName' => 'a', 'rowFormat' => null],
            ['tableName' => 'b', 'rowFormat' => ''],
        ]);

        $this->assertSame(['Unknown' => 2], $summary['formats']);
        $this->assertSame(0, $summary['at_risk']);
        $this->assertSame(2, $summary['total']);
        // Surfaced as its own number. A census that read nothing is not a census showing nothing
        // at risk, and with only at_risk to go on the two are indistinguishable.
        $this->assertSame(2, $summary['unknown']);
    }

    /**
     * The name list is bounded so a large legacy database cannot bloat every report it sends, but
     * the count must stay exact -- a truncated number would understate the problem.
     */
    public function testTableListIsBoundedButTheCountIsNot(): void
    {
        $rows = [];

        for ($i = 0; $i < Report::MAX_ROW_FORMAT_TABLES + 10; $i++) {
            $rows[] = $this->row(sprintf('craft_table_%03d', $i), 'Compact');
        }

        $summary = $this->summarise($rows);

        $this->assertSame(Report::MAX_ROW_FORMAT_TABLES + 10, $summary['at_risk']);
        $this->assertCount(Report::MAX_ROW_FORMAT_TABLES, $summary['tables']);
    }
}
