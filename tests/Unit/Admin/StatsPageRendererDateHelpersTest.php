<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Piwigo\Admin\StatsPageRenderer;
use Piwigo\History\Projection\HistorySummaryRow;

/**
 * StatsPageRenderer::getDateObject()/setMissingValues() are pure functions,
 * no DB/globals needed. Both take a real HistorySummaryRow now (not a raw
 * array) -- year/month/day/hour/nbPages are already narrowed to their real
 * types by HistorySummaryRow::fromRow() at the repository boundary (see
 * HistorySummaryRowTest for that coercion's own coverage), so the
 * non-numeric/malformed-input cases this file used to cover here no longer
 * exist as reachable states for these two methods.
 */
final class StatsPageRendererDateHelpersTest extends TestCase
{
    public function testGetDateObjectYearOnlyRowDefaultsToFirstOfYear(): void
    {
        $date = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: null, day: null, hour: null, nbPages: 0));

        self::assertSame('2026-01-01', $date->format('Y-m-d'));
    }

    public function testGetDateObjectYearMonthRowDefaultsToFirstOfMonth(): void
    {
        $date = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: 3, day: null, hour: null, nbPages: 0));

        self::assertSame('2026-03-01', $date->format('Y-m-d'));
    }

    public function testGetDateObjectYearMonthDayRowHasNoTimeComponent(): void
    {
        $date = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: 3, day: 17, hour: null, nbPages: 0));

        self::assertSame('2026-03-17 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testGetDateObjectFullRowIncludesTheHour(): void
    {
        $date = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: 3, day: 17, hour: 14, nbPages: 0));

        self::assertSame('2026-03-17 14:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testGetDateObjectTreatsHourZeroAsPresentNotAbsent(): void
    {
        // hour 0 (midnight) must be read as a real hour value, not
        // treated the same as a NULL hour.
        $date = StatsPageRenderer::getDateObject(new HistorySummaryRow(year: 2026, month: 3, day: 17, hour: 0, nbPages: 0));

        self::assertSame('2026-03-17 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testSetMissingValuesZeroFillsGapsAndOverlaysRealRows(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                new HistorySummaryRow(year: 2026, month: 1, day: 3, hour: null, nbPages: 7),
            ],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-03')
        );

        self::assertSame(
            [
                '2026-01-01' => 0,
                '2026-01-02' => 0,
                '2026-01-03' => 7,
            ],
            $result
        );
    }

    public function testSetMissingValuesWithoutExplicitDatesUsesFirstAndLastRowOfData(): void
    {
        // $data is expected DESC-ordered (matching getLast()'s own ORDER BY):
        // index 0 is the most recent row (the end of the range), the last
        // index is the oldest row (the start of the range).
        $result = StatsPageRenderer::setMissingValues(
            'month',
            [
                new HistorySummaryRow(year: 2026, month: 3, day: null, hour: null, nbPages: 9),
                new HistorySummaryRow(year: 2026, month: 1, day: null, hour: null, nbPages: 4),
            ]
        );

        self::assertSame(
            [
                '2026-01' => 4,
                '2026-02' => 0,
                '2026-03' => 9,
            ],
            $result
        );
    }

    public function testSetMissingValuesHourUnitFormatsWithTimeComponent(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'hour',
            [],
            new DateTime('2026-01-01 00:00:00'),
            new DateTime('2026-01-01 02:00:00')
        );

        self::assertSame(
            [
                '2026-01-01T00:00' => 0,
                '2026-01-01T01:00' => 0,
                '2026-01-01T02:00' => 0,
            ],
            $result
        );
    }

    public function testSetMissingValuesYearUnit(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'year',
            [
                new HistorySummaryRow(year: 2026, month: null, day: null, hour: null, nbPages: 11),
            ],
            new DateTime('2024-01-01'),
            new DateTime('2026-01-01')
        );

        self::assertSame(
            [
                '2024' => 0,
                '2025' => 0,
                '2026' => 11,
            ],
            $result
        );
    }

    public function testSetMissingValuesRejectsAnInvalidUnit(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessageIsOrContains('Invalid unit: fortnight');

        StatsPageRenderer::setMissingValues(
            'fortnight',
            [],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-02')
        );
    }

    public function testSetMissingValuesIgnoresARowWhoseDateFallsOutsideTheRequestedRange(): void
    {
        // isset($result[$str]) must be checked independently -- a row for a
        // date outside [date, date_end] has no matching key in the
        // pre-filled $result at all, and must not silently create one.
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                new HistorySummaryRow(year: 2020, month: 1, day: 1, hour: null, nbPages: 5),
            ],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-03')
        );

        self::assertSame(
            [
                '2026-01-01' => 0,
                '2026-01-02' => 0,
                '2026-01-03' => 0,
            ],
            $result
        );
    }
}
