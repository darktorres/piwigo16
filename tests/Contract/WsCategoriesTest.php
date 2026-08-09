<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;
use Piwigo\Cache\CachePools;
use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsCategoriesTest extends ContractTestCase
{
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
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->imageIdsToDelete as $imageId) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }
        foreach (array_reverse($this->categoryIdsToDelete) as $categoryId) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::imageCategory() . ' WHERE category_id = ?', [$categoryId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::categories() . ' WHERE id = ?', [$categoryId]);
        }
        // A test may have overridden category 1's image_order via raw SQL --
        // restore it so later tests (in this file or any other Contract
        // test file sharing the same per-process fixture) see the fixture's
        // original NULL.
        $this->conn->executeStatement('UPDATE ' . Tables::categories() . ' SET image_order = NULL WHERE id = 1');

        parent::tearDown();
    }

    /** Creates a virtual category via the WS API and marks it for cleanup in tearDown(). */
    private function createCategory(string $name, ?int $parentId = null): int
    {
        $params = ['name' => $name];
        if ($parentId !== null) {
            $params['parent'] = $parentId;
        }

        $response = $this->wsAdmin('pwg.categories.add', $params);
        self::assertSame('ok', $response['stat'], 'category creation failed: ' . json_encode($response));
        $result = $response['result'];
        self::assertIsArray($result);
        $id = $result['id'] ?? null;
        self::assertIsNumeric($id);
        $categoryId = (int) $id;
        $this->categoryIdsToDelete[] = $categoryId;

        return $categoryId;
    }

    /**
     * Inserts a real throwaway image row (with a tiny real file on disk, so
     * DerivativeImage::url()/getimagesize() calls have something real to
     * read) directly via SQL, tracked for cleanup in tearDown().
     */
    private function insertThrowawayImage(int $level = 0): int
    {
        $tmpDir = dirname(__DIR__, 2) . '/upload/2026/08/01';
        $filename = 'ws-categories-test-' . uniqid() . '.jpg';
        file_put_contents($tmpDir . '/' . $filename, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==',
            true
        ));

        // date_available must be a real timestamp, not the column's own
        // NULL default -- CategoryRepository::findComputedCategoriesRollup()
        // computes nb_images as COUNT(date_available), so a NULL here makes
        // the rollup treat the category as having zero images, which in
        // turn makes it invisible to non-admin viewers (EffectiveForbidden
        // CategoriesCache's "feature 1053" zero-image exclusion) even
        // though the image is really associated.
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum, level, date_available) VALUES (?, ?, ?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename), $level, '2026-08-01 00:00:00']
        );
        $id = (int) $this->conn->lastInsertId();
        $this->imageIdsToDelete[] = $id;

        return $id;
    }

    public function test_getList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.categories.getList', ['recursive' => 1]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('categories.getList', $response);
    }

    public function test_getList_returns_array_of_categories(): void
    {
        $response = $this->wsAdmin('pwg.categories.getList', ['recursive' => 1]);

        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            $encoded = json_encode($response);
            self::fail('WS response result is not an array: ' . ($encoded === false ? 'null' : $encoded));
        }

        $categories = $result['categories'] ?? null;
        if (!is_array($categories)) {
            $encoded = json_encode($response);
            self::fail('WS response result.categories is not an array: ' . ($encoded === false ? 'null' : $encoded));
        }
        self::assertNotEmpty($categories, 'Fixture must contain at least one album');

        $first = $categories[0] ?? null;
        if (!is_array($first)) {
            $encoded = json_encode($response);
            self::fail('First category is not an array: ' . ($encoded === false ? 'null' : $encoded));
        }
        self::assertIsInt($first['id'] ?? null);
        self::assertIsString($first['name'] ?? null);
        self::assertIsInt($first['nb_images'] ?? null);
        self::assertIsString($first['url'] ?? null);
    }

    public function test_getAdminList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.categories.getAdminList', ['recursive' => 1]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('categories.getAdminList', $response);
    }

    public function test_getAdminList_is_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.categories.getAdminList');

        self::assertSame('fail', $response['stat']);
        self::assertArrayHasKey('err', $response);
    }

    public function test_getImages_response_matches_schema(): void
    {
        // cat_id=1 is the first album seeded in the fixture
        $response = $this->wsAdmin('pwg.categories.getImages', ['cat_id' => 1]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.categories.getImages', $response);
    }

    public function test_getImages_returns_paging_and_image_array(): void
    {
        $response = $this->wsAdmin('pwg.categories.getImages', ['cat_id' => 1]);

        $result = $response['result'] ?? null;
        if (!is_array($result)) {
            $encoded = json_encode($response);
            self::fail('WS response result is not an array: ' . ($encoded === false ? 'null' : $encoded));
        }
        self::assertArrayHasKey('paging', $result);
        self::assertArrayHasKey('images', $result);
        self::assertIsArray($result['images']);
    }

    /**
     * getList()'s `public: true` branch computes the guest identity's
     * forbidden categories via UserService::getUserData(). A private
     * album must not appear here even though the caller is authenticated
     * as admin, since `public: true` intentionally asks "what would an
     * anonymous visitor see."
     */
    public function test_getList_public_true_excludes_a_private_album(): void
    {
        $created = $this->wsAdmin('pwg.categories.add', [
            'name' => 'Stage 4h Private Album',
            'status' => 'private',
        ]);
        self::assertSame('ok', $created['stat']);
        $result = $created['result'] ?? null;
        self::assertIsArray($result);
        $catId = $result['id'] ?? null;
        self::assertIsInt($catId);
        $this->categoryIdsToDelete[] = $catId;

        $response = $this->wsAdmin('pwg.categories.getList', ['recursive' => 1, 'public' => true]);
        self::assertSame('ok', $response['stat']);

        $result = $response['result'] ?? null;
        self::assertIsArray($result);
        $categories = $result['categories'] ?? null;
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertNotContains($catId, $ids, 'public:true must hide a private album even for an admin session');
    }

    /**
     * getList()'s "normal" (authenticated, non-admin) branch reads
     * CurrentUser::forbiddenCategories directly. regular_user has no
     * explicit grant on the private album created above, so it must
     * stay hidden.
     */
    public function test_getList_as_regular_user_excludes_a_private_album_without_access(): void
    {
        $created = $this->wsAdmin('pwg.categories.add', [
            'name' => 'Stage 4h Private Album For Regular User',
            'status' => 'private',
        ]);
        self::assertSame('ok', $created['stat']);
        $result = $created['result'] ?? null;
        self::assertIsArray($result);
        $catId = $result['id'] ?? null;
        self::assertIsInt($catId);
        $this->categoryIdsToDelete[] = $catId;

        $login = $this->callWs('pwg.session.login', [
            'username' => 'regular_user',
            'password' => 'regular_user_pass',
        ]);
        self::assertSame('ok', $login['stat']);

        $response = $this->callWs('pwg.categories.getList', ['recursive' => 1]);
        self::assertSame('ok', $response['stat']);

        $result = $response['result'] ?? null;
        self::assertIsArray($result);
        $categories = $result['categories'] ?? null;
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertNotContains($catId, $ids, 'a non-admin user with no grant must not see a private album');
    }

    // ------------------------------------------------------------ getImages

    public function test_getImages_missing_cat_id_returns_404(): void
    {
        $response = $this->wsAdmin('pwg.categories.getImages', ['cat_id' => [999999]]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('cat_id {999999} not found', $response['message']);
    }

    public function test_getImages_recursive_true_includes_subcategory_images(): void
    {
        // cat_id=1 (Sample Album) has category 2 (Nested Sub Album) as a
        // real fixture child, holding images 4/5 -- recursive=true must
        // pull those in via the uppercats regex where-clause, not just
        // category 1's own images (1,2,3).
        $response = $this->wsAdmin('pwg.categories.getImages', ['cat_id' => [1], 'recursive' => true]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);

        $ids = array_column($images, 'id');
        self::assertContains(1, $ids);
        self::assertContains(4, $ids, 'recursive=true must include subcategory 2\'s images');
    }

    public function test_getImages_single_cat_id_uses_its_own_image_order(): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::categories() . ' SET image_order = ? WHERE id = 1',
            ['file DESC']
        );

        $response = $this->wsAdmin('pwg.categories.getImages', ['cat_id' => [1]]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);

        $ids = array_column($images, 'id');
        self::assertSame([3, 2, 1], $ids, 'category 1\'s own image_order (file DESC) must be used when no order param is given');
    }

    // getImages()'s own `! is_int($image_id) { continue; }` guard (built
    // from `$img['id'] ?? null`, right after the categories-of-image lookup
    // is populated) and its sibling `! isset($categories_of_image[$image_id])
    // { continue; }` guard are NOT chased here, for two different reasons:
    //
    // - `$img['id']` is always a real int by the time that loop runs: `id`
    //   is images' NOT NULL auto-increment PK, selected wholesale via `i.*`
    //   (ImageRepository::findWithConditionsPaginated()) and unconditionally
    //   cast by the `foreach (['id', 'width', 'height', 'hit'] as $k)` loop
    //   a few dozen lines above (same "native-int NOT NULL PK" reasoning as
    //   SearchServiceTest's own documented residual for
    //   qsearchGetTags()/qsearchGetCategories()). The pre-rewrite
    //   include/ws_functions/pwg.categories.php never had this guard at
    //   all -- it's a rewrite-added PHPStan narrowing check, not a branch
    //   the original authors ever observed taken.
    // - the `categories_of_image` guard IS inherited from that same legacy
    //   file, unchanged (down to its own "it should not be possible at this
    //   point, but let's consider a photo can be in no album" comment). But
    //   every image that reaches this loop matched the earlier
    //   `category_id IN (keys of $cats)` WHERE clause, and $cats itself was
    //   already filtered by the identical `forbidden_categories` condition
    //   reused (unmodified) for the `$image_category_rows` query below --
    //   so the specific category link that made the image appear at all is
    //   provably still present in `$categories_of_image` afterwards, within
    //   one request/one transaction. Both guards defend against a
    //   cross-request data race (the association being deleted between the
    //   two queries), not something a single synchronous Contract-test call
    //   can produce.

    // -------------------------------------------------------------- getList

    public function test_getList_invalid_thumbnail_size_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.categories.getList', ['thumbnail_size' => 'not-a-real-size']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Invalid thumbnail_size', $response['message']);
    }

    public function test_getList_recursive_and_limit_together_returns_error(): void
    {
        $response = $this->wsAdmin('pwg.categories.getList', ['recursive' => true, 'limit' => 5]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Cannot use both recursive and limit parameters at the same time', $response['message']);
    }

    public function test_getList_non_recursive_returns_only_root_categories(): void
    {
        $response = $this->wsAdmin('pwg.categories.getList');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertContains(1, $ids);
        self::assertNotContains(2, $ids, 'non-recursive default (cat_id=0) must return only root categories');
    }

    public function test_getList_non_recursive_with_cat_id_includes_the_category_and_its_children(): void
    {
        $response = $this->wsAdmin('pwg.categories.getList', ['cat_id' => 1]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);
    }

    public function test_getList_recursive_with_cat_id_filters_by_uppercats(): void
    {
        $response = $this->wsAdmin('pwg.categories.getList', ['cat_id' => 1, 'recursive' => true]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);
    }

    public function test_getList_search_filters_by_name(): void
    {
        $unique = 'UniqueSearchable' . uniqid();
        $this->createCategory($unique);

        $response = $this->wsAdmin('pwg.categories.getList', ['recursive' => true, 'search' => $unique]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        self::assertCount(1, $categories);
        $first = $categories[0];
        self::assertIsArray($first);
        self::assertSame($unique, $first['name_raw'] ?? null);
    }

    public function test_getList_limit_returns_metadata_reflecting_added_root_categories(): void
    {
        $before = $this->wsAdmin('pwg.categories.getList', ['limit' => 100000]);
        self::assertSame('ok', $before['stat']);
        $beforeResult = $before['result'];
        self::assertIsArray($beforeResult);
        $beforeLimit = $beforeResult['limit'];
        self::assertIsArray($beforeLimit);
        $totalBefore = $beforeLimit['total_cats'];
        self::assertIsInt($totalBefore);

        $this->createCategory('ct_root_a_' . uniqid());
        $this->createCategory('ct_root_b_' . uniqid());

        $after = $this->wsAdmin('pwg.categories.getList', ['limit' => 100000]);
        self::assertSame('ok', $after['stat']);
        $afterResult = $after['result'];
        self::assertIsArray($afterResult);
        $afterLimit = $afterResult['limit'];
        self::assertIsArray($afterLimit);

        self::assertSame(100000, $afterLimit['limited_to']);
        self::assertSame($totalBefore + 2, $afterLimit['total_cats']);
        self::assertSame(0, $afterLimit['remaining_cats']);
    }

    public function test_getList_limit_with_cat_id_counts_only_the_subtree_excluding_self(): void
    {
        $parentId = $this->createCategory('ct_limit_parent_' . uniqid());
        $this->createCategory('ct_limit_child_1_' . uniqid(), $parentId);
        $this->createCategory('ct_limit_child_2_' . uniqid(), $parentId);
        $this->createCategory('ct_limit_child_3_' . uniqid(), $parentId);

        $response = $this->wsAdmin('pwg.categories.getList', ['cat_id' => $parentId, 'limit' => 1]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $limit = $result['limit'];
        self::assertIsArray($limit);

        self::assertSame(1, $limit['limited_to']);
        self::assertSame(3, $limit['total_cats'], 'total_cats must exclude the parent itself when cat_id > 0');
        self::assertSame(2, $limit['remaining_cats']);
    }

    public function test_getList_fullname_true_uses_display_name_cache(): void
    {
        $parentName = 'FullnameParent' . uniqid();
        $childName = 'FullnameChild' . uniqid();
        $parentId = $this->createCategory($parentName);
        $childId = $this->createCategory($childName, $parentId);

        $response = $this->wsAdmin('pwg.categories.getList', ['cat_id' => $parentId, 'recursive' => true, 'fullname' => true]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $byId = [];
        foreach ($categories as $cat) {
            self::assertIsArray($cat);
            $catId = $cat['id'] ?? null;
            self::assertIsInt($catId);
            $byId[$catId] = $cat;
        }
        self::assertArrayHasKey($childId, $byId);
        self::assertSame($parentName . ' / ' . $childName, $byId[$childId]['name']);
    }

    /**
     * When a category has no directly-set representative
     * (neither a cached user override nor a real representative_picture_id
     * column value) and allowRandomRepresentative() is false (the default),
     * getList() falls back to searching sub-categories for a representative
     * picture instead.
     */
    public function test_getList_finds_representative_from_subcategory_when_none_set_directly(): void
    {
        $parentId = $this->createCategory('ct_subrep_parent_' . uniqid());
        $childId = $this->createCategory('ct_subrep_child_' . uniqid(), $parentId);

        $token = $this->getPwgToken();
        $assoc = $this->callWs('pwg.images.setCategory', [
            'image_id' => [1],
            'category_id' => $childId,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $assoc['stat']);

        $response = $this->wsAdmin('pwg.categories.getList', ['cat_id' => $parentId, 'recursive' => false]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $byId = [];
        foreach ($categories as $cat) {
            self::assertIsArray($cat);
            $catId = $cat['id'] ?? null;
            self::assertIsInt($catId);
            $byId[$catId] = $cat;
        }
        self::assertArrayHasKey($parentId, $byId);
        self::assertSame(1, $byId[$parentId]['representative_picture_id'], 'parent with no direct representative must fall back to a subcategory image');
    }

    /**
     * allow_random_representative defaults to false -- when enabled, a
     * category with no representative_picture_id/user_representative_
     * picture_id of its own picks one at random from its own images
     * (CategoryService::getRandomImageInCategory()), a distinct branch
     * from the "fall back to a subcategory" one above.
     */
    public function test_getList_picks_a_random_representative_when_enabled_and_none_is_set(): void
    {
        $this->upsertConfig('allow_random_representative', 'true');
        CachePools::config()->clear();

        try {
            $catId = $this->createCategory('ct_randomrep_' . uniqid());
            $token = $this->getPwgToken();
            $assoc = $this->callWs('pwg.images.setCategory', [
                'image_id' => [1],
                'category_id' => $catId,
                'pwg_token' => $token,
            ]);
            self::assertSame('ok', $assoc['stat']);

            $response = $this->wsAdmin('pwg.categories.getList', ['cat_id' => $catId, 'recursive' => false]);

            self::assertSame('ok', $response['stat']);
            $result = $response['result'];
            self::assertIsArray($result);
            $categories = $result['categories'];
            self::assertIsArray($categories);
            $entry = $categories[0] ?? null;
            self::assertIsArray($entry);
            self::assertSame(1, $entry['representative_picture_id'], 'only image in the category, so the random pick is deterministic');
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'allow_random_representative'");
            CachePools::config()->clear();
        }
    }

    /**
     * A representative image whose privacy level exceeds the
     * viewing user's own level must not be shown to that user -- getList()
     * substitutes a random lower-level image from the same category
     * instead (management-of-album-thumbnail block).
     */
    public function test_getList_substitutes_representative_above_viewer_privacy_level(): void
    {
        $categoryId = $this->createCategory('ct_privacy_' . uniqid());
        $highLevelImageId = $this->insertThrowawayImage(level: 5);
        $lowLevelImageId = $this->insertThrowawayImage(level: 0);

        $this->conn->executeStatement(
            'UPDATE ' . Tables::categories() . ' SET representative_picture_id = ? WHERE id = ?',
            [$highLevelImageId, $categoryId]
        );
        $token = $this->getPwgToken();
        $assoc = $this->callWs('pwg.images.setCategory', [
            'image_id' => [$lowLevelImageId],
            'category_id' => $categoryId,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $assoc['stat']);

        // CategoryTreeCache's per-user rollup (nb_images/count_images) has
        // a 300s TTL and nothing above invalidates it (unlike
        // PermissionCacheInvalidator, which only clears the permissions/
        // effective-permissions pools) -- clear it explicitly so
        // regular_user's getList() call below sees the association just
        // made, not a stale pre-association snapshot.
        CachePools::categoryTree()->clear();

        $login = $this->callWs('pwg.session.login', [
            'username' => 'regular_user',
            'password' => 'regular_user_pass',
        ]);
        self::assertSame('ok', $login['stat']);

        $response = $this->callWs('pwg.categories.getList', ['cat_id' => $categoryId, 'recursive' => false]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $byId = [];
        foreach ($categories as $cat) {
            self::assertIsArray($cat);
            $catId = $cat['id'] ?? null;
            self::assertIsInt($catId);
            $byId[$catId] = $cat;
        }
        self::assertArrayHasKey($categoryId, $byId);
        $tnUrl = $byId[$categoryId]['tn_url'] ?? null;
        self::assertIsString($tnUrl);
        self::assertStringContainsString($this->imageFilePath($lowLevelImageId), $tnUrl, 'tn_url must point at the substitute (low-level) image');
        self::assertStringNotContainsString($this->imageFilePath($highLevelImageId), $tnUrl, 'tn_url must not still point at the above-privacy-level representative');
    }

    /**
     * DerivativeImage::url()'s thumbnail URL embeds the source image's own
     * upload path with a "-th" suffix inserted before the extension (e.g.
     * ".../foo-th.jpg" for source path ".../foo.jpg") -- returns just the
     * extension-less basename, enough to tell which of the two throwaway
     * images a returned tn_url actually points at without depending on the
     * exact derivative suffix format.
     */
    private function imageFilePath(int $imageId): string
    {
        $path = $this->conn->fetchOne('SELECT path FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        self::assertIsString($path);

        return pathinfo($path, PATHINFO_FILENAME);
    }

    // --------------------------------------------------------- getAdminList

    public function test_getAdminList_non_recursive_returns_only_root_categories(): void
    {
        $response = $this->wsAdmin('pwg.categories.getAdminList', ['recursive' => false]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertContains(1, $ids);
        self::assertNotContains(2, $ids);
    }

    public function test_getAdminList_non_recursive_with_cat_id_includes_the_category_and_its_children(): void
    {
        $response = $this->wsAdmin('pwg.categories.getAdminList', ['recursive' => false, 'cat_id' => 1]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);
    }

    public function test_getAdminList_recursive_with_cat_id_filters_by_uppercats(): void
    {
        $response = $this->wsAdmin('pwg.categories.getAdminList', ['recursive' => true, 'cat_id' => 1]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $ids = array_column($categories, 'id');
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);
    }

    public function test_getAdminList_search_filters_by_name(): void
    {
        $unique = 'AdminUniqueSearchable' . uniqid();
        $this->createCategory($unique);

        $response = $this->wsAdmin('pwg.categories.getAdminList', ['recursive' => true, 'search' => $unique]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        self::assertCount(1, $categories);
        $first = $categories[0];
        self::assertIsArray($first);
        self::assertSame($unique, $first['name_raw'] ?? null);
    }

    public function test_getAdminList_additional_output_full_name_with_admin_links(): void
    {
        $response = $this->wsAdmin('pwg.categories.getAdminList', ['recursive' => true, 'additional_output' => 'full_name_with_admin_links']);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $categories = $result['categories'];
        self::assertIsArray($categories);

        $first = $categories[0] ?? null;
        self::assertIsArray($first);
        self::assertArrayHasKey('full_name_with_admin_links', $first);
        self::assertIsString($first['full_name_with_admin_links']);
        self::assertStringContainsString('<a', $first['full_name_with_admin_links'], 'full_name_with_admin_links must contain the raw (unstripped) admin-link HTML');
    }
}
