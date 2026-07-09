<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Narrows the `result.id` of a pwg.categories.add WS response to an int.
 * H::wsCall() only guarantees array<string, mixed> — the decoded JSON body
 * isn't typed any further — so this asserts the shape this file's own
 * pwg.categories.add calls always produce, rather than trusting it blindly.
 *
 * @param  array<string, mixed>  $response
 */
function photoUploadAlbumId(array $response): int
{
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException('pwg.categories.add response missing result: ' . var_export($response, true));
    }

    $id = $result['id'] ?? null;
    if (!is_numeric($id)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric result.id: ' . var_export($response, true));
    }

    return (int) $id;
}

/**
 * Narrows the `result` of a pwg.images.getInfo WS response to the {id,
 * width, height} fields this file's assertions need.
 *
 * @param  array<string, mixed>  $response
 * @return array{id: int, width: int, height: int}
 */
function photoUploadImageInfo(array $response): array
{
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException('pwg.images.getInfo response missing result: ' . var_export($response, true));
    }

    $id = $result['id'] ?? null;
    $width = $result['width'] ?? null;
    $height = $result['height'] ?? null;
    if (!is_numeric($id) || !is_numeric($width) || !is_numeric($height)) {
        throw new RuntimeException('pwg.images.getInfo did not return numeric id/width/height: ' . var_export($response, true));
    }

    return ['id' => (int) $id, 'width' => (int) $width, 'height' => (int) $height];
}

/**
 * Narrows the `result.images` of a pwg.categories.getImages WS response to a
 * plain array, for count() at this file's multi-upload assertion.
 *
 * @param  array<string, mixed>  $response
 * @return array<mixed>
 */
function photoUploadImagesList(array $response): array
{
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException('pwg.categories.getImages response missing result: ' . var_export($response, true));
    }

    $images = $result['images'] ?? null;

    return is_array($images) ? $images : [];
}

it('uploads a photo via the API and returns a positive image id', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Upload Test Album ' . uniqid()]);
    expect($album['stat'])->toBe('ok');

    $image = H::makeTestImage('Upload Test');
    $imageId = H::uploadPhotoViaApi($image, photoUploadAlbumId($album), 'Upload Test Photo');
    @unlink($image);

    expect($imageId)->toBeGreaterThan(0);
});

it('uploaded photo appears in getInfo with real dimensions', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'GetInfo Test Album ' . uniqid()]);
    $albumId = photoUploadAlbumId($album);

    $image = H::makeTestImage('GetInfo Test');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'My Test Photo');
    @unlink($image);

    $info = H::wsCall($page, 'pwg.images.getInfo', ['image_id' => $imageId]);
    expect($info['stat'])->toBe('ok');
    $infoResult = photoUploadImageInfo($info);
    expect($infoResult['id'])->toBe($imageId);
    expect($infoResult['width'])->toBeGreaterThan(0);
    expect($infoResult['height'])->toBeGreaterThan(0);
});

it('two uploaded photos both appear in the album', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Multi Upload Album ' . uniqid()]);
    $albumId = photoUploadAlbumId($album);

    $image1 = H::makeTestImage('Photo A');
    $image2 = H::makeTestImage('Photo B');
    H::uploadPhotoViaApi($image1, $albumId, 'Photo A');
    H::uploadPhotoViaApi($image2, $albumId, 'Photo B');
    @unlink($image1);
    @unlink($image2);

    $list = H::wsCall($page, 'pwg.categories.getImages', ['cat_id' => $albumId]);
    expect($list['stat'])->toBe('ok');
    expect(count(photoUploadImagesList($list)))->toBeGreaterThanOrEqual(2);
});
