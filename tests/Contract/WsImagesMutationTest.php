<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;

final class WsImagesMutationTest extends ContractTestCase
{
    /** Fixture image id — must not be deleted by these tests. */
    private const int FIXTURE_IMAGE_ID = 1;

    /** Fixture category id. */
    private const int FIXTURE_CAT_ID = 1;

    private Connection $conn;

    /** @var list<int> throwaway image ids inserted by a test, deleted in tearDown if a test didn't already remove them. */
    private array $throwawayImageIds = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->throwawayImageIds as $id) {
            $this->conn->executeStatement('DELETE FROM ' . 'images' . ' WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    /**
     * Inserts a real (tiny, on-disk) throwaway photo, optionally pre-associated
     * with the given category ids, so setCategory/delete/rate tests below
     * don't have to mutate the shared fixture images other test files (e.g.
     * CategoryServiceTest, SearchServiceTest) also read.
     * @param list<int> $categoryIds
     */
    private function insertThrowawayImage(array $categoryIds = []): int
    {
        $dir = dirname(__DIR__, 2) . '/upload/2026/08/01';
        $filename = 'mutation-test-' . uniqid() . '.jpg';
        file_put_contents($dir . '/' . $filename, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==',
            true
        ));

        $this->conn->executeStatement(
            'INSERT INTO ' . 'images' . ' (file, path, md5sum) VALUES (?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename)]
        );
        $id = (int) $this->conn->lastInsertId();
        $this->throwawayImageIds[] = $id;

        foreach ($categoryIds as $catId) {
            $this->conn->executeStatement(
                'INSERT INTO ' . 'image_category' . ' (image_id, category_id) VALUES (?, ?)',
                [$id, $catId]
            );
        }

        return $id;
    }

    public function test_setPrivacyLevel_returns_ok(): void
    {
        $response = $this->callWs('pwg.images.setPrivacyLevel', [
            'image_id' => [self::FIXTURE_IMAGE_ID],
            'level'    => 0,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setPrivacyLevel_with_a_level_outside_the_available_set_returns_error(): void
    {
        // 'level' is only registered with WsParamType::INT|POSITIVE plus a
        // 'maxValue' (the highest of CurrentConfig::availablePermissionLevels(),
        // 8 by default) at the WS layer -- a too-high value is silently
        // *clamped* there (PwgServer::invoke()'s own maxValue handling), not
        // rejected, so this needs a value that's positive, <= 8, and simply
        // not one of the actual available levels ([0, 1, 2, 4, 8] by
        // default) to reach setPrivacyLevel()'s own in_array() guard.
        $response = $this->callWs('pwg.images.setPrivacyLevel', [
            'image_id' => [self::FIXTURE_IMAGE_ID],
            'level'    => 3,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid level', $response['message']);
    }

    public function test_setRank_returns_ok(): void
    {
        $response = $this->callWs('pwg.images.setRank', [
            'image_id'    => self::FIXTURE_IMAGE_ID,
            'category_id' => self::FIXTURE_CAT_ID,
            'rank'        => 1,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setRank_with_multiple_image_ids_reorders_the_category(): void
    {
        // piwigo_image_category is (image_id, category_id, rank) with no
        // separate id column -- fixture category 1 contains images 1, 2 and 3
        // (rows (1,1,1),(2,1,2),(3,1,3)). Passing all 3 -- count(image_id) > 1
        // takes the "reorder" branch instead of the single-image "set one
        // rank" branch below it -- and reading them all back keeps this
        // assertion exact rather than assuming only a subset is present.
        $response = $this->callWs('pwg.images.setRank', [
            'image_id'    => [3, 2, 1],
            'category_id' => self::FIXTURE_CAT_ID,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame(self::FIXTURE_CAT_ID, $result['category_id']);
        $imageIds = $result['image_id'];
        self::assertIsArray($imageIds);
        self::assertSame([3, 2, 1], array_values($imageIds));
    }

    public function test_setCategory_returns_ok(): void
    {
        $token    = $this->getPwgToken();
        $response = $this->callWs('pwg.images.setCategory', [
            'image_id'    => [self::FIXTURE_IMAGE_ID],
            'category_id' => self::FIXTURE_CAT_ID,
            'pwg_token'   => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setCategory_unknown_category_returns_404(): void
    {
        $response = $this->callWs('pwg.images.setCategory', [
            'image_id'    => [self::FIXTURE_IMAGE_ID],
            'category_id' => 999999,
            'pwg_token'   => $this->getPwgToken(),
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function test_setCategory_dissociate_removes_the_association(): void
    {
        $imageId = $this->insertThrowawayImage([self::FIXTURE_CAT_ID]);

        $response = $this->callWs('pwg.images.setCategory', [
            'image_id'    => [$imageId],
            'category_id' => self::FIXTURE_CAT_ID,
            'action'      => 'dissociate',
            'pwg_token'   => $this->getPwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        $remaining = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . 'image_category' . ' WHERE image_id = ? AND category_id = ?',
            [$imageId, self::FIXTURE_CAT_ID]
        );
        self::assertIsNumeric($remaining);
        self::assertSame(0, (int) $remaining);
    }

    public function test_setCategory_move_reassigns_the_association(): void
    {
        $otherCatId = self::FIXTURE_CAT_ID + 1;
        $imageId    = $this->insertThrowawayImage([self::FIXTURE_CAT_ID]);

        $response = $this->callWs('pwg.images.setCategory', [
            'image_id'    => [$imageId],
            'category_id' => $otherCatId,
            'action'      => 'move',
            'pwg_token'   => $this->getPwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);

        $stillInOld = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . 'image_category' . ' WHERE image_id = ? AND category_id = ?',
            [$imageId, self::FIXTURE_CAT_ID]
        );
        self::assertIsNumeric($stillInOld);
        self::assertSame(0, (int) $stillInOld);

        $nowInNew = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . 'image_category' . ' WHERE image_id = ? AND category_id = ?',
            [$imageId, $otherCatId]
        );
        self::assertIsNumeric($nowInNew);
        self::assertSame(1, (int) $nowInNew);
    }

    // delete()'s own preg_split() failure guard (image_id, the same
    // `/[\s,;\|]/` pattern used by several other Ws\PwgImages methods) is
    // unreachable from a black-box Contract test: preg_split() only
    // returns false on a genuine PCRE engine error, this pattern has no
    // quantifiers/groups to ever backtrack, and this test process (a
    // separate curl client) has no way to lower the *server* process's
    // pcre.backtrack_limit/pcre.recursion_limit to force one artificially
    // either -- see WsImagesTest's exist() section for the full writeup.

    public function test_delete_removes_the_image(): void
    {
        $imageId = $this->insertThrowawayImage();

        $response = $this->callWs('pwg.images.delete', [
            'image_id'  => (string) $imageId,
            'pwg_token' => $this->getPwgToken(),
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(1, $response['result']);

        $remaining = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . 'images' . ' WHERE id = ?', [$imageId]);
        self::assertIsNumeric($remaining);
        self::assertSame(0, (int) $remaining);
    }

    public function test_delete_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.images.delete', [
            'image_id'  => (string) self::FIXTURE_IMAGE_ID,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_addComment_response_matches_schema(): void
    {
        // The ephemeral key must come from the server; getInfo returns one in
        // result.comment_post_data.key (valid_after_seconds=2, so sleep first).
        $info       = $this->callWs('pwg.images.getInfo', ['image_id' => self::FIXTURE_IMAGE_ID]);
        $infoResult = $info['result'] ?? null;
        $key        = '';
        if (is_array($infoResult)) {
            $commentPost = $infoResult['comment_post'] ?? null;
            if (is_array($commentPost) && is_string($commentPost['key'] ?? null)) {
                $key = $commentPost['key'];
            }
        }
        sleep(3);

        $response = $this->callWs('pwg.images.addComment', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'author'   => 'ContractTest',
            'content'  => 'Automated contract test comment ' . uniqid(),
            'key'      => $key,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.images.addComment', $response);

        $result = $response['result'];
        self::assertIsArray($result);
        $comment = $result['comment'] ?? null;
        self::assertIsArray($comment);
        self::assertArrayHasKey('id', $comment);
    }

    public function test_addComment_with_an_invalid_key_is_rejected(): void
    {
        $response = $this->callWs('pwg.images.addComment', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'author'   => 'ContractTest',
            'content'  => 'Automated contract test comment ' . uniqid(),
            'key'      => 'not-a-real-ephemeral-key',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame(
            'Your comment has NOT been registered because it did not pass the validation rules',
            $response['message']
        );
    }

    public function test_emptyLounge_returns_rows(): void
    {
        $response = $this->callWs('pwg.images.emptyLounge', []);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('rows', $result);
    }

    public function test_rate_invalid_value_returns_fail(): void
    {
        // rate value 99 is not in rate_items ([1,2,3,4,5]); contract: returns stat=fail with err 403
        $response = $this->callWs('pwg.images.rate', [
            'image_id' => self::FIXTURE_IMAGE_ID,
            'rate'     => 99,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_rate_valid_value_returns_ok(): void
    {
        // rate() requires the image to be joined to at least one (non-forbidden)
        // category -- a throwaway image avoids nudging the shared fixture's
        // rating_score for image 1, which other test files also read.
        $imageId = $this->insertThrowawayImage([self::FIXTURE_CAT_ID]);

        $response = $this->callWs('pwg.images.rate', [
            'image_id' => $imageId,
            'rate'     => 4,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertIsArray($response['result']);
    }

    public function test_rate_on_unassociated_image_returns_404(): void
    {
        $imageId = $this->insertThrowawayImage();

        $response = $this->callWs('pwg.images.rate', [
            'image_id' => $imageId,
            'rate'     => 4,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('Invalid image_id or access denied', $response['message']);
    }
}
