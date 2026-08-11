<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Ws\WsHelper;

/**
 * Ws\WsHelper -- static helpers shared by several pwg.* WS methods, reached
 * here mostly through pwg.images.search (stdImageSqlFilterCriteria()/
 * stdImageSqlOrder()/stdGetUrls()) and pwg.categories.getList
 * (categoriesFlatlistToTree()).
 *
 * isInvokeAllowed()'s guest-denied branch is covered by WsServerTest's own
 * guest_access-disabled test (same EventDispatcher handler, reached
 * through every WS call, not specific to any one method here).
 *
 * WsHelper::stdImageSqlFilterCriteria()'s "invalid date ->
 * sendResponse()+exit" branch really does call PHP's exit() -- confirmed
 * live it's safe to exercise through a real HTTP request (each request is
 * an independent script execution; exit() just ends that one normally,
 * same as any request's natural end, no shared process is torn down),
 * unlike a bare PHPUnit-process exit() would be.
 *
 * stdImageSqlOrder()'s own `case 'rand': case 'random': ... break;` is
 * already exercised for real by
 * test_stdImageSqlOrder_random_alias_is_accepted() below -- its trailing
 * `break;` (the last case in the switch, nothing but the closing `}`
 * after it) is provably eliminated by OPcache's real jump-elision
 * optimizer on the live Apache-served process this suite runs against,
 * same root cause (and same live PCOV-based confirmation method) as this
 * project's own documented "OPcache constant-array-folding coverage
 * artifact" precedent; not a gap in test coverage. See
 * WsCommentsTest's own class docblock for the full writeup.
 *
 * categoriesFlatlistToTree()'s 2 malformed-row guards (`! is_int($cat_id)
 * && ! is_string($cat_id)` and the sibling check on `$id_uppercat`) are
 * genuinely unreachable through the real pwg.categories.getList route:
 * every row's `id`/`id_uppercat` comes straight off categories'
 * `id`/`id_uppercat` columns (a real int PK / nullable int FK), never a
 * non-scalar value. test_categoriesFlatlistToTree_skips_*() below call the
 * method directly (via a locally-booted Kernel/container, same pattern as
 * WsServerTest's own test_run_without_a_request_handler_returns_unknown_request_format())
 * with a genuinely malformed row instead -- a real call to the real
 * instance method under test, not a mock, same "unreachable through any
 * real WS request" precedent as WsServerTest's own
 * test_checkType_accepts_an_array_of_booleans() for PwgServer::checkType().
 */
