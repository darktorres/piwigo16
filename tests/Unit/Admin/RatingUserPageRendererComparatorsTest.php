<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Piwigo\Admin\RatingUserPageRenderer;

/**
 * RatingUserPageRenderer's 5 uasort() comparator callbacks -- extracted
 * from include/admin/rating_user.php's own top-level free functions during
 * the P23 batch 6a port. Pure functions, no DB/globals needed.
 */
final class RatingUserPageRendererComparatorsTest extends TestCase
{
    public function test_avg_compare_orders_ascending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::avgCompare(['avg' => 1.0], ['avg' => 2.0]));
        self::assertSame(1, RatingUserPageRenderer::avgCompare(['avg' => 2.0], ['avg' => 1.0]));
        self::assertSame(0, RatingUserPageRenderer::avgCompare(['avg' => 1.5], ['avg' => 1.5]));
    }

    public function test_avg_compare_orders_a_sub_one_difference_correctly(): void
    {
        // A difference strictly between 0 and 1 -- a boundary the
        // 1.0-apart/2.0-apart cases above can't distinguish from a
        // `$d < 1` off-by-one in the sign check.
        self::assertSame(1, RatingUserPageRenderer::avgCompare(['avg' => 1.5], ['avg' => 1.0]));
    }

    public function test_count_compare_orders_ascending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::countCompare(['count' => 3], ['count' => 5]));
        self::assertSame(1, RatingUserPageRenderer::countCompare(['count' => 5], ['count' => 3]));
        self::assertSame(0, RatingUserPageRenderer::countCompare(['count' => 4], ['count' => 4]));
    }

    public function test_count_compare_orders_a_sub_one_difference_correctly(): void
    {
        // Both sides of the same `$d < 0`/`$d < ±1` boundary as
        // test_avg_compare_orders_a_sub_one_difference_correctly() above.
        self::assertSame(1, RatingUserPageRenderer::countCompare(['count' => 4.5], ['count' => 4]));
        self::assertSame(-1, RatingUserPageRenderer::countCompare(['count' => 4], ['count' => 4.5]));
    }

    public function test_cv_compare_orders_descending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::cvCompare(['cv' => 2.0], ['cv' => 1.0]));
        self::assertSame(1, RatingUserPageRenderer::cvCompare(['cv' => 1.0], ['cv' => 2.0]));
        self::assertSame(0, RatingUserPageRenderer::cvCompare(['cv' => 1.0], ['cv' => 1.0]));
    }

    public function test_cv_compare_orders_a_sub_one_difference_correctly(): void
    {
        self::assertSame(1, RatingUserPageRenderer::cvCompare(['cv' => 1.0], ['cv' => 1.5]));
    }

    public function test_cv_compare_defaults_both_missing_values_to_a_tie(): void
    {
        // Neither side present -- both fall back to the same 0.0 default.
        // Distinguishes that literal 0.0 from an off-by-one default (which
        // would break the tie and report a spurious ordering).
        self::assertSame(0, RatingUserPageRenderer::cvCompare([], []));
    }

    public function test_consensus_dev_compare_orders_descending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::consensusDevCompare(['cd' => 2.0], ['cd' => 1.0]));
        self::assertSame(1, RatingUserPageRenderer::consensusDevCompare(['cd' => 1.0], ['cd' => 2.0]));
        self::assertSame(0, RatingUserPageRenderer::consensusDevCompare(['cd' => 0.5], ['cd' => 0.5]));
    }

    public function test_consensus_dev_compare_orders_a_sub_one_difference_correctly(): void
    {
        self::assertSame(1, RatingUserPageRenderer::consensusDevCompare(['cd' => 1.0], ['cd' => 1.5]));
    }

    public function test_consensus_dev_compare_defaults_both_missing_values_to_a_tie(): void
    {
        self::assertSame(0, RatingUserPageRenderer::consensusDevCompare([], []));
    }

    public function test_last_rate_compare_orders_most_recent_first(): void
    {
        self::assertSame(
            -1,
            RatingUserPageRenderer::lastRateCompare(
                ['last_date' => '2026-08-01'],
                ['last_date' => '2026-07-01']
            )
        );
        self::assertSame(
            1,
            RatingUserPageRenderer::lastRateCompare(
                ['last_date' => '2026-07-01'],
                ['last_date' => '2026-08-01']
            )
        );
        self::assertSame(
            0,
            RatingUserPageRenderer::lastRateCompare(
                ['last_date' => '2026-08-01'],
                ['last_date' => '2026-08-01']
            )
        );
    }

    public function test_comparators_default_missing_values_to_zero(): void
    {
        self::assertSame(0, RatingUserPageRenderer::avgCompare([], []));
        self::assertSame(0, RatingUserPageRenderer::countCompare([], []));
        self::assertSame(0, RatingUserPageRenderer::lastRateCompare([], []));
    }
}
