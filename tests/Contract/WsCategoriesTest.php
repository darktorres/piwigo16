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
            self::fail('WS response result is not an array: ' . json_encode($response));
        }

        $categories = $result['categories'] ?? null;
        if (!is_array($categories)) {
            self::fail('WS response result.categories is not an array: ' . json_encode($response));
        }
        self::assertNotEmpty($categories, 'Fixture must contain at least one album');

        $first = $categories[0] ?? null;
        if (!is_array($first)) {
            self::fail('First category is not an array: ' . json_encode($response));
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
            self::fail('WS response result is not an array: ' . json_encode($response));
        }
        self::assertArrayHasKey('paging', $result);
        self::assertArrayHasKey('images', $result);
        self::assertIsArray($result['images']);
    }
}
