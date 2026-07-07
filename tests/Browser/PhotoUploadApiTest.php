<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('uploads a photo via the API and returns a positive image id', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Upload Test Album ' . uniqid()]);
    expect($album['stat'])->toBe('ok');

    $image = H::makeTestImage('Upload Test');
    $imageId = H::uploadPhotoViaApi($image, (int) $album['result']['id'], 'Upload Test Photo');
    @unlink($image);

    expect($imageId)->toBeGreaterThan(0);
});

it('uploaded photo appears in getInfo with real dimensions', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'GetInfo Test Album ' . uniqid()]);
    $albumId = (int) $album['result']['id'];

    $image = H::makeTestImage('GetInfo Test');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'My Test Photo');
    @unlink($image);

    $info = H::wsCall($page, 'pwg.images.getInfo', ['image_id' => $imageId]);
    expect($info['stat'])->toBe('ok');
    expect($info['result']['id'])->toBe($imageId);
    expect($info['result']['width'])->toBeGreaterThan(0);
    expect($info['result']['height'])->toBeGreaterThan(0);
});

it('two uploaded photos both appear in the album', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Multi Upload Album ' . uniqid()]);
    $albumId = (int) $album['result']['id'];

    $image1 = H::makeTestImage('Photo A');
    $image2 = H::makeTestImage('Photo B');
    H::uploadPhotoViaApi($image1, $albumId, 'Photo A');
    H::uploadPhotoViaApi($image2, $albumId, 'Photo B');
    @unlink($image1);
    @unlink($image2);

    $list = H::wsCall($page, 'pwg.categories.getImages', ['cat_id' => $albumId]);
    expect($list['stat'])->toBe('ok');
    expect(count($list['result']['images']))->toBeGreaterThanOrEqual(2);
});
