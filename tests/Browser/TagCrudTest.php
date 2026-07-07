<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('creates a tag, assigns it to a photo, then deletes the tag', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Tags Test Album ' . uniqid()]);
    $albumId = (int) $album['result']['id'];
    $image = H::makeTestImage('Tag Target');
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tag Target');
    @unlink($image);

    $tagName = 'browser-tag-' . uniqid();
    $tagCreate = H::wsCall($page, 'pwg.tags.add', ['name' => $tagName]);
    expect($tagCreate['stat'])->toBe('ok');
    $tagId = (int) ($tagCreate['result']['id'] ?? $tagCreate['result']['info'] ?? 0);
    expect($tagId)->toBeGreaterThan(0);

    $setInfo = H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $imageId, 'tag_ids' => $tagId]);
    expect($setInfo['stat'])->toBe('ok');

    $tagImages = H::wsCall($page, 'pwg.tags.getImages', ['tag_id' => $tagId]);
    expect($tagImages['stat'])->toBe('ok');
    $imageIds = array_column($tagImages['result']['images'], 'id');
    expect($imageIds)->toContain($imageId);

    $deleteTag = H::wsCall($page, 'pwg.tags.delete', ['tag_id' => $tagId, 'pwg_token' => $pwgToken]);
    expect($deleteTag['stat'])->toBe('ok');

    $tagList = H::wsCall($page, 'pwg.tags.getList');
    $tagIds = array_column($tagList['result']['tags'], 'id');
    expect($tagIds)->not->toContain($tagId);
});

it('admin tags page loads without errors', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=tags');
    $page->assertNoJavaScriptErrors();
});
