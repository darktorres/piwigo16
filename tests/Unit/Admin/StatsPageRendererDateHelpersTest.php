<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use DateMalformedStringException;
use DateTime;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Piwigo\Admin\StatsPageRenderer;

/**
 * StatsPageRenderer::getDateObject()/setMissingValues() are pure functions,
 * no DB/globals needed.
 */
final class StatsPageRendererDateHelpersTest extends TestCase
{
    public function testGetDateObjectYearOnlyRowDefaultsToFirstOfYear(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => null,
            'day' => null,
            'hour' => null,
        ]);

        self::assertSame('2026-01-01', $date->format('Y-m-d'));
    }

    public function testGetDateObjectYearMonthRowDefaultsToFirstOfMonth(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => null,
            'hour' => null,
        ]);

        self::assertSame('2026-03-01', $date->format('Y-m-d'));
    }

    public function testGetDateObjectYearMonthDayRowHasNoTimeComponent(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => '17',
            'hour' => null,
        ]);

        self::assertSame('2026-03-17 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testGetDateObjectFullRowIncludesTheHour(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => '17',
            'hour' => '14',
        ]);

        self::assertSame('2026-03-17 14:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testGetDateObjectSilentlyIgnoresANonNumericYearWhenMonthIsPresent(): void
    {
        // '' (real is_numeric()-false fallback for year) concatenated with
        // '-3' gives '-3', which PHP's DateTime constructor happens to
        // parse as a no-op relative modifier (not an absolute date) rather
        // than throwing -- documenting this actual (surprising) behavior
        // also proves the fallback is genuinely '', not some other
        // placeholder text, which DateTime can't parse at all.
        $date = StatsPageRenderer::getDateObject([
            'year' => 'not-a-year',
            'month' => '3',
            'day' => null,
            'hour' => null,
        ]);

        // getDateObject()'s own return type already guarantees the class --
        // what's actually under test is the surprising behavior documented
        // above: this malformed input doesn't throw.
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(DateTime::class, $date);
    }

    public function testGetDateObjectRejectsANonNumericMonth(): void
    {
        // The year-month separator produces a literal '2026-' (empty
        // month) when the fallback is genuinely '' -- DateTime's own parse
        // failure message echoes the exact malformed string back, so this
        // also proves the fallback isn't some non-empty placeholder (which
        // would embed extra characters between the dashes).
        $this->expectException(DateMalformedStringException::class);
        $this->expectExceptionMessageIsOrContains('(2026-)');

        StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => 'not-a-month',
            'day' => null,
            'hour' => null,
        ]);
    }

    public function testGetDateObjectRejectsANonNumericDay(): void
    {
        $this->expectException(DateMalformedStringException::class);
        $this->expectExceptionMessageIsOrContains('(2026-3-)');

        StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => 'not-a-day',
            'hour' => null,
        ]);
    }

    public function testGetDateObjectRejectsANonNumericHour(): void
    {
        $this->expectException(DateMalformedStringException::class);
        $this->expectExceptionMessageIsOrContains('(2026-3-17 :00)');

        StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => '17',
            'hour' => 'not-an-hour',
        ]);
    }

    public function testGetDateObjectTreatsHourZeroAsPresentNotAbsent(): void
    {
        // hour '0' (midnight) must be read as a real hour value, not
        // treated the same as a NULL hour.
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => '17',
            'hour' => '0',
        ]);

        self::assertSame('2026-03-17 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function testSetMissingValuesZeroFillsGapsAndOverlaysRealRows(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                [
                    'year' => '2026',
                    'month' => '1',
                    'day' => '3',
                    'hour' => null,
                    'nb_pages' => '7',
                ],
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
                [
                    'year' => '2026',
                    'month' => '3',
                    'day' => null,
                    'hour' => null,
                    'nb_pages' => '9',
                ],
                [
                    'year' => '2026',
                    'month' => '1',
                    'day' => null,
                    'hour' => null,
                    'nb_pages' => '4',
                ],
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
                [
                    'year' => '2026',
                    'month' => null,
                    'day' => null,
                    'hour' => null,
                    'nb_pages' => '11',
                ],
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

    public function testSetMissingValuesIgnoresARowWithANonNumericNbPages(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                [
                    'year' => '2026',
                    'month' => '1',
                    'day' => '2',
                    'hour' => null,
                    'nb_pages' => 'not-a-number',
                ],
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

    public function testSetMissingValuesIgnoresARowWhoseDateFallsOutsideTheRequestedRange(): void
    {
        // isset($result[$str]) must be checked independently of
        // is_numeric($nb_pages) -- a row for a date outside [date, date_end]
        // has no matching key in the pre-filled $result at all, and must
        // not silently create one.
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                [
                    'year' => '2020',
                    'month' => '1',
                    'day' => '1',
                    'hour' => null,
                    'nb_pages' => '5',
                ],
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

    public function testSetMissingValuesTruncatesAFractionalNbPagesToAnInt(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                [
                    'year' => '2026',
                    'month' => '1',
                    'day' => '1',
                    'hour' => null,
                    'nb_pages' => '5.9',
                ],
            ],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-01')
        );

        self::assertSame([
            '2026-01-01' => 5,
        ], $result);
    }
}
