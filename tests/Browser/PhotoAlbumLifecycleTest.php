<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Narrows a decoded `/api/v1` response's `id` field to an int.
 *
 * @param  array<array-key, mixed>  $result
 */
function lifecycleResultId(array $result, string $context): int
{
    $id = $result['id'] ?? null;
    if (! is_numeric($id)) {
        throw new RuntimeException("{$context} did not return a numeric result.id: " . var_export($result, true));
    }

    return (int) $id;
}

/**
 * Narrows a decoded `/api/v1` response's `<listKey>` field to a list of
 * string-keyed arrays, e.g. the `images` list from categoryImages() or
 * the `categories` list from listCategoriesAdmin() — skipping any entry
 * that isn't itself an array (array_column needs array-shaped rows, not
 * scalars).
 *
 * @param  array<array-key, mixed>  $result
 * @return list<array<string, mixed>>
 */
function lifecycleResultList(array $result, string $listKey, string $context): array
{
    $list = $result[$listKey] ?? null;
    if (! is_array($list)) {
        throw new RuntimeException("{$context} response missing {$listKey}: " . var_export($result, true));
    }

    $out = [];
    foreach ($list as $item) {
        if (! is_array($item)) {
            continue;
        }

        $normalizedItem = [];
        foreach ($item as $key => $value) {
            $normalizedItem[(string) $key] = $value;
        }

        $out[] = $normalizedItem;
    }

    return $out;
}

it('photo and album full CRUD lifecycle', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    // --- Create album ---
    $album = H::createCategory($page, [
        'name' => 'Lifecycle Album ' . uniqid(),
    ]);
    $albumId = lifecycleResultId($album, 'createCategory');
    expect($albumId)
        ->toBeGreaterThan(0);

    // --- Upload photo ---
    $image = H::makeTestImage('Original Name');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Original Name');
    @unlink($image);

    // --- Photo appears in album ---
    $list = H::categoryImages($page, [
        'cat_id' => $albumId,
    ]);
    $imageIds = array_column(
        lifecycleResultList($list, 'images', 'categoryImages'),
        'id'
    );
    expect($imageIds)
        ->toContain($imageId);

    // --- Update photo name ---
    H::updateImageInfo($page, [
        'image_id' => $imageId,
        'name' => 'Updated Name',
        'single_value_mode' => 'replace',
    ]);

    $info = H::imageInfo($page, [
        'image_id' => $imageId,
    ]);
    expect($info['name'])->toBe('Updated Name');

    // --- Delete photo ---
    H::deleteImage($page, [
        'image_id' => $imageId,
        'pwg_token' => $pwgToken,
    ]);

    // --- Delete album ---
    H::deleteCategory($page, [
        'category_id' => $albumId,
        'photo_deletion_mode' => 'delete_orphans',
        'pwg_token' => $pwgToken,
    ]);

    // Verify album is gone from the admin list.
    $catList = H::listCategoriesAdmin($page);
    $catIds = array_column(
        lifecycleResultList($catList, 'categories', 'listCategoriesAdmin'),
        'id'
    );
    expect($catIds)
        ->not->toContain($albumId);
});
