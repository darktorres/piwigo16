<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Piwigo\Admin\Projection\UserRatingRow;
use Piwigo\Admin\RatingUserPageRenderer;

/**
 * RatingUserPageRenderer's 5 uasort() comparator callbacks are pure
 * functions, no DB/globals needed.
 *
 * They take a UserRatingRow as of P58-A's §3, so the
 * "missing key defaults to zero" cases this file used to carry are
 * gone: a row cannot be missing a field, and each comparator reads
 * the one it sorts on directly. What survives is the ordering
 * itself, including the sub-one differences that a `< 1` in place of
 * a `< 0` would get wrong.
 */
final class RatingUserPageRendererComparatorsTest extends TestCase
{
    private static function row(
        float $avg = 0.0,
        int $count = 0,
        float $cv = 0.0,
        float $cd = 0.0,
        string $lastDate = '',
    ): UserRatingRow {
        return new UserRatingRow(
            uid: 1,
            aid: '',
            firstDate: '',
            lastDate: $lastDate,
            count: $count,
            avg: $avg,
            cv: $cv,
            cd: $cd,
            cdTop: null,
            rates: [],
        );
    }

    public function testAvgCompareOrdersAscending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::avgCompare(self::row(avg: 1.0), self::row(avg: 2.0)));
        self::assertSame(1, RatingUserPageRenderer::avgCompare(self::row(avg: 2.0), self::row(avg: 1.0)));
        self::assertSame(0, RatingUserPageRenderer::avgCompare(self::row(avg: 1.5), self::row(avg: 1.5)));
    }

    public function testAvgCompareOrdersASubOneDifferenceCorrectly(): void
    {
        // A difference strictly between 0 and 1 -- a boundary the
        // 1.0-apart/2.0-apart cases above can't distinguish from a
        // `$d < 1` off-by-one in the sign check.
        self::assertSame(1, RatingUserPageRenderer::avgCompare(self::row(avg: 1.5), self::row(avg: 1.0)));
    }

    public function testCountCompareOrdersAscending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::countCompare(self::row(count: 3), self::row(count: 5)));
        self::assertSame(1, RatingUserPageRenderer::countCompare(self::row(count: 5), self::row(count: 3)));
        self::assertSame(0, RatingUserPageRenderer::countCompare(self::row(count: 4), self::row(count: 4)));
    }

    public function testCountCompareOrdersAdjacentCountsCorrectly(): void
    {
        // Counts are ints, so the smallest real difference is 1 --
        // and the comparator casts both sides to float precisely
        // because its zero test is strict and `0 === 0.0` is false.
        self::assertSame(-1, RatingUserPageRenderer::countCompare(self::row(count: 3), self::row(count: 4)));
        self::assertSame(1, RatingUserPageRenderer::countCompare(self::row(count: 4), self::row(count: 3)));
    }

    public function testCvCompareOrdersDescending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::cvCompare(self::row(cv: 2.0), self::row(cv: 1.0)));
        self::assertSame(1, RatingUserPageRenderer::cvCompare(self::row(cv: 1.0), self::row(cv: 2.0)));
        self::assertSame(0, RatingUserPageRenderer::cvCompare(self::row(cv: 1.5), self::row(cv: 1.5)));
    }

    public function testCvCompareOrdersASubOneDifferenceCorrectly(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::cvCompare(self::row(cv: 1.5), self::row(cv: 1.0)));
    }

    public function testConsensusDevCompareOrdersDescending(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::consensusDevCompare(self::row(cd: 2.0), self::row(cd: 1.0)));
        self::assertSame(1, RatingUserPageRenderer::consensusDevCompare(self::row(cd: 1.0), self::row(cd: 2.0)));
        self::assertSame(0, RatingUserPageRenderer::consensusDevCompare(self::row(cd: 1.5), self::row(cd: 1.5)));
    }

    public function testConsensusDevCompareOrdersASubOneDifferenceCorrectly(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::consensusDevCompare(self::row(cd: 1.5), self::row(cd: 1.0)));
    }

    public function testLastRateCompareOrdersMostRecentFirst(): void
    {
        self::assertSame(-1, RatingUserPageRenderer::lastRateCompare(self::row(lastDate: '2026-08-01'), self::row(lastDate: '2026-07-01')));
        self::assertSame(1, RatingUserPageRenderer::lastRateCompare(self::row(lastDate: '2026-07-01'), self::row(lastDate: '2026-08-01')));
        self::assertSame(0, RatingUserPageRenderer::lastRateCompare(self::row(lastDate: '2026-08-01'), self::row(lastDate: '2026-08-01')));
    }

    public function testLastRateCompareTreatsAnEmptyDateAsOldest(): void
    {
        // Rate::$date is nullable and the renderer normalizes null to
        // '', so an empty date is a real value here rather than a
        // missing key.
        self::assertSame(-1, RatingUserPageRenderer::lastRateCompare(self::row(lastDate: '2026-08-01'), self::row(lastDate: '')));
        self::assertSame(0, RatingUserPageRenderer::lastRateCompare(self::row(lastDate: ''), self::row(lastDate: '')));
    }
}
