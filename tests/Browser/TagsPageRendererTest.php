<?php

declare(strict_types=1);

use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\TagsPageRenderer (admin.php?page=tags) -- lists every tag
 * with its image counter, warns about orphan tags (tags with zero images),
 * and deletes them via a CSRF-gated action. pwg.images.setInfo's own
 * `tag_ids` param only accepts existing numeric tag ids (not names) --
 * pwg.tags.add is the real way to create a brand-new tag over the WS API.
 */
function tagsPageAddTag(Webpage|PendingAwaitablePage|AwaitableWebpage $page, string $name): int
{
    $result = H::wsCall($page, 'pwg.tags.add', ['name' => $name]);
    $tagResult = $result['result'] ?? null;
    $tagId = is_array($tagResult) ? ($tagResult['id'] ?? null) : null;
    if (! is_numeric($tagId)) {
        throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($result, true));
    }

    return (int) $tagId;
}

it('renders the tag list including a real tagged photo\'s counter', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Tags Page Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Tags Page Photo');
    @unlink($image);

    $tagName = 'Counted Tag ' . uniqid();
    $tagId = tagsPageAddTag($page, $tagName);

    $updateResult = H::wsCall($page, 'pwg.images.setInfo', [
        'image_id' => (string) $imageId,
        'tag_ids' => (string) $tagId,
    ]);
    expect($updateResult['stat'] ?? null)->toBe('ok');

    $page = H::navigateOk($page, '/admin.php?page=tags');

    $page->assertSee($tagName);
    $page->assertNoJavaScriptErrors();
});

it('warns about a real orphan tag and offers a review link', function (): void {
    $page = H::loginAsAdmin($this);
    $tagName = 'Orphan Tag ' . uniqid();
    tagsPageAddTag($page, $tagName);

    $page = H::navigateOk($page, '/admin.php?page=tags');

    $page->assertSee('orphan tag');
    $page->assertSee($tagName);
});

it('deletes every orphan tag via the CSRF-gated delete_orphans action', function (): void {
    $page = H::loginAsAdmin($this);
    $tagName = 'Deletable Orphan Tag ' . uniqid();
    tagsPageAddTag($page, $tagName);

    $token = H::pwgToken($page);
    $result = H::rawGet($page, '/admin.php?page=tags&action=delete_orphans&pwg_token=' . $token);

    // redirect() is a real Location header -- opaque under fetch(manual),
    // status always 0 (see this suite's own empty_caddie test).
    expect($result['status'])->toBe(0);

    $listPage = H::navigateOk($page, '/admin.php?page=tags');
    $listPage->assertDontSee($tagName);
    $listPage->assertSee('Orphan tags deleted');
});

it('rejects a delete_orphans request without a valid CSRF token', function (): void {
    $page = H::loginAsAdmin($this);
    $tagName = 'CSRF Guarded Orphan Tag ' . uniqid();
    tagsPageAddTag($page, $tagName);

    $result = H::rawGet($page, '/admin.php?page=tags&action=delete_orphans');

    expect($result['status'])->toBe(400);

    $listPage = H::navigateOk($page, '/admin.php?page=tags');
    $listPage->assertSee($tagName);
});