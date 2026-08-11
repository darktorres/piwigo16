<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('creates a tag, assigns it to a photo, then deletes the tag', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Tags Test Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new ExpectationFailedException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage('Tag Target');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tag Target');
    @unlink($image);

    $tagName = 'browser-tag-' . uniqid();
    $tagCreate = H::wsCall($page, 'pwg.tags.add', [
        'name' => $tagName,
    ]);
    expect($tagCreate['stat'])->toBe('ok');
    $tagCreateResult = $tagCreate['result'] ?? null;
    $rawTagId = is_array($tagCreateResult) ? ($tagCreateResult['id'] ?? $tagCreateResult['info'] ?? 0) : 0;
    $tagId = is_numeric($rawTagId) ? (int) $rawTagId : 0;
    expect($tagId)
        ->toBeGreaterThan(0);

    $setInfo = H::wsCall($page, 'pwg.images.setInfo', [
        'image_id' => $imageId,
        'tag_ids' => $tagId,
    ]);
    expect($setInfo['stat'])->toBe('ok');

    $tagImages = H::wsCall($page, 'pwg.tags.getImages', [
        'tag_id' => $tagId,
    ]);
    expect($tagImages['stat'])->toBe('ok');
    $tagImagesResult = $tagImages['result'] ?? null;
    if (! is_array($tagImagesResult) || ! is_array($tagImagesResult['images'] ?? null)) {
        throw new ExpectationFailedException('pwg.tags.getImages did not return an images list: ' . var_export($tagImages, true));
    }
    $imageIds = array_column($tagImagesResult['images'], 'id');
    expect($imageIds)
        ->toContain($imageId);

    $deleteTag = H::wsCall($page, 'pwg.tags.delete', [
        'tag_id' => $tagId,
        'pwg_token' => $pwgToken,
    ]);
    expect($deleteTag['stat'])->toBe('ok');

    $tagList = H::wsCall($page, 'pwg.tags.getList');
    $tagListResult = $tagList['result'] ?? null;
    if (! is_array($tagListResult) || ! is_array($tagListResult['tags'] ?? null)) {
        throw new ExpectationFailedException('pwg.tags.getList did not return a tags list: ' . var_export($tagList, true));
    }
    $tagIds = array_column($tagListResult['tags'], 'id');
    expect($tagIds)
        ->not->toContain($tagId);
});

it('admin tags page loads without errors', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=tags');
    $page->assertNoJavaScriptErrors();
});
