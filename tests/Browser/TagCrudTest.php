<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('creates a tag, assigns it to a photo, then deletes the tag', function (): void {
    $page = H::asAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::createCategory($page, [
        'name' => 'Tags Test Album ' . uniqid(),
    ]);
    if (! is_numeric($album['id'] ?? null)) {
        throw new ExpectationFailedException('createCategory did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $album['id'];
    $image = H::makeTestImage('Tag Target');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tag Target');
    @unlink($image);

    $tagName = 'browser-tag-' . uniqid();
    $tagCreate = H::createTag($page, [
        'name' => $tagName,
    ]);
    $rawTagId = $tagCreate['id'] ?? $tagCreate['info'] ?? 0;
    $tagId = is_numeric($rawTagId) ? (int) $rawTagId : 0;
    expect($tagId)
        ->toBeGreaterThan(0);

    H::updateImageInfo($page, [
        'image_id' => $imageId,
        'tag_ids' => (string) $tagId,
    ]);

    $tagImages = H::tagImages($page, [
        'tag_id' => $tagId,
    ]);
    if (! is_array($tagImages['images'] ?? null)) {
        throw new ExpectationFailedException('tagImages did not return an images list: ' . var_export($tagImages, true));
    }
    $imageIds = array_column($tagImages['images'], 'id');
    expect($imageIds)
        ->toContain($imageId);

    H::deleteTag($page, [
        'tag_id' => $tagId,
        'pwg_token' => $pwgToken,
    ]);

    $tagList = H::listTags($page);
    if (! is_array($tagList['tags'] ?? null)) {
        throw new ExpectationFailedException('listTags did not return a tags list: ' . var_export($tagList, true));
    }
    $tagIds = array_column($tagList['tags'], 'id');
    expect($tagIds)
        ->not->toContain($tagId);
});

it('admin tags page loads without errors', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=tags');
    $page->assertNoJavaScriptErrors();
});
