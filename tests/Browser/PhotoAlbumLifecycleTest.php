<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Narrows a WS response's `result` field to an array. H::wsCall() only
 * guarantees array<string, mixed> — the decoded JSON body isn't typed any
 * further — so this asserts the shape this file's own WS calls always
 * produce, rather than trusting it blindly.
 *
 * @param  array<string, mixed>  $response
 * @return array<array-key, mixed>
 */
function lifecycleWsResult(array $response, string $context): array
{
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException("{$context} response missing result: " . var_export($response, true));
    }

    return $result;
}

/**
 * Narrows `result.id` (as produced by pwg.categories.add) to an int.
 *
 * @param  array<string, mixed>  $result
 */
function lifecycleResultId(array $result, string $context): int
{
    $id = $result['id'] ?? null;
    if (!is_numeric($id)) {
        throw new RuntimeException("{$context} did not return a numeric result.id: " . var_export($result, true));
    }

    return (int) $id;
}

/**
 * Narrows `result.<listKey>` to a list of arrays, e.g. the `images` list
 * from pwg.categories.getImages or the `categories` list from
 * pwg.categories.getAdminList — skipping any entry that isn't itself an
 * array (array_column needs array-shaped rows, not scalars).
 *
 * @param  array<string, mixed>  $result
 * @return list<array<string, mixed>>
 */
function lifecycleResultList(array $result, string $listKey, string $context): array
{
    $list = $result[$listKey] ?? null;
    if (!is_array($list)) {
        throw new RuntimeException("{$context} response missing {$listKey}: " . var_export($result, true));
    }

    $out = [];
    foreach ($list as $item) {
        if (is_array($item)) {
            $out[] = $item;
        }
    }

    return $out;
}

it('photo and album full CRUD lifecycle', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    // --- Create album ---
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Lifecycle Album ' . uniqid()]);
    expect($album['stat'])->toBe('ok');
    $albumId = lifecycleResultId(lifecycleWsResult($album, 'pwg.categories.add'), 'pwg.categories.add');
    expect($albumId)->toBeGreaterThan(0);

    // --- Upload photo ---
    $image = H::makeTestImage('Original Name');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Original Name');
    @unlink($image);

    // --- Photo appears in album ---
    $list = H::wsCall($page, 'pwg.categories.getImages', ['cat_id' => $albumId]);
    expect($list['stat'])->toBe('ok');
    $imageIds = array_column(
        lifecycleResultList(lifecycleWsResult($list, 'pwg.categories.getImages'), 'images', 'pwg.categories.getImages'),
        'id'
    );
    expect($imageIds)->toContain($imageId);

    // --- Update photo name ---
    $update = H::wsCall($page, 'pwg.images.setInfo', [
        'image_id'          => $imageId,
        'name'              => 'Updated Name',
        'single_value_mode' => 'replace',
    ]);
    expect($update['stat'])->toBe('ok');

    $info = H::wsCall($page, 'pwg.images.getInfo', ['image_id' => $imageId]);
    expect($info['stat'])->toBe('ok');
    $infoResult = lifecycleWsResult($info, 'pwg.images.getInfo');
    expect($infoResult['name'])->toBe('Updated Name');

    // --- Delete photo ---
    $deletePhoto = H::wsCall($page, 'pwg.images.delete', ['image_id' => $imageId, 'pwg_token' => $pwgToken]);
    expect($deletePhoto['stat'])->toBe('ok');

    // --- Delete album ---
    $deleteAlbum = H::wsCall($page, 'pwg.categories.delete', [
        'category_id'         => $albumId,
        'photo_deletion_mode' => 'delete_orphans',
        'pwg_token'            => $pwgToken,
    ]);
    expect($deleteAlbum['stat'])->toBe('ok');

    // Verify album is gone from the admin list.
    $catList = H::wsCall($page, 'pwg.categories.getAdminList');
    expect($catList['stat'])->toBe('ok');
    $catIds = array_column(
        lifecycleResultList(lifecycleWsResult($catList, 'pwg.categories.getAdminList'), 'categories', 'pwg.categories.getAdminList'),
        'id'
    );
    expect($catIds)->not->toContain($albumId);
});
