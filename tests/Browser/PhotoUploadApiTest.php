<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Narrows a `POST /api/v1/categories` response to its `id`.
 *
 * @param  array<array-key, mixed>  $response
 */
function photoUploadAlbumId(array $response): int
{
    $id = $response['id'] ?? null;
    if (! is_numeric($id)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($response, true));
    }

    return (int) $id;
}

/**
 * Narrows a `GET /api/v1/images/{id}` response to the {id, width, height}
 * fields this file's assertions need.
 *
 * @param  array<array-key, mixed>  $response
 * @return array{id: int, width: int, height: int}
 */
function photoUploadImageInfo(array $response): array
{
    $id = $response['id'] ?? null;
    $width = $response['width'] ?? null;
    $height = $response['height'] ?? null;
    if (! is_numeric($id) || ! is_numeric($width) || ! is_numeric($height)) {
        throw new RuntimeException('imageInfo did not return numeric id/width/height: ' . var_export($response, true));
    }

    return [
        'id' => (int) $id,
        'width' => (int) $width,
        'height' => (int) $height,
    ];
}

/**
 * Narrows a `GET /api/v1/categories/images` response's `images` to a plain
 * array, for count() at this file's multi-upload assertion.
 *
 * @param  array<array-key, mixed>  $response
 * @return array<mixed>
 */
function photoUploadImagesList(array $response): array
{
    $images = $response['images'] ?? null;

    return is_array($images) ? $images : [];
}

it('uploads a photo via the API and returns a positive image id', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Upload Test Album ' . uniqid(),
    ]);

    $image = H::makeTestImage('Upload Test');
    $imageId = H::uploadPhotoViaApi($image, photoUploadAlbumId($album), 'Upload Test Photo');
    @unlink($image);

    expect($imageId)
        ->toBeGreaterThan(0);
});

it('uploaded photo appears in getInfo with real dimensions', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'GetInfo Test Album ' . uniqid(),
    ]);
    $albumId = photoUploadAlbumId($album);

    $image = H::makeTestImage('GetInfo Test');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'My Test Photo');
    @unlink($image);

    $info = H::imageInfo($page, [
        'image_id' => $imageId,
    ]);
    $infoResult = photoUploadImageInfo($info);
    expect($infoResult['id'])->toBe($imageId);
    expect($infoResult['width'])->toBeGreaterThan(0);
    expect($infoResult['height'])->toBeGreaterThan(0);
});

it('two uploaded photos both appear in the album', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Multi Upload Album ' . uniqid(),
    ]);
    $albumId = photoUploadAlbumId($album);

    $image1 = H::makeTestImage('Photo A');
    $image2 = H::makeTestImage('Photo B');
    H::uploadPhotoViaApi($image1, $albumId, 'Photo A');
    H::uploadPhotoViaApi($image2, $albumId, 'Photo B');
    @unlink($image1);
    @unlink($image2);

    $list = H::categoryImages($page, [
        'cat_id' => $albumId,
    ]);
    expect(count(photoUploadImagesList($list)))
        ->toBeGreaterThanOrEqual(2);
});
