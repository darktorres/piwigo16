<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
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
        $rank = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        foreach ($orderedIds as $index => $categoryId) {
            $this->conn->executeStatement(
                "UPDATE " . Tables::categories() . " SET {$rank} = ? WHERE id = ?",
                [$index + 1, $categoryId]
            );
        }
    }

    /**
     * @return list<int>
     */
    private function siblingOrder(int $parentId): array
    {
        $rankIdentifier = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier('rank');
        $ids = $this->conn->fetchFirstColumn(
            "SELECT id FROM " . Tables::categories() . " WHERE id_uppercat = ? ORDER BY {$rankIdentifier} ASC",
            [$parentId]
        );

        return array_map(static function (mixed $id): int {
            self::assertIsNumeric($id);

            return (int) $id;
        }, $ids);
    }

    public function test_add_returns_category_shape(): void
    {
        $response = $this->callWs('pwg.categories.add', ['name' => 'ct_album_' . uniqid()]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.add', $response);

        $this->categoryId = $this->extractResultId($response);
    }

    public function test_add_with_an_explicit_invalid_token_returns_error(): void
    {
        // pwg_token is WsParamFlag::OPTIONAL for add() -- every other add()
        // test omits it entirely; this exercises the branch where it's
        // present but wrong.
        $response = $this->callWs('pwg.categories.add', [
            'name' => 'ct_album_' . uniqid(),
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
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

    /**
     * A requested rank beyond the sibling count never matches `$i` inside
     * the old-order loop -- $was_inserted stays false, and the category is
     * appended at the very end instead (rather than left out entirely).
     */
    public function test_setRank_single_category_with_a_rank_beyond_siblings_appends_it_last(): void
    {
        $parentId = $this->createCategory('ct_rank_parent_' . uniqid());
        $child1 = $this->createCategory('ct_rank_child1_' . uniqid(), $parentId);
        $child2 = $this->createCategory('ct_rank_child2_' . uniqid(), $parentId);
        $this->assignRanks([$child1, $child2]);
        self::assertSame([$child1, $child2], $this->siblingOrder($parentId));

        $response = $this->callWs('pwg.categories.setRank', ['category_id' => [$child1], 'rank' => 99]);

        self::assertSame('ok', $response['stat']);
        self::assertSame([$child2, $child1], $this->siblingOrder($parentId));
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

    /**
     * `visible`/`commentable` are genuine boolean columns -- native bool
     * on Postgres, numeric on MySQL (same recurring raw-fetch
     * normalization gap fixed throughout this pgsql support pass).
     */
    private function fetchCategoryBoolColumn(string $column, int $categoryId): int
    {
        $value = $this->conn->fetchOne('SELECT ' . $column . ' FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        self::assertTrue(is_bool($value) || is_numeric($value), $column . ' must be a boolean or numeric value');

        return (int) (bool) $value;
    }

    public function test_setInfo_sets_visible_false(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());
        self::assertSame(1, $this->fetchCategoryBoolColumn('visible', $categoryId));

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $categoryId,
            'visible' => 'false',
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame(0, $this->fetchCategoryBoolColumn('visible', $categoryId));
    }

    /**
     * setInfo()'s `$info_columns = ['name', 'comment']` shared loop is only
     * ever exercised via the 'name' element elsewhere in this file
     * (test_setInfo_updates_name) -- 'comment' has its own isset()/
     * strip_tags()-vs-allowHtmlDescriptions() branch through the exact same
     * loop iteration and was never independently verified.
     */
    public function test_setInfo_updates_comment(): void
    {
        $categoryId = $this->createCategory('ct_album_' . uniqid());

        $response = $this->callWs('pwg.categories.setInfo', [
            'category_id' => $categoryId,
            'comment' => 'a fresh comment ' . uniqid(),
        ]);

        self::assertSame('ok', $response['stat']);
        $stored = $this->conn->fetchOne('SELECT comment FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        self::assertIsString($stored);
        self::assertStringContainsString('a fresh comment', $stored);
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
        self::assertSame(0, $this->fetchCategoryBoolColumn('commentable', $parentId));
        self::assertSame(0, $this->fetchCategoryBoolColumn('commentable', $childId), 'apply_commentable_to_subalbums must propagate to the child');
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
        self::assertSame(0, $this->fetchCategoryBoolColumn('commentable', $parentId));
        self::assertSame(1, $this->fetchCategoryBoolColumn('commentable', $childId), 'without apply_commentable_to_subalbums the child must be untouched');
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

    /**
     * allow_random_representative defaults to false (not present in the
     * fixture config table, so CurrentConfig falls back to its own
     * default) -- deleteRepresentative() refuses to clear the
     * representative of a category that still has direct images, since it
     * would have no random substitute to fall back to for the thumbnail.
     * test_deleteRepresentative_returns_ok above only covers the opposite
     * (no images) path.
     */
    public function test_deleteRepresentative_with_images_and_random_representative_disallowed_returns_401(): void
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

        $response = $this->callWsAllowingServerError('pwg.categories.deleteRepresentative', [
            'category_id' => $categoryId,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('not permitted', $response['message']);
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

    // refreshRepresentative()'s own `$representative_picture_id === null`
    // guard (-> `PwgError(500, 'unable to determine a new representative
    // picture for this category')`) is NOT chased: `setRandomRepresentant()`
    // (CategoryService::setRandomRepresentant() ->
    // CategoryRepository::findRandomImageIdInCategory()) queries
    // `image_category` with the exact same `category_id = X` condition
    // `hasImages()` already checked immediately above it (also a bare query
    // against `image_category`, no other filter) -- if `hasImages()` is
    // true there is necessarily at least one row for
    // `findRandomImageIdInCategory()`'s `ORDER BY RAND() LIMIT 1` to pick
    // (`image_id` is a NOT NULL column), so the representative can't still
    // be null afterwards within one synchronous request. The legacy
    // include/ws_functions/pwg.categories.php equivalent
    // (ws_categories_refreshRepresentative()) had no such guard at all --
    // it read `representative_picture_id` straight off the re-fetched row
    // with no null check -- confirming this is a rewrite-added guard for
    // getCategoryRepresentantProperties()'s stricter `int` parameter type,
    // not a reachable real-world branch.

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

    // delete()'s and move()'s own `preg_split() === false` guards (both the
    // same `/[\s,;\|]/` pattern used by several Ws\PwgImages methods, for
    // the scalar-string `category_id` branch) are unreachable from a
    // black-box Contract test: preg_split() only returns false on a genuine
    // PCRE engine error, this pattern has no quantifiers/groups to ever
    // backtrack, and this test process (a separate curl client) has no way
    // to lower the *server* process's pcre.backtrack_limit/
    // pcre.recursion_limit to force one artificially either -- see
    // WsImagesTest's exist() section (and WsImagesMutationTest's delete()
    // section) for the full writeup, and MetadataServiceTest for the
    // Integration-level technique that only works because that test runs
    // in-process against the real PHP settings instead of over HTTP.

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

    /**
     * CategoryService::moveCategories()'s own uppercats-prefix guard (`you
     * cannot move a category into a sub-category or itself`) records its
     * failure onto PageState::current() via addError() rather than
     * returning it directly -- move()'s own `$pageState->hasErrors()` check
     * afterwards is what turns that into the WS-level PwgError this test
     * verifies. Genuinely reachable via a real WS call: attempting to move
     * a parent album into its own child.
     */
    public function test_move_into_its_own_subcategory_returns_error(): void
    {
        $parentId = $this->createCategory('ct_move_into_own_child_' . uniqid());
        $childId = $this->createCategory('ct_move_into_own_child_sub_' . uniqid(), $parentId);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.categories.move', [
            'category_id' => $parentId,
            'parent' => $childId,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('You cannot move an album in its own sub album', $response['message']);
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
     * calculateOrphans()'s "else" branch (`$category['nb_images_recursive']
     * >= 1000`) recomputes the same orphan/associated-outside split in PHP
     * (array_flip()+isset()+array_diff()) instead of delegating to MySQL
     * (CategoryRepository::findNonOrphanImageIds()/
     * findImageIdsOutsideCategories(), the branch
     * test_calculateOrphans_counts_images_that_would_become_orphan() above
     * exercises) -- a genuinely different code path, only reachable with a
     * real fixture at or above that threshold. Builds 1000 real `images`
     * rows via a single `WITH RECURSIVE` bulk INSERT (not 1000 individual
     * round trips -- image_category.image_id has a real FK to images.id,
     * ON DELETE CASCADE, see the fk_image_category_image_id constraint in
     * install/piwigo_structure-mysql.sql, so bare image_category rows
     * aren't an option), 4 of which are additionally associated with
     * fixture category 1 so they count as "associated outside" rather than
     * "becoming orphan".
     */
    public function test_calculateOrphans_at_1000_images_uses_the_php_based_computation(): void
    {
        $categoryId = $this->createCategory('ct_orphans_bulk_' . uniqid());
        $prefix = 'ct_orphans_bulk_' . uniqid() . '_';

        try {
            // pgsql support pass: real bug found live -- a bound
            // parameter appearing only inside a function call argument
            // (CONCAT(?, n)) gives Postgres nothing to infer its type
            // from ("could not determine data type of parameter $1"),
            // confirmed live via a real PREPARE/EXECUTE against this
            // exact table. An explicit ::text cast resolves it
            // unconditionally; MySQL has no such ambiguity to begin with
            // and accepts the bare parameter unchanged.
            $textCast = $this->dbDriver === 'pgsql' ? '::text' : '';
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::images() . " (file, path, md5sum, date_available)
                 SELECT CONCAT(?{$textCast}, n), CONCAT(?{$textCast}, n), MD5(CONCAT(?{$textCast}, n)), ?
                 FROM (
                     WITH RECURSIVE seq AS (
                         SELECT 1 AS n
                         UNION ALL
                         SELECT n + 1 FROM seq WHERE n < 1000
                     )
                     SELECT n FROM seq
                 ) bulk_seq",
                [$prefix, $prefix, $prefix, '2026-08-01 00:00:00']
            );

            $imageIds = $this->conn->fetchFirstColumn(
                'SELECT id FROM ' . Tables::images() . ' WHERE file LIKE ? ORDER BY id',
                [$prefix . '%']
            );
            self::assertCount(1000, $imageIds, 'bulk insert must have produced exactly 1000 real image rows');

            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id)
                 SELECT id, ? FROM ' . Tables::images() . ' WHERE file LIKE ?',
                [$categoryId, $prefix . '%']
            );

            // Only the first 4 images also get a link to fixture category 1
            // -- these are the ones expected in nb_images_associated_outside,
            // the remaining 996 are only ever linked to $categoryId and are
            // expected in nb_images_becoming_orphan.
            $sharedImageIds = array_slice($imageIds, 0, 4);
            foreach ($sharedImageIds as $imageId) {
                self::assertIsNumeric($imageId);
                $this->conn->executeStatement(
                    'INSERT INTO ' . Tables::imageCategory() . ' (image_id, category_id) VALUES (?, ?)',
                    [(int) $imageId, 1]
                );
            }

            $response = $this->callWs('pwg.categories.calculateOrphans', [
                'category_id' => [$categoryId],
            ]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $first = $result[0] ?? null;
            self::assertIsArray($first);
            self::assertSame(1000, $first['nb_images_recursive']);
            self::assertSame(4, $first['nb_images_associated_outside']);
            self::assertSame(996, $first['nb_images_becoming_orphan']);
        } finally {
            // images.id -> image_category.image_id is ON DELETE CASCADE, so
            // this single statement also removes both image_category rows
            // per shared image (the $categoryId link and the fixture
            // category 1 link) as well as the 996 orphan-only links.
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE file LIKE ?', [$prefix . '%']);
        }
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
