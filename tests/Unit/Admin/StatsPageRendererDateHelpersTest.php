<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use DateMalformedStringException;
use DateTime;
use InvalidArgumentException;
use Piwigo\Admin\StatsPageRenderer;

/**
 * StatsPageRenderer::getDateObject()/setMissingValues() are pure functions,
 * no DB/globals needed.
 */
final class StatsPageRendererDateHelpersTest extends TestCase
{
    public function test_get_date_object_year_only_row_defaults_to_first_of_year(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => null,
            'day' => null,
            'hour' => null,
        ]);

        self::assertSame('2026-01-01', $date->format('Y-m-d'));
    }

    public function test_get_date_object_year_month_row_defaults_to_first_of_month(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => null,
            'hour' => null,
        ]);

        self::assertSame('2026-03-01', $date->format('Y-m-d'));
    }

    public function test_get_date_object_year_month_day_row_has_no_time_component(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => '17',
            'hour' => null,
        ]);

        self::assertSame('2026-03-17 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function test_get_date_object_full_row_includes_the_hour(): void
    {
        $date = StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => '17',
            'hour' => '14',
        ]);

        self::assertSame('2026-03-17 14:00:00', $date->format('Y-m-d H:i:s'));
    }

    public function test_get_date_object_silently_ignores_a_non_numeric_year_when_month_is_present(): void
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

        self::assertInstanceOf(DateTime::class, $date);
    }

    public function test_get_date_object_rejects_a_non_numeric_month(): void
    {
        // The year-month separator produces a literal '2026-' (empty
        // month) when the fallback is genuinely '' -- DateTime's own parse
        // failure message echoes the exact malformed string back, so this
        // also proves the fallback isn't some non-empty placeholder (which
        // would embed extra characters between the dashes).
        $this->expectException(DateMalformedStringException::class);
        $this->expectExceptionMessage('(2026-)');

        StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => 'not-a-month',
            'day' => null,
            'hour' => null,
        ]);
    }

    public function test_get_date_object_rejects_a_non_numeric_day(): void
    {
        $this->expectException(DateMalformedStringException::class);
        $this->expectExceptionMessage('(2026-3-)');

        StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => 'not-a-day',
            'hour' => null,
        ]);
    }

    public function test_get_date_object_rejects_a_non_numeric_hour(): void
    {
        $this->expectException(DateMalformedStringException::class);
        $this->expectExceptionMessage('(2026-3-17 :00)');

        StatsPageRenderer::getDateObject([
            'year' => '2026',
            'month' => '3',
            'day' => '17',
            'hour' => 'not-an-hour',
        ]);
    }

    public function test_get_date_object_treats_hour_zero_as_present_not_absent(): void
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

    public function test_set_missing_values_zero_fills_gaps_and_overlays_real_rows(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                ['year' => '2026', 'month' => '1', 'day' => '3', 'hour' => null, 'nb_pages' => '7'],
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

    public function test_set_missing_values_without_explicit_dates_uses_first_and_last_row_of_data(): void
    {
        // $data is expected DESC-ordered (matching getLast()'s own ORDER BY):
        // index 0 is the most recent row (the end of the range), the last
        // index is the oldest row (the start of the range).
        $result = StatsPageRenderer::setMissingValues(
            'month',
            [
                ['year' => '2026', 'month' => '3', 'day' => null, 'hour' => null, 'nb_pages' => '9'],
                ['year' => '2026', 'month' => '1', 'day' => null, 'hour' => null, 'nb_pages' => '4'],
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

    public function test_set_missing_values_hour_unit_formats_with_time_component(): void
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

    public function test_set_missing_values_year_unit(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'year',
            [
                ['year' => '2026', 'month' => null, 'day' => null, 'hour' => null, 'nb_pages' => '11'],
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

    public function test_set_missing_values_rejects_an_invalid_unit(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Invalid unit: fortnight');

        StatsPageRenderer::setMissingValues(
            'fortnight',
            [],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-02')
        );
    }

    public function test_set_missing_values_ignores_a_row_with_a_non_numeric_nb_pages(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                ['year' => '2026', 'month' => '1', 'day' => '2', 'hour' => null, 'nb_pages' => 'not-a-number'],
            ],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-03')
        );

        self::assertSame(
            ['2026-01-01' => 0, '2026-01-02' => 0, '2026-01-03' => 0],
            $result
        );
    }

    public function test_set_missing_values_ignores_a_row_whose_date_falls_outside_the_requested_range(): void
    {
        // isset($result[$str]) must be checked independently of
        // is_numeric($nb_pages) -- a row for a date outside [date, date_end]
        // has no matching key in the pre-filled $result at all, and must
        // not silently create one.
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                ['year' => '2020', 'month' => '1', 'day' => '1', 'hour' => null, 'nb_pages' => '5'],
            ],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-03')
        );

        self::assertSame(
            ['2026-01-01' => 0, '2026-01-02' => 0, '2026-01-03' => 0],
            $result
        );
    }

    public function test_set_missing_values_truncates_a_fractional_nb_pages_to_an_int(): void
    {
        $result = StatsPageRenderer::setMissingValues(
            'day',
            [
                ['year' => '2026', 'month' => '1', 'day' => '1', 'hour' => null, 'nb_pages' => '5.9'],
            ],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-01')
        );

        self::assertSame(['2026-01-01' => 5], $result);
    }
}
