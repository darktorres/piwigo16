<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Ws\WsHelper -- static helpers shared by several pwg.* WS methods, reached
 * here mostly through pwg.images.search (stdImageSqlFilter()/
 * stdImageSqlOrder()/stdGetUrls()) and pwg.categories.getList
 * (categoriesFlatlistToTree()).
 *
 * isInvokeAllowed()'s guest-denied branch is covered by WsServerTest's own
 * guest_access-disabled test (same EventDispatcher handler, reached
 * through every WS call, not specific to any one method here).
 *
 * WsHelper::stdImageSqlFilter()'s "invalid date -> sendResponse()+exit"
 * branch really does call PHP's exit() -- confirmed live it's safe to
 * exercise through a real HTTP request (each request is an independent
 * script execution; exit() just ends that one normally, same as any
 * request's natural end, no shared process is torn down), unlike a bare
 * PHPUnit-process exit() would be.
 */
final class WsHelperTest extends ContractTestCase
{
    private Connection $conn;

    #[\Override]
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
        $response = $this->ws('pwg.images.search', array_merge(['query' => $query, 'order' => 'id asc'], $extraParams));
        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);

        return array_values(array_map(static fn (mixed $im): int => is_array($im) && is_numeric($im['id']) ? (int) $im['id'] : 0, $images));
    }

    // ------------------------------------------------------- stdImageSqlFilter

    public function test_stdImageSqlFilter_invalid_date_sends_an_error_response_and_stops(): void
    {
        // Fixture images 1-5's real ratings (4.5, 3, 5, 2, null) confirmed
        // live via a direct DB read before writing the assertions below.
        // All 5 start with hit=0 -- the f_min_hit test below seeds its own
        // nonzero value rather than relying on the fixture for that column.
        $response = $this->ws('pwg.images.search', [
            'query' => 'Photo',
            'f_min_date_available' => 'not-a-real-date',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid f_min_date_available', $response['message']);
    }

    public function test_stdImageSqlFilter_f_min_rate_keeps_only_images_at_or_above(): void
    {
        $ids = $this->searchIds('Photo', ['f_min_rate' => 4]);
        self::assertSame([1, 3], $ids);
    }

    public function test_stdImageSqlFilter_f_max_rate_keeps_only_images_at_or_below(): void
    {
        $ids = $this->searchIds('Photo', ['f_max_rate' => 3]);
        self::assertSame([2, 4], $ids);
    }

    public function test_stdImageSqlFilter_f_min_hit_keeps_only_images_at_or_above(): void
    {
        // All 5 fixture images start with hit=0 -- seed a real nonzero
        // value on image 1 so the filter has something to actually
        // discriminate on.
        $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET hit = 4 WHERE id = 1');

        try {
            $ids = $this->searchIds('Photo', ['f_min_hit' => 1]);
            self::assertSame([1], $ids);
        } finally {
            $this->conn->executeStatement('UPDATE ' . Tables::images() . ' SET hit = 0 WHERE id = 1');
        }
    }

    public function test_stdImageSqlFilter_f_min_ratio_excludes_a_squarer_image(): void
    {
        // fixture image 1 is 200x150 (ratio 1.333) -- a min_ratio above
        // that excludes it.
        $ids = $this->searchIds('Photo 1', ['f_min_ratio' => 2]);
        self::assertSame([], $ids);
    }

    public function test_stdImageSqlFilter_f_max_level_keeps_a_public_level_zero_image(): void
    {
        $ids = $this->searchIds('Photo 1', ['f_max_level' => 0]);
        self::assertSame([1], $ids);
    }

    // -------------------------------------------------------- stdImageSqlOrder

    public function test_stdImageSqlOrder_date_posted_alias_and_date_created_alias_are_accepted(): void
    {
        $postedResponse = $this->ws('pwg.images.search', ['query' => 'Photo 1', 'order' => 'date_posted asc']);
        self::assertSame('ok', $postedResponse['stat']);

        $createdResponse = $this->ws('pwg.images.search', ['query' => 'Photo 1', 'order' => 'date_created asc']);
        self::assertSame('ok', $createdResponse['stat']);
    }

    public function test_stdImageSqlOrder_random_alias_is_accepted(): void
    {
        $response = $this->ws('pwg.images.search', ['query' => 'Photo', 'order' => 'random']);

        self::assertSame('ok', $response['stat']);
    }

    public function test_stdImageSqlOrder_drops_an_unrecognized_field_but_keeps_the_valid_one(): void
    {
        // Comma-separated tokens are parsed independently -- an
        // unrecognized field name is silently dropped rather than erroring,
        // while a valid one still takes effect.
        $ids = $this->searchIds('Photo', ['order' => 'not_a_real_column, id desc']);
        $sorted = $ids;
        rsort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $ids);
    }

    // ---------------------------------------------------------------- stdGetUrls

    public function test_stdGetUrls_uses_the_element_url_service_for_a_non_original_representative(): void
    {
        // representative_ext non-empty -> SrcImage::is_original() is false
        // (IS_MIMETYPE branch instead) -- stdGetUrls()'s else branch
        // (urlService->getElementUrl()) instead of the is_original()
        // element_url/get_url() branch.
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum, representative_ext, width, height) VALUES (?, ?, ?, ?, ?, ?)',
            ['video-helper-test.mp4', 'upload/video-helper-test.mp4', md5('video-helper-test'), 'mp4', 200, 150]
        );
        $imageId = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (?, 1)',
            [$imageId]
        );

        try {
            $response = $this->ws('pwg.images.getInfo', ['image_id' => $imageId]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            self::assertIsString($result['element_url']);
            self::assertStringContainsString('/upload/video-helper-test.mp4', $result['element_url']);
            self::assertIsString($result['download_url']);
            self::assertStringContainsString('part=e&download', $result['download_url']);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
    }

    // -------------------------------------------------- categoriesFlatlistToTree

    public function test_categoriesFlatlistToTree_nests_a_child_under_its_parent(): void
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
}
