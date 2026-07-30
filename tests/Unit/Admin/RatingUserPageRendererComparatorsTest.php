<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use Piwigo\Admin\RatingUserPageRenderer;

/**
 * RatingUserPageRenderer's 5 uasort() comparator callbacks -- extracted
 * from include/admin/rating_user.php's own top-level free functions during
 * the P23 batch 6a port. Pure functions, no DB/globals needed.
 */
final class RatingUserPageRendererComparatorsTest extends \PHPUnit\Framework\TestCase
{
    public function test_avg_compare_orders_ascending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::avgCompare(['avg' => 1.0], ['avg' => 2.0]));
        self::assertSame(1, RatingUserPageRenderer::avgCompare(['avg' => 2.0], ['avg' => 1.0]));
        self::assertSame(0, RatingUserPageRenderer::avgCompare(['avg' => 1.5], ['avg' => 1.5]));
    }

    public function test_count_compare_orders_ascending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::countCompare(['count' => 3], ['count' => 5]));
        self::assertSame(1, RatingUserPageRenderer::countCompare(['count' => 5], ['count' => 3]));
        self::assertSame(0, RatingUserPageRenderer::countCompare(['count' => 4], ['count' => 4]));
    }

    public function test_cv_compare_orders_descending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::cvCompare(['cv' => 2.0], ['cv' => 1.0]));
        self::assertSame(1, RatingUserPageRenderer::cvCompare(['cv' => 1.0], ['cv' => 2.0]));
        self::assertSame(0, RatingUserPageRenderer::cvCompare(['cv' => 1.0], ['cv' => 1.0]));
    }

    public function test_consensus_dev_compare_orders_descending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::consensusDevCompare(['cd' => 2.0], ['cd' => 1.0]));
        self::assertSame(1, RatingUserPageRenderer::consensusDevCompare(['cd' => 1.0], ['cd' => 2.0]));
        self::assertSame(0, RatingUserPageRenderer::consensusDevCompare(['cd' => 0.5], ['cd' => 0.5]));
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
