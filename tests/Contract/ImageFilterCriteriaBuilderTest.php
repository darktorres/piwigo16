<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;

/**
 * Ws\ImageFilterCriteriaBuilder -- split out of the former WsHelper
 * god-class (P25 Stage 1 step 6), reached here through
 * pwg.images.search.
 *
 * ImageFilterCriteriaBuilder::stdImageSqlFilterCriteria()'s "invalid date ->
 * sendResponse()+exit" branch really does call PHP's exit() -- confirmed
 * live it's safe to exercise through a real HTTP request (each request is
 * an independent script execution; exit() just ends that one normally,
 * same as any request's natural end, no shared process is torn down),
 * unlike a bare PHPUnit-process exit() would be.
 */
final class ImageFilterCriteriaBuilderTest extends ContractTestCase
{
    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
    }

    /**
     * @param array<string, mixed> $extraParams
     * @return list<int>
     */
    private function searchIds(string $query, array $extraParams = []): array
    {
        $response = $this->ws('pwg.images.search', array_merge([
            'query' => $query,
            'order' => 'id asc',
        ], $extraParams));
        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);

        return array_values(array_map(static fn (mixed $im): int => is_array($im) && is_numeric($im['id']) ? (int) $im['id'] : 0, $images));
    }

    public function testStdImageSqlFilterCriteriaInvalidDateSendsAnErrorResponseAndStops(): void
    {
        // All 5 fixture images start with hit=0 -- the f_min_hit test
        // below seeds its own nonzero value rather than relying on the
        // fixture for that column.
        $response = $this->ws('pwg.images.search', [
            'query' => 'Photo',
            'f_min_date_available' => 'not-a-real-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid f_min_date_available', $response['message']);
    }

    /**
     * Real bug found live: fixture images 1-5's real ratings are not just
     * genuinely different between piwigo-17.0.sql and
     * piwigo-17.0-pgsql.sql (separate independent install+upload+rate
     * runs, same real gap already fixed for the fixture-photo hash
     * suffixes elsewhere) -- they also shift *during* a
     * single suite run. RateService::updateRatingScore() recomputes a
     * bayesian-shrinkage score for every rated image whenever any rate
     * is added or removed anywhere (confirmed live by reading its own
     * source: every image's score depends on global averages over the
     * whole rate table, not just its own rows), so even a
     * different test's throwaway-image rate call shifts every fixture
     * image's rating_score for the rest of the run -- a hardcoded
     * expected value (for either platform) is fundamentally unreliable
     * regardless of portability. Reads the real, live rating_score
     * values right before asserting and computes the expected id set
     * from them, instead of a baked-in literal.
     *
     * @return list<int>
     */
    private function fetchFixtureImageIdsWithRating(callable $matches): array
    {
        $rows = $this->conn->fetchAllAssociative('SELECT id, rating_score FROM images WHERE id IN (1, 2, 3, 4, 5)');
        $matching = [];
        foreach ($rows as $row) {
            // id is a real, never-null PK; rating_score genuinely can be
            // null (unrated images), so its own is_numeric() guard stays.
            $id = $row['id'];
            $rating = $row['rating_score'];
            if (is_numeric($rating) && $matches((float) $rating)) {
                $matching[] = $id;
            }
        }
        sort($matching);

        return $matching;
    }

    public function testStdImageSqlFilterCriteriaFMinRateKeepsOnlyImagesAtOrAbove(): void
    {
        $expected = $this->fetchFixtureImageIdsWithRating(static fn (float $rating): bool => $rating >= 4.0);
        self::assertNotEmpty($expected, 'at least one fixture image must genuinely have a rating >= 4 for this test to prove anything');

        $ids = $this->searchIds('Photo', [
            'f_min_rate' => 4,
        ]);
        sort($ids);
        self::assertSame($expected, $ids);
    }

    public function testStdImageSqlFilterCriteriaFMaxRateKeepsOnlyImagesAtOrBelow(): void
    {
        $expected = $this->fetchFixtureImageIdsWithRating(static fn (float $rating): bool => $rating <= 3.0);
        self::assertNotEmpty($expected, 'at least one fixture image must genuinely have a rating <= 3 for this test to prove anything');

        $ids = $this->searchIds('Photo', [
            'f_max_rate' => 3,
        ]);
        sort($ids);
        self::assertSame($expected, $ids);
    }

    public function testStdImageSqlFilterCriteriaFMinHitKeepsOnlyImagesAtOrAbove(): void
    {
        // All 5 fixture images start with hit=0 -- seed a real nonzero
        // value on image 1 so the filter has something to actually
        // discriminate on.
        $this->conn->executeStatement('UPDATE images SET hit = 4 WHERE id = 1');

        try {
            $ids = $this->searchIds('Photo', [
                'f_min_hit' => 1,
            ]);
            self::assertSame([1], $ids);
        } finally {
            $this->conn->executeStatement('UPDATE images SET hit = 0 WHERE id = 1');
        }
    }

    public function testStdImageSqlFilterCriteriaFMinRatioExcludesASquarerImage(): void
    {
        // fixture image 1 is 200x150 (ratio 1.333) -- a min_ratio above
        // that excludes it.
        $ids = $this->searchIds('Photo 1', [
            'f_min_ratio' => 2,
        ]);
        self::assertSame([], $ids);
    }

    public function testStdImageSqlFilterCriteriaFMaxRatioExcludesAWiderImage(): void
    {
        // fixture image 1 is 200x150 (ratio 1.333) -- a max_ratio below
        // that excludes it; a max_ratio above it keeps it.
        $excluded = $this->searchIds('Photo 1', [
            'f_max_ratio' => 1,
        ]);
        self::assertSame([], $excluded);

        $included = $this->searchIds('Photo 1', [
            'f_max_ratio' => 2,
        ]);
        self::assertSame([1], $included);
    }

    public function testStdImageSqlFilterCriteriaFMaxHitKeepsOnlyImagesAtOrBelow(): void
    {
        // All 5 fixture images start with hit=0 -- seed a real nonzero
        // value on image 1 so the filter has something to actually
        // discriminate on.
        $this->conn->executeStatement('UPDATE images SET hit = 4 WHERE id = 1');

        try {
            $ids = $this->searchIds('Photo', [
                'f_max_hit' => 3,
            ]);
            self::assertSame([2, 3, 4, 5], $ids);
        } finally {
            $this->conn->executeStatement('UPDATE images SET hit = 0 WHERE id = 1');
        }
    }

    public function testStdImageSqlFilterCriteriaFMinDateAvailableKeepsOnlyImagesAtOrAfter(): void
    {
        // Every fixture image shares date_available='2026-08-01 00:00:00'.
        $included = $this->searchIds('Photo 1', [
            'f_min_date_available' => '2026-07-01',
        ]);
        self::assertSame([1], $included);

        $excluded = $this->searchIds('Photo 1', [
            'f_min_date_available' => '2026-09-01',
        ]);
        self::assertSame([], $excluded);
    }

    public function testStdImageSqlFilterCriteriaFMaxDateAvailableKeepsOnlyImagesStrictlyBefore(): void
    {
        $included = $this->searchIds('Photo 1', [
            'f_max_date_available' => '2026-09-01',
        ]);
        self::assertSame([1], $included);

        $excluded = $this->searchIds('Photo 1', [
            'f_max_date_available' => '2026-07-01',
        ]);
        self::assertSame([], $excluded);
    }

    public function testStdImageSqlFilterCriteriaFMinDateCreatedKeepsOnlyImagesAtOrAfter(): void
    {
        // Every fixture image starts with date_creation=NULL -- `date_creation
        // >= '...'` is always false (NULL) against it, so a real value is
        // seeded first, same rationale as the f_min_hit test above.
        $this->conn->executeStatement("UPDATE images SET date_creation = '2026-01-15 00:00:00' WHERE id = 1");

        try {
            $included = $this->searchIds('Photo 1', [
                'f_min_date_created' => '2026-01-10',
            ]);
            self::assertSame([1], $included);

            $excluded = $this->searchIds('Photo 1', [
                'f_min_date_created' => '2026-01-20',
            ]);
            self::assertSame([], $excluded);
        } finally {
            $this->conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id = 1');
        }
    }

    public function testStdImageSqlFilterCriteriaFMaxDateCreatedKeepsOnlyImagesStrictlyBefore(): void
    {
        $this->conn->executeStatement("UPDATE images SET date_creation = '2026-01-15 00:00:00' WHERE id = 1");

        try {
            $included = $this->searchIds('Photo 1', [
                'f_max_date_created' => '2026-01-20',
            ]);
            self::assertSame([1], $included);

            $excluded = $this->searchIds('Photo 1', [
                'f_max_date_created' => '2026-01-10',
            ]);
            self::assertSame([], $excluded);
        } finally {
            $this->conn->executeStatement('UPDATE images SET date_creation = NULL WHERE id = 1');
        }
    }

    public function testStdImageSqlFilterCriteriaFMaxLevelKeepsAPublicLevelZeroImage(): void
    {
        $ids = $this->searchIds('Photo 1', [
            'f_max_level' => 0,
        ]);
        self::assertSame([1], $ids);
    }
}