final class WsHelperTest extends ContractTestCase
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

    // ------------------------------------------------- stdImageSqlFilterCriteria

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
        $this->conn->executeStatement('UPDATE images' . " SET date_creation = '2026-01-15 00:00:00' WHERE id = 1");

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
        $this->conn->executeStatement('UPDATE images' . " SET date_creation = '2026-01-15 00:00:00' WHERE id = 1");

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

    // -------------------------------------------------------- stdImageSqlOrder

    public function testStdImageSqlOrderDatePostedAliasAndDateCreatedAliasAreAccepted(): void
    {
        $postedResponse = $this->ws('pwg.images.search', [
            'query' => 'Photo 1',
            'order' => 'date_posted asc',
        ]);
        self::assertSame('ok', $postedResponse['stat']);

        $createdResponse = $this->ws('pwg.images.search', [
            'query' => 'Photo 1',
            'order' => 'date_created asc',
        ]);
        self::assertSame('ok', $createdResponse['stat']);
    }

    public function testStdImageSqlOrderRandomAliasIsAccepted(): void
    {
        $response = $this->ws('pwg.images.search', [
            'query' => 'Photo',
            'order' => 'random',
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function testStdImageSqlOrderDropsAnUnrecognizedFieldButKeepsTheValidOne(): void
    {
        // Comma-separated tokens are parsed independently -- an
        // unrecognized field name is silently dropped rather than erroring,
        // while a valid one still takes effect.
        $ids = $this->searchIds('Photo', [
            'order' => 'not_a_real_column, id desc',
        ]);
        $sorted = $ids;
        rsort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $ids);
    }

    /**
     * The `if ($ret !== '') { $ret .= ', '; }` comma-join branch --
     * test_stdImageSqlOrder_drops_an_unrecognized_field_but_keeps_the_valid_one()
     * above only ever ends up with a *single* real appended field (its
     * first, unrecognized token never reaches `$ret .= '...'` at all), so
     * $ret is still '' by the time its one real field is appended. Two
     * *valid* fields are needed to reach the comma-join itself; if it
     * silently dropped, the resulting SQL ("i.hit asc i.id desc", no
     * comma) would be a real MySQL syntax error -- a 500 here, not a
     * quietly-wrong sort order.
     */
    public function testStdImageSqlOrderJoinsMultipleValidFieldsWithAComma(): void
    {
        // All 5 fixture images share hit=0 -- ties on the primary sort key
        // fall through to the secondary "id desc", which only takes effect
        // if the 2 fields were joined into one real ORDER BY clause.
        $ids = $this->searchIds('Photo', [
            'order' => 'hit asc, id desc',
        ]);
        self::assertSame([5, 4, 3, 2, 1], $ids);
    }

    // ---------------------------------------------------------------- stdGetUrls

    public function testStdGetUrlsUsesTheElementUrlServiceForANonOriginalRepresentative(): void
    {
        // representative_ext non-empty -> SrcImage::is_original() is false
        // (IS_MIMETYPE branch instead) -- stdGetUrls()'s else branch
        // (urlService->getElementUrl()) instead of the is_original()
        // element_url/get_url() branch.
        $this->conn->executeStatement(
            'INSERT INTO images (file, path, md5sum, representative_ext, width, height) VALUES (?, ?, ?, ?, ?, ?)',
            ['video-helper-test.mp4', 'upload/video-helper-test.mp4', md5('video-helper-test'), 'mp4', 200, 150]
        );
        $imageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO image_category (image_id, category_id) VALUES (?, 1)',
            [$imageId]
        );

        try {
            $response = $this->ws('pwg.images.getInfo', [
                'image_id' => $imageId,
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertIsString($result['element_url']);
            self::assertStringContainsString('/upload/video-helper-test.mp4', $result['element_url']);
            self::assertIsString($result['download_url']);
            self::assertStringContainsString('part=e&download', $result['download_url']);
        } finally {
            $this->conn->executeStatement('DELETE FROM image_category WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }
    }

    // -------------------------------------------------- categoriesFlatlistToTree

    public function testCategoriesFlatlistToTreeNestsAChildUnderItsParent(): void
    {
        // fixture category 2 ("Nested Sub Album") is a real child of
        // category 1 ("Sample Album") -- confirmed live via a direct DB
        // read before writing this assertion.
        $response = $this->ws('pwg.categories.getList', [
            'cat_id' => 1,
            'recursive' => true,
            'tree_output' => true,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertCount(1, $result, 'tree_output must return only the root, not a flat list');
        $root = $result[0];
        self::assertIsArray($root);
        self::assertSame(1, $root['id']);
        $subCategories = $root['sub_categories'];
        self::assertIsArray($subCategories);
        self::assertCount(1, $subCategories);
        $child = $subCategories[0];
        self::assertIsArray($child);
        self::assertSame(2, $child['id']);
        self::assertSame('Nested Sub Album', $child['name']);
    }

    /**
     * See this file's own class docblock: unreachable through the real WS
     * route (categories.id is a real int PK), so this calls the
     * real public static method directly with a genuinely malformed row.
     */
    public function testCategoriesFlatlistToTreeSkipsARowWithANonScalarId(): void
    {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        try {
            $wsHelper = Kernel::container()->get(WsHelper::class);
            self::assertInstanceOf(WsHelper::class, $wsHelper);

            $tree = $wsHelper->categoriesFlatlistToTree([
                [
                    'id' => ['not', 'scalar'],
                ],
                [
                    'id' => 1,
                    'name' => 'Valid Root',
                ],
            ]);
        } finally {
            Kernel::reset();
        }

        self::assertCount(1, $tree, 'the malformed row must be skipped entirely, not just left out of the tree');
        self::assertSame(1, $tree[0]['id']);
    }

    /**
     * Same rationale as the sibling test above, for the `id_uppercat`
     * guard instead: categories.id_uppercat is a real nullable int
     * FK, never a non-scalar value through the real WS route.
     */
    public function testCategoriesFlatlistToTreeSkipsAChildRowWithANonScalarUppercatId(): void
    {
        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        try {
            $wsHelper = Kernel::container()->get(WsHelper::class);
            self::assertInstanceOf(WsHelper::class, $wsHelper);

            $tree = $wsHelper->categoriesFlatlistToTree([
                [
                    'id' => 1,
                    'name' => 'Root',
                ],
                [
                    'id' => 2,
                    'id_uppercat' => ['not', 'scalar'],
                    'name' => 'Bad Child',
                ],
            ]);
        } finally {
            Kernel::reset();
        }

        self::assertCount(1, $tree, 'the malformed child row must never be attached anywhere');
        self::assertSame(1, $tree[0]['id']);
        self::assertArrayNotHasKey('sub_categories', $tree[0]);
    }
}
