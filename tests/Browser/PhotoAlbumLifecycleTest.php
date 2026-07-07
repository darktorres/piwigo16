<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('photo and album full CRUD lifecycle', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    // --- Create album ---
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Lifecycle Album ' . uniqid()]);
    expect($album['stat'])->toBe('ok');
    $albumId = (int) $album['result']['id'];
    expect($albumId)->toBeGreaterThan(0);

    // --- Upload photo ---
    $image = H::makeTestImage('Original Name');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Original Name');
    @unlink($image);

    // --- Photo appears in album ---
    $list = H::wsCall($page, 'pwg.categories.getImages', ['cat_id' => $albumId]);
    expect($list['stat'])->toBe('ok');
    $imageIds = array_column($list['result']['images'], 'id');
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
    expect($info['result']['name'])->toBe('Updated Name');

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
    $catIds = array_column($catList['result']['categories'], 'id');
    expect($catIds)->not->toContain($albumId);
});
