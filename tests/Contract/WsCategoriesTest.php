<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsCategoriesTest extends ContractTestCase
{
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
     * Gap-closure Stage 4h (docs/plan/gap-closure-p0-p23.md): getList()'s
     * `public: true` branch now computes the guest identity's forbidden
     * categories via UserService::getUserData() (replacing a
     * user_cache_categories JOIN) -- new code with no prior direct
     * coverage. A private album must not appear here even though the
     * caller is authenticated as admin, since `public: true` intentionally
     * asks "what would an anonymous visitor see."
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
     * Gap-closure Stage 4h: getList()'s "normal" (authenticated,
     * non-admin) branch now reads CurrentUser::forbiddenCategories
     * directly (replacing the same JOIN) -- also new code with no prior
     * direct coverage. regular_user has no explicit grant on the private
     * album created above, so it must stay hidden.
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
}
