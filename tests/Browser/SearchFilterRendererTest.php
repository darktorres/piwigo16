<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Search\SearchFilterRenderer -- the search-results page renderer
 * reached via GalleryController's `section === 'search'` branch (see
 * GalleryControllerTest.php's own docblock, which deliberately deferred
 * that branch to this file). tests/Browser/SearchTest.php's own
 * qsearch.php-driven tests already exercise the plain allwords path
 * incidentally; this file drives Controller\SearchController
 * (search.php) directly with specific filter fields (tag_id/cat_id) to
 * reach SearchFilterRenderer::render()'s own per-filter-type branches
 * (renderTagsFound()/renderAlbumsFound()), which a bare text query never
 * touches.
 */

it('renders search results filtered by a single tag', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Tag Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Search Tag Photo');
    @unlink($image);
    // Fixture tag 1 ('nature') -- see this suite's own fixture-shape
    // memory notes.
    H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $imageId, 'tag_ids' => '1']);

    $page = H::navigateOk($page, '/search.php?tag_id=1');
    $page->assertNoJavaScriptErrors();
    $page->assertSee('Search Tag Photo');
});

it('renders search results filtered by a single album', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Cat Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    H::uploadPhotoViaApi($image, $albumId, 'Search Cat Photo');
    @unlink($image);

    $page = H::navigateOk($page, '/search.php?cat_id=' . $albumId);
    $page->assertNoJavaScriptErrors();
    $page->assertSee('Search Cat Photo');
});

it('renders an empty search-results page for a freshly created tag with no matching photos', function (): void {
    $page = H::loginAsAdmin($this);
    $tag = H::wsCall($page, 'pwg.tags.add', ['name' => 'Empty Search Tag ' . uniqid()]);
    $tagResult = $tag['result'] ?? null;
    if (! is_array($tagResult) || ! is_numeric($tagResult['id'] ?? null)) {
        throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($tag, true));
    }
    $tagId = (int) $tagResult['id'];

    $page = H::navigateOk($page, '/search.php?tag_id=' . $tagId);
    $page->assertNoJavaScriptErrors();
});
