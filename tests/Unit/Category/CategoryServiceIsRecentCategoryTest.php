<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Category;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Piwigo\Category\CategoryService;

/**
 * CategoryService::isRecentCategory() -- the PHP-side replacement
 * for get_recent_photos_sql()'s SQL fragment:
 * `$dbField >= LEAST(today - $recentPeriod days, $lastPhotoDate - 1 day)`.
 * Pure function, no DB/globals needed.
 *
 * A mutation-testing sweep found `$thresholdFromToday < $thresholdFromLastPhoto`
 * (the LEAST() port) has a confirmed-equivalent `<` -> `<=` mutant: the two
 * comparison operators can only ever disagree on *which* branch gets
 * picked when the two thresholds are exactly equal -- and when they're
 * equal, both branches hold the same date/time value, so $threshold ends
 * up identical either way regardless of which operand's copy gets
 * assigned. Not worth chasing.
 */
final class CategoryServiceIsRecentCategoryTest extends TestCase
{
    private const string NOW = '2026-08-01 00:00:00';

    public function testReturnsFalseWhenLastPhotoDateIsNull(): void
    {
        // matches get_recent_photos_sql()'s own
        // `if (!isset($user['last_photo_date'])) return '0=1';`
        self::assertFalse(CategoryService::isRecentCategory(
            self::NOW,
            7,
            null,
            new DateTimeImmutable(self::NOW)
        ));
    }

    public function testReturnsFalseWhenDateLastIsNull(): void
    {
        self::assertFalse(CategoryService::isRecentCategory(
            null,
            7,
            self::NOW,
            new DateTimeImmutable(self::NOW)
        ));
    }

    public function testTodayMinusPeriodThresholdWinsWhenLastPhotoDateIsRecent(): void
    {
        // last_photo_date 2 days ago -> thresholdFromLastPhoto = 3 days ago.
        // recentPeriod=7 -> thresholdFromToday = 7 days ago. LEAST() picks
        // the earlier (smaller) of the two -- 7 days ago wins here.
        $now = new DateTimeImmutable(self::NOW);
        $lastPhotoDate = '2026-07-30 00:00:00'; // 2 days before NOW

        self::assertTrue(CategoryService::isRecentCategory(
            '2026-07-26 00:00:00', // 6 days ago -- within the 7-day window
            7,
            $lastPhotoDate,
            $now
        ));
        self::assertFalse(CategoryService::isRecentCategory(
            '2026-07-20 00:00:00', // 12 days ago -- outside the 7-day window
            7,
            $lastPhotoDate,
            $now
        ));
    }

    public function testLastPhotoDateThresholdWidensTheWindowWhenTheGalleryIsStale(): void
    {
        // last_photo_date 60 days ago -> thresholdFromLastPhoto = 61 days
        // ago, earlier than the naive 7-day threshold -- LEAST() picks it,
        // so recent_cats still shows the gallery's actual last activity
        // instead of coming back empty.
        $now = new DateTimeImmutable(self::NOW);
        $lastPhotoDate = '2026-06-02 00:00:00'; // 60 days before NOW

        self::assertTrue(CategoryService::isRecentCategory(
            '2026-06-10 00:00:00', // 52 days ago -- outside the naive 7-day window, inside the widened one
            7,
            $lastPhotoDate,
            $now
        ));
        self::assertFalse(CategoryService::isRecentCategory(
            '2026-05-01 00:00:00', // before even the widened threshold
            7,
            $lastPhotoDate,
            $now
        ));
    }

    public function testBoundaryDateLastExactlyAtTheThresholdCountsAsRecent(): void
    {
        $now = new DateTimeImmutable(self::NOW);

        self::assertTrue(CategoryService::isRecentCategory(
            '2026-07-25 00:00:00', // exactly NOW - 7 days
            7,
            self::NOW,
            $now
        ));
    }
}
