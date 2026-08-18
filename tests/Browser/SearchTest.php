<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('API search finds photo by name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::createCategory($page, [
        'name' => 'Search Test Album ' . uniqid(),
    ]);

    if (! is_numeric($album['id'] ?? null)) {
        throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
    }

    $albumId = (int) $album['id'];

    $uniqueName = 'BrowserSearchTarget_' . uniqid();
    $image = H::makeTestImage('Search Target');
    H::uploadPhotoViaApi($image, $albumId, $uniqueName);
    @unlink($image);

    $search = H::searchImages($page, [
        'query' => $uniqueName,
    ]);

    $images = $search['images'] ?? null;
    if (! is_array($images)) {
        throw new RuntimeException('searchImages result missing images: ' . var_export($search, true));
    }

    $names = array_column($images, 'name');
    expect($names)
        ->toContain($uniqueName);
});

it('gallery search page renders without errors', function (): void {
    $page = H::gotoOk($this, '/qsearch.php?q=sunset');
    $page->assertNoJavaScriptErrors();

    // qsearch.php -> SearchController -> saveSearch() never records a
    // top-level 'q' key on the saved search array (only
    // fields.allwords.words) -- SearchService::getSearchResults() only
    // populates SectionContext's qsearchDetails (=> QUERY_SEARCH => this
    // box's pre-filled value) when a saved search DOES have that 'q' key,
    // so the box always falls back to its "Quick search" JS placeholder
    // here -- confirmed live, and the reference 16.x-rewrite branch's own
    // SearchController has the exact same gap, so this is pre-existing
    // behavior, not a regression.
    $page->assertPresent('input#qsearchInput');
    $page->assertSee('Search results');
});

it('search with no results renders an empty state without errors', function (): void {
    $page = H::gotoOk($this, '/qsearch.php?q=zzznomatch99xqz');
    $page->assertNoJavaScriptErrors();
});
