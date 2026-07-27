<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsCategoriesMutationTest extends ContractTestCase
{
    private ?int $categoryId = null;

    private Connection $conn;

    /** @var list<int> */
    private array $categoryIdsToDelete = [];

    /** @var list<int> */
    private array $imageIdsToDelete = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->categoryId !== null) {
            $token = $this->getPwgToken();
            $this->callWs('pwg.categories.delete', [
                'category_id'         => $this->categoryId,
                'photo_deletion_mode' => 'no_delete',
                'pwg_token'           => $token,
            ]);
            $this->categoryId = null;
        }

        foreach ($this->imageIdsToDelete as $imageId) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
        foreach (array_reverse($this->categoryIdsToDelete) as $categoryId) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE category_id = ?', [$categoryId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        }

        parent::tearDown();
    }

    /** Creates a virtual category via the WS API and marks it for cleanup in tearDown(). */
    private function createCategory(string $name, ?int $parentId = null): int
    {
        $params = ['name' => $name];
        if ($parentId !== null) {
            $params['parent'] = $parentId;
        }

        $response = $this->callWs('pwg.categories.add', $params);
        $categoryId = $this->extractResultId($response);
        $this->categoryIdsToDelete[] = $categoryId;

        return $categoryId;
    }

    /**
     * Inserts a real throwaway image row (with a tiny real file on disk)
     * directly via SQL, tracked for cleanup in tearDown(). date_available
     * is set to a real timestamp (not the column's NULL default) since
     * several rollup queries elsewhere key "does this category have
     * images" off COUNT(date_available).
     */
    private function insertThrowawayImage(): int
    {
        $tmpDir = dirname(__DIR__, 2) . '/upload/2026/08/01';
        $filename = 'ws-categories-mutation-test-' . uniqid() . '.jpg';
        file_put_contents($tmpDir . '/' . $filename, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==',
            true
        ));

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum, date_available) VALUES (?, ?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename), '2026-08-01 00:00:00']
        );
        $id = (int) $this->conn->lastInsertId();
        $this->imageIdsToDelete[] = $id;

        return $id;
    }

    /**
     * Assigns deterministic, sequential `rank` values (1..N) to a list of
     * sibling category ids. categories.add()'s own rank assignment depends
     * on CurrentConfig::newcatDefaultPosition() (default 'first', which
     * leaves every new category's rank at 0), so creation order alone
     * doesn't guarantee any particular ORDER BY `rank` result -- setRank()
     * itself reads that same column to build its own before/after
     * ordering, so tests exercising it need a known starting point.
     *
     * @param list<int> $orderedIds
     */
    private function assignRanks(array $orderedIds): void
    {
        foreach ($orderedIds as $index => $categoryId) {
            $this->conn->executeStatement(
                'UPDATE ' . Tables::categories() . ' SET `rank` = ? WHERE id = ?',
                [$index + 1, $categoryId]
            );
        }
    }

    /**
     * @return list<int>
     */
    private function siblingOrder(int $parentId): array
    {
        $ids = $this->conn->fetchFirstColumn(
            'SELECT id FROM ' . Tables::categories() . ' WHERE id_uppercat = ? ORDER BY `rank` ASC',
            [$parentId]
        );

        return array_map(static function (mixed $id): int {
            self::assertIsNumeric($id);

            return (int) $id;
        }, $ids);
    }

    /**
     * Bypasses callWs()'s hard `assertLessThan(500, $status)` guard --
     * needed for branches where PwgError() itself uses a 4xx/5xx code
     * (PwgError's constructor sets a real HTTP status header for codes
     * 400-599), a genuine business-logic response here, not a real server
     * error.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callWsAllowingServerError(string $method, array $params): array
    {
        $url = $this->baseUrl . '/ws.php?format=json';
        $ch = curl_init($url);
        self::assertNotFalse($ch);

        $cookieJar = $this->cookieJar();
        assert($cookieJar !== '');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function test_add_returns_category_shape(): void
    {
        $response = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.add', $response);

        $this->categoryId = $this->extractResultId($response);
    }

    public function test_setInfo_updates_name(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $this->categoryId,
            'name'        => 'ct_album_renamed_' . $this->categoryId,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_setRepresentative_returns_ok(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        // Associate fixture image 1 with this album first
        $token = $this->getPwgToken();
        $this->callWs('pwg.images.setCategory', [
            'image_id'    => [1],
            'category_id' => $this->categoryId,
            'pwg_token'   => $token,
        ]);

        $response = $this->callWs('pwg.categories.setRepresentative', [
            'category_id' => $this->categoryId,
            'image_id'    => 1,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_deleteRepresentative_returns_ok(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        $response = $this->callWs('pwg.categories.deleteRepresentative', [
            'category_id' => $this->categoryId,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_move_returns_move_shape(): void
    {
        $token  = $this->getPwgToken();
        $parent = $this->callWs('pwg.categories.add', ['name' => 'ct_parent_' . uniqid()]);
        $child  = $this->callWs('pwg.categories.add', ['name' => 'ct_child_' . uniqid()]);
        $parentId = $this->extractResultId($parent);
        $childId  = $this->extractResultId($child);

        $response = $this->callWs('pwg.categories.move', [
            'category_id' => $childId,
            'parent'      => $parentId,
            'pwg_token'   => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.move', $response);

        // clean up
        $this->callWs('pwg.categories.delete', [
            'category_id'         => $parentId,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token'           => $token,
        ]);
    }

    public function test_calculateOrphans_returns_orphan_info(): void
    {
        $add = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $this->categoryId = $this->extractResultId($add);

        $response = $this->callWs('pwg.categories.calculateOrphans', [
            'category_id' => [$this->categoryId],
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.calculateOrphans', $response);
    }

    public function test_delete_returns_ok(): void
    {
        $add   = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);
        $id    = $this->extractResultId($add);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.delete', [
            'category_id'         => $id,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token'           => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        // already deleted
    }

    // ------------------------------------------------------------------ add

    public function test_add_rejects_a_blank_name(): void
    {
        $response = $this->callWsAllowingServerError('pwg.categories.add', ['name' => '   ']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
    }

    public function test_add_stores_the_provided_comment(): void
    {
        $add = $this->callWs('pwg.categories.add', [
            'name' => 'ct_album_' . uniqid(),
            'comment' => 'a real comment ' . uniqid(),
        ]);
        $this->categoryId = $this->extractResultId($add);

        $stored = $this->conn->fetchOne('SELECT comment FROM ' . Tables::categories() . ' WHERE id = ?', [$this->categoryId]);
        self::assertIsString($stored);
        self::assertStringContainsString('a real comment', $stored);
    }

    // --------------------------------------------------------------- setRank

    public function test_setRank_unknown_category_returns_404(): void
    {
        $response = $this->callWs('pwg.categories.setRank', ['category_id' => [999999]]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function test_setRank_single_category_moves_before_its_siblings(): void
    {
        $parentId = $this->createCategory('ct_rank_parent_' . uniqid());
        $child1 = $this->createCategory('ct_rank_child1_' . uniqid(), $parentId);
        $child2 = $this->createCategory('ct_rank_child2_' . uniqid(), $parentId);
        $child3 = $this->createCategory('ct_rank_child3_' . uniqid(), $parentId);
        $this->assignRanks([$child1, $child2, $child3]);
        self::assertSame([$child1, $child2, $child3], $this->siblingOrder($parentId));

        $response = $this->callWs('pwg.categories.setRank', ['category_id' => [$child3], 'rank' => 1]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([$child3, $child1, $child2], $this->siblingOrder($parentId));
    }

    public function test_setRank_multiple_categories_reorders_them_all(): void
    {
        $parentId = $this->createCategory('ct_rank_parent_' . uniqid());
        $child1 = $this->createCategory('ct_rank_child1_' . uniqid(), $parentId);
        $child2 = $this->createCategory('ct_rank_child2_' . uniqid(), $parentId);
        $child3 = $this->createCategory('ct_rank_child3_' . uniqid(), $parentId);
        $this->assignRanks([$child1, $child2, $child3]);
        self::assertSame([$child1, $child2, $child3], $this->siblingOrder($parentId));

        $response = $this->callWs('pwg.categories.setRank', ['category_id' => [$child3, $child1, $child2]]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([$child3, $child1, $child2], $this->siblingOrder($parentId));
    }

    public function test_setRank_multiple_categories_missing_a_sibling_returns_error(): void
    {
        $parentId = $this->createCategory('ct_rank_parent_' . uniqid());
        $child1 = $this->createCategory('ct_rank_child1_' . uniqid(), $parentId);
        $child2 = $this->createCategory('ct_rank_child2_' . uniqid(), $parentId);
        $this->createCategory('ct_rank_child3_' . uniqid(), $parentId);
        $this->assignRanks([$child1, $child2]);

        // 2 ids given, but the parent really has 3 children -- setRank()
        // only reaches this "count > 1" validation branch when more than
        // one id is provided.
        $response = $this->callWs('pwg.categories.setRank', ['category_id' => [$child2, $child1]]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('you need to provide all sub-category ids for a given category', $response['message']);
    }

    // -------------------------------------------------------------- setInfo

    public function test_setInfo_invalid_token_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.categories.setInfo', [
            'category_id' => $categoryId,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_setInfo_unknown_category_returns_404(): void
    {
        $response = $this->callWs('pwg.categories.setInfo', ['category_id' => 999999]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
    }

    public function test_setInfo_invalid_status_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $categoryId,
            'status' => 'bogus',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid status, only public/private', $response['message']);
    }

    public function test_setInfo_changes_status_to_private(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());
        $before = $this->conn->fetchOne('SELECT status FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        self::assertSame('public', $before);

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $categoryId,
            'status' => 'private',
        ]);

        self::assertSame('ok', $response['stat']);
        $after = $this->conn->fetchOne('SELECT status FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        self::assertSame('private', $after);
    }

    public function test_setInfo_invalid_visible_param_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $categoryId,
            'visible' => 'not-a-bool',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid param visible : not-a-bool', $response['message']);
    }

    public function test_setInfo_sets_visible_false(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());
        $before = $this->conn->fetchOne('SELECT visible FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        self::assertIsNumeric($before);
        self::assertSame(1, (int) $before);

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $categoryId,
            'visible' => 'false',
        ]);

        self::assertSame('ok', $response['stat']);
        $after = $this->conn->fetchOne('SELECT visible FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        self::assertIsNumeric($after);
        self::assertSame(0, (int) $after);
    }

    public function test_setInfo_sets_commentable_and_applies_to_subalbums(): void
    {
        $parentId = $this->createCategory('ct_commentable_parent_' . uniqid());
        $childId = $this->createCategory('ct_commentable_child_' . uniqid(), $parentId);

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $parentId,
            'commentable' => 'false',
            'apply_commentable_to_subalbums' => '1',
        ]);

        self::assertSame('ok', $response['stat']);
        $parentCommentable = $this->conn->fetchOne('SELECT commentable FROM ' . Tables::categories() . ' WHERE id = ?', [$parentId]);
        $childCommentable = $this->conn->fetchOne('SELECT commentable FROM ' . Tables::categories() . ' WHERE id = ?', [$childId]);
        self::assertIsNumeric($parentCommentable);
        self::assertIsNumeric($childCommentable);
        self::assertSame(0, (int) $parentCommentable);
        self::assertSame(0, (int) $childCommentable, 'apply_commentable_to_subalbums must propagate to the child');
    }

    public function test_setInfo_sets_commentable_without_subalbums(): void
    {
        $parentId = $this->createCategory('ct_commentable_parent_' . uniqid());
        $childId = $this->createCategory('ct_commentable_child_' . uniqid(), $parentId);

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $parentId,
            'commentable' => 'false',
        ]);

        self::assertSame('ok', $response['stat']);
        $parentCommentable = $this->conn->fetchOne('SELECT commentable FROM ' . Tables::categories() . ' WHERE id = ?', [$parentId]);
        $childCommentable = $this->conn->fetchOne('SELECT commentable FROM ' . Tables::categories() . ' WHERE id = ?', [$childId]);
        self::assertIsNumeric($parentCommentable);
        self::assertIsNumeric($childCommentable);
        self::assertSame(0, (int) $parentCommentable);
        self::assertSame(1, (int) $childCommentable, 'without apply_commentable_to_subalbums the child must be untouched');
    }

    // ------------------------------------------------------- setRepresentative

    public function test_setRepresentative_unknown_category_returns_404(): void
    {
        $response = $this->callWs('pwg.categories.setRepresentative', [
            'category_id' => 999999,
            'image_id' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('category_id not found', $response['message']);
    }

    public function test_setRepresentative_unknown_image_returns_404(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWs('pwg.categories.setRepresentative', [
            'category_id' => $categoryId,
            'image_id' => 999999,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('image_id not found', $response['message']);
    }

    // ---------------------------------------------------- deleteRepresentative

    public function test_deleteRepresentative_unknown_category_returns_404(): void
    {
        $response = $this->callWs('pwg.categories.deleteRepresentative', ['category_id' => 999999]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('category_id not found', $response['message']);
    }

    // ---------------------------------------------------- refreshRepresentative

    public function test_refreshRepresentative_unknown_category_returns_404(): void
    {
        $response = $this->callWsAllowingServerError('pwg.categories.refreshRepresentative', ['category_id' => 999999]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('category_id not found', $response['message']);
    }

    public function test_refreshRepresentative_no_images_returns_401(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.categories.refreshRepresentative', ['category_id' => $categoryId]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('not permitted', $response['message']);
    }

    public function test_refreshRepresentative_returns_new_representative_properties(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());
        $imageId = $this->insertThrowawayImage();
        $token = $this->getPwgToken();
        $assoc = $this->callWs('pwg.images.setCategory', [
            'image_id' => [$imageId],
            'category_id' => $categoryId,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $assoc['stat']);

        $response = $this->callWs('pwg.categories.refreshRepresentative', ['category_id' => $categoryId]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('src', $result);
        self::assertArrayHasKey('url', $result);

        $representativeId = $this->conn->fetchOne('SELECT representative_picture_id FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        self::assertIsNumeric($representativeId);
        self::assertSame($imageId, (int) $representativeId, 'the only associated image must become the new representative');
    }

    // ---------------------------------------------------------------- delete

    public function test_delete_invalid_token_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.categories.delete', [
            'category_id' => $categoryId,
            'photo_deletion_mode' => 'no_delete',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_delete_invalid_photo_deletion_mode_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());
        $token = $this->getPwgToken();

        $response = $this->callWsAllowingServerError('pwg.categories.delete', [
            'category_id' => $categoryId,
            'photo_deletion_mode' => 'bogus_mode',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(500, $response['err']);
        $message = $response['message'];
        self::assertIsString($message);
        self::assertStringContainsString('invalid parameter photo_deletion_mode', $message);
    }

    public function test_delete_with_only_non_positive_category_ids_returns_null(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.delete', [
            'category_id' => [0, -1],
            'photo_deletion_mode' => 'no_delete',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertNull($response['result']);
    }

    public function test_delete_with_unknown_category_id_returns_null(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.delete', [
            'category_id' => [999999],
            'photo_deletion_mode' => 'no_delete',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertNull($response['result']);
    }

    // ------------------------------------------------------------------ move

    public function test_move_invalid_token_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.categories.move', [
            'category_id' => $categoryId,
            'parent' => 0,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_move_with_no_valid_category_ids_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.move', [
            'category_id' => [0, -1],
            'parent' => 0,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid category_id input parameter, no category to move', $response['message']);
    }

    public function test_move_unknown_category_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.move', [
            'category_id' => [999999],
            'parent' => 0,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Category 999999 does not exist', $response['message']);
    }

    public function test_move_unknown_parent_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.move', [
            'category_id' => $categoryId,
            'parent' => 999999,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Unknown parent category id', $response['message']);
    }

    public function test_move_physical_category_returns_error(): void
    {
        $categoryId = $this->createCategory('ct_physical_' . uniqid());
        // move() only refuses categories with a non-empty `dir` (a real,
        // filesystem-synced category) -- categories.add() always creates a
        // virtual one (dir=NULL), so simulating a physical category needs a
        // direct raw-SQL write; this only exercises move()'s own guard, it
        // doesn't pretend to be a real filesystem sync.
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET dir = ? WHERE id = ?', ['some_physical_dir', $categoryId]);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.move', [
            'category_id' => $categoryId,
            'parent' => 0,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        $message = $response['message'];
        self::assertIsString($message);
        self::assertStringContainsString('is not a virtual category, you cannot move it', $message);
    }

    // -------------------------------------------------------- calculateOrphans

    public function test_calculateOrphans_counts_images_that_would_become_orphan(): void
    {
        $categoryId = $this->createCategory('ct_orphans_' . uniqid());
        $orphanImageId = $this->insertThrowawayImage();
        $sharedImageId = $this->insertThrowawayImage();

        $token = $this->getPwgToken();
        // orphanImageId belongs only to this category -- deleting it would
        // orphan the photo. sharedImageId is also associated with fixture
        // category 1, so it would NOT become an orphan.
        $this->callWs('pwg.images.setCategory', [
            'image_id' => [$orphanImageId, $sharedImageId],
            'category_id' => $categoryId,
            'pwg_token' => $token,
        ]);
        $this->callWs('pwg.images.setCategory', [
            'image_id' => [$sharedImageId],
            'category_id' => 1,
            'pwg_token' => $token,
        ]);

        $response = $this->callWs('pwg.categories.calculateOrphans', [
            'category_id' => [$categoryId],
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $first = $result[0] ?? null;
        self::assertIsArray($first);
        self::assertSame(2, $first['nb_images_recursive']);
        self::assertSame(1, $first['nb_images_associated_outside']);
        self::assertSame(1, $first['nb_images_becoming_orphan']);

        // clean up the extra association on the shared fixture category
        $this->callWs('pwg.images.setCategory', [
            'image_id' => [$sharedImageId],
            'category_id' => 1,
            'action' => 'dissociate',
            'pwg_token' => $token,
        ]);
    }

    /**
     * Extracts and int-casts the 'id' field from a WS mutation response's
     * 'result' object (e.g. pwg.categories.add), narrowing the otherwise
     * mixed decoded-JSON response so it can flow into typed contexts (the
     * $categoryId property, subsequent WS call params, etc). Fails the test
     * with a diagnostic message if the response doesn't have the expected
     * shape, rather than silently producing a bogus 0.
     *
     * @param array<string, mixed> $response
     */
    private function extractResultId(array $response): int
    {
        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            $encoded = json_encode($response);
            self::fail('WS response result is not an array: ' . ($encoded === false ? 'null' : $encoded));
        }

        $id = $result['id'] ?? null;
        if (!is_numeric($id)) {
            $encoded = json_encode($response);
            self::fail('WS response result.id is not numeric: ' . ($encoded === false ? 'null' : $encoded));
        }

        return (int) $id;
    }
}
