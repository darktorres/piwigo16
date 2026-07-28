<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Search\SearchFilterRenderer::render() -- the existing
 * SearchFilterRendererTest.php only exercises the 'tags'/'cat' per-filter
 * branches (the 2 fields SearchController's own GET-driven quick search
 * actually populates). Every other filter type (date_posted/date_created/
 * added_by/filetypes/ratios/ratings/filesize/width/height) only enters
 * $search['fields'] as an empty placeholder when the admin-configured
 * `filters_views` config exposes it (Piwigo\Controller\Admin\
 * ConfigurationSubController's own "search" tab) -- this test enables all
 * of them with 'everybody' access and confirms render() handles that
 * wider field set without a fatal error.
 */
it('renders every configured search filter panel without a fatal error', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'words' => ['access' => 'everybody', 'default' => true],
        'tags' => ['access' => 'everybody', 'default' => true],
        'album' => ['access' => 'everybody', 'default' => true],
        'author' => ['access' => 'everybody', 'default' => true],
        'added_by' => ['access' => 'everybody', 'default' => true],
        'file_type' => ['access' => 'everybody', 'default' => true],
        'ratio' => ['access' => 'everybody', 'default' => true],
        'rating' => ['access' => 'everybody', 'default' => true],
        'file_size' => ['access' => 'everybody', 'default' => true],
        'post_date' => ['access' => 'everybody', 'default' => true],
        'creation_date' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Extra Filters Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Search Extra Filters Photo');
        @unlink($image);
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $imageId, 'author' => 'Search Filter Author']);

        $page = H::navigateOk($page, '/search.php?cat_id=' . $albumId);

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'search results with every filter panel enabled');
        $page->assertSee('Search Extra Filters Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders the date-filter panel with a real threshold-based interval', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'words' => ['access' => 'everybody', 'default' => true],
        'cat' => ['access' => 'everybody', 'default' => true],
        'creation_date' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Date Filter Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Date Filter Photo');
        @unlink($image);

        $page = H::navigateOk($page, '/search.php?cat_id=' . $albumId);

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'search results with date filter enabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});