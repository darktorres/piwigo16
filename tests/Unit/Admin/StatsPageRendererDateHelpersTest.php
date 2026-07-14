<?php

declare(strict_types=1);

use Piwigo\Admin\StatsPageRenderer;

/**
 * StatsPageRenderer::getDateObject()/setMissingValues() -- extracted from
 * admin/stats.php's own top-level free functions during the P23 batch 6b
 * port. Pure functions, no DB/globals needed.
 */
final class StatsPageRendererDateHelpersTest extends \PHPUnit\Framework\TestCase
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

    public function test_get_date_object_treats_hour_zero_as_present_not_absent(): void
    {
        // Regression guard for the PHPStan-flagged `!=`-to-`!==` normalization
        // done during the port: hour '0' (midnight) must still be read as a
        // real hour value, not treated the same as a NULL hour.
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

        StatsPageRenderer::setMissingValues(
            'fortnight',
            [],
            new DateTime('2026-01-01'),
            new DateTime('2026-01-02')
        );
    }
}
