<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

final class WsTagsTest extends ContractTestCase
{
    public function testGetListResponseMatchesSchema(): void
    {
        $response = $this->ws('pwg.tags.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('tags.getList', $response);
    }

    public function testGetListReturnsOnlyUsedTags(): void
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

    public function testGetAdminListResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.tags.getAdminList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.tags.getAdminList', $response);
    }

    public function testGetAdminListReturnsAllTagsIncludingUnused(): void
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

    public function testGetAdminListForbiddenForGuest(): void
    {
        $response = $this->ws('pwg.tags.getAdminList');

        self::assertSame('fail', $response['stat']);
    }

    public function testGetImagesReturnsPagedImageList(): void
    {
        // Use tag_id=1 which is seeded in the fixture
        $response = $this->wsAdmin('pwg.tags.getImages', [
            'tag_id' => [1],
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.tags.getImages', $response);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertArrayHasKey('images', $result);
    }

    /**
     * getImages()'s own `if ($order_by !== '') { $order_by = 'ORDER BY '
     * . $order_by; }` branch -- test_getImages_returns_paged_image_list()
     * above never passes an 'order' param at all.
     */
    public function testGetImagesAcceptsAnOrderParam(): void
    {
        // fixture tag 1 ("nature") is attached to images 1, 2 and 3, per
        // image_tag -- confirmed live via a direct DB read.
        $response = $this->wsAdmin('pwg.tags.getImages', [
            'tag_id' => [1],
            'order' => 'id asc',
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $images = $result['images'];
        self::assertIsArray($images);
        $ids = array_values(array_map(
            static fn (mixed $image): int => is_array($image) && is_numeric($image['id']) ? (int) $image['id'] : 0,
            $images
        ));
        self::assertSame([1, 2, 3], $ids);
    }

    /**
     * getList()'s `if ($params['sort_by_counter'])` branch --
     * test_getList_returns_only_used_tags() above never passes
     * sort_by_counter at all (default false, alphabetical
     * tagAlphaCompare() sort).
     */
    public function testGetListSortsByCounterWhenRequested(): void
    {
        $response = $this->ws('pwg.tags.getList', [
            'sort_by_counter' => true,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $tags = $result['tags'];
        self::assertIsArray($tags);
        self::assertGreaterThanOrEqual(3, count($tags), 'fixture must contain at least the 3 seeded tags');

        $counters = array_map(static function (mixed $tag): int {
            self::assertIsArray($tag);
            self::assertIsNumeric($tag['counter']);

            return (int) $tag['counter'];
        }, $tags);

        $sortedDescending = $counters;
        rsort($sortedDescending, SORT_NUMERIC);
        self::assertSame($sortedDescending, $counters, 'sort_by_counter must return tags ordered by counter descending');
    }
}
