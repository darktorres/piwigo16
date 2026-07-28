<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\SearchController (search.php) -- builds a $search
 * descriptor from $_GET/user preferences, persists it via
 * SearchService::saveSearch(), and always redirects to the generated
 * search URL (never renders anything of its own). Had zero test coverage
 * before this file.
 */

it('redirects to a real generated search URL for a plain keyword search', function (): void {
    $page = H::gotoOk($this, '/search.php?q=' . urlencode('ct-search-keyword-' . uniqid()));

    // SearchService::saveSearch()'s own redirect target is
    // UrlServiceInterface::makeIndexUrl(['section' => 'search', 'search'
    // => $uuid]) -- a real gallery index URL carrying the persisted
    // search's uuid, not search.php itself.
    // makeIndexUrl() builds a path-style URL (no mod_rewrite in this test
    // env, so PATH_INFO is emulated via the `index.php?/...` prefix) --
    // confirmed live: the real redirect target is
    // `index.php?/search/<uuid>`, never a `?search=<uuid>` query string.
    $currentUrl = H::rawWebpage($page)->url();
    expect($currentUrl)->not->toContain('search.php');
    expect($currentUrl)->toContain('index.php?/search/');
    H::assertNoServerErrors($page, 'search.php redirect target');
});

it('fatal-errors on a hacking-attempt array cat_id', function (): void {
    $result = H::httpStatus('/search.php?cat_id[]=1&cat_id[]=2');

    expect($result)->toBe(500);
});

it('returns 404 for a nonexistent cat_id', function (): void {
    $result = H::httpStatus('/search.php?cat_id=999999999');

    expect($result)->toBe(404);
});

it('accepts a real, visible cat_id and redirects to a real search URL', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Controller Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    try {
        $page = H::navigateOk($page, '/search.php?cat_id=' . $albumId);
        $currentUrl = H::rawWebpage($page)->url();
        expect($currentUrl)->not->toContain('search.php');
        expect($currentUrl)->toContain('index.php?/search/');
        H::assertNoServerErrors($page, 'search.php redirect target with a real cat_id');
    } finally {
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});

it('fatal-errors on a hacking-attempt array tag_id when tags exist', function (): void {
    $page = H::loginAsAdmin($this);
    $tagResult = H::wsCall($page, 'pwg.tags.add', ['name' => 'ct-search-tag-' . uniqid()]);
    $tagResultData = $tagResult['result'] ?? null;
    if (! is_array($tagResultData) || ! is_numeric($tagResultData['id'] ?? null)) {
        throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($tagResult, true));
    }
    $tagId = (int) $tagResultData['id'];

    try {
        $result = H::httpStatus('/search.php?tag_id[]=1&tag_id[]=2');
        expect($result)->toBe(500);
    } finally {
        H::wsCall($page, 'pwg.tags.delete', ['tag_id' => $tagId, 'pwg_token' => H::pwgToken($page)]);
    }
});
