<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsTagsTest extends ContractTestCase
{
    public function test_getList_response_matches_schema(): void
    {
        $response = $this->ws('pwg.tags.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('tags.getList', $response);
    }

    public function test_getList_returns_only_used_tags(): void
    {
        // pwg.tags.getList filters to tags attached to ≥1 image.
        // The fixture seeds 3 tags all attached to photos, so this must be non-empty.
        $response = $this->ws('pwg.tags.getList');

        $result = $response['result'];
        self::assertIsArray($result);
        $tags = $result['tags'];
        self::assertIsArray($tags);
        self::assertNotEmpty($tags, 'Fixture must contain at least one used tag');

        foreach ($tags as $tag) {
            self::assertIsArray($tag);
            self::assertIsInt($tag['id']);
            self::assertIsString($tag['name']);
            self::assertGreaterThan(0, $tag['counter']);
            self::assertIsString($tag['url']);
        }
    }

    public function test_getAdminList_response_matches_schema(): void
    {
        $response = $this->wsAdmin('pwg.tags.getAdminList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.tags.getAdminList', $response);
    }

    public function test_getAdminList_returns_all_tags_including_unused(): void
    {
        $adminList = $this->wsAdmin('pwg.tags.getAdminList');
        $publicList = $this->ws('pwg.tags.getList');

        // getAdminList includes all tags; getList only returns used ones
        $publicResult = $publicList['result'];
        self::assertIsArray($publicResult);
        $publicTags = $publicResult['tags'];
        self::assertIsArray($publicTags);

        $adminResult = $adminList['result'];
        self::assertIsArray($adminResult);
        $adminTags = $adminResult['tags'];
        self::assertIsArray($adminTags);

        self::assertGreaterThanOrEqual(count($publicTags), count($adminTags));
    }

    public function test_getAdminList_forbidden_for_guest(): void
    {
        $response = $this->ws('pwg.tags.getAdminList');

        self::assertSame('fail', $response['stat']);
    }

    public function test_getImages_returns_paged_image_list(): void
    {
        // Use tag_id=1 which is seeded in the fixture
        $response = $this->wsAdmin('pwg.tags.getImages', ['tag_id' => [1]]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.tags.getImages', $response);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('images', $result);
    }
}
