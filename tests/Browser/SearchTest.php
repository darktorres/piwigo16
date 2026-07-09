<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('API search finds photo by name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Test Album ' . uniqid()]);

    $albumResult = $album['result'] ?? null;
    if (!is_array($albumResult)) {
        throw new RuntimeException('pwg.categories.add response missing result: ' . var_export($album, true));
    }

    $rawAlbumId = $albumResult['id'] ?? null;
    if (!is_numeric($rawAlbumId)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric result.id: ' . var_export($albumResult, true));
    }

    $albumId = (int) $rawAlbumId;

    $uniqueName = 'BrowserSearchTarget_' . uniqid();
    $image = H::makeTestImage('Search Target');
    H::uploadPhotoViaApi($image, $albumId, $uniqueName);
    @unlink($image);

    $search = H::wsCall($page, 'pwg.images.search', ['query' => $uniqueName]);
    expect($search['stat'])->toBe('ok');

    $searchResult = $search['result'] ?? null;
    if (!is_array($searchResult)) {
        throw new RuntimeException('pwg.images.search response missing result: ' . var_export($search, true));
    }

    $images = $searchResult['images'] ?? null;
    if (!is_array($images)) {
        throw new RuntimeException('pwg.images.search result missing images: ' . var_export($searchResult, true));
    }

    $names = array_column($images, 'name');
    expect($names)->toContain($uniqueName);
});

it('gallery search page renders without errors', function (): void {
    $page = H::gotoOk($this, '/qsearch.php?q=sunset');
    $page->assertNoJavaScriptErrors();
});

it('search with no results renders an empty state without errors', function (): void {
    $page = H::gotoOk($this, '/qsearch.php?q=zzznomatch99xqz');
    $page->assertNoJavaScriptErrors();
});
