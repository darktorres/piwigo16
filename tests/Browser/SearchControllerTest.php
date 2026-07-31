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

// `cat_id`/`tag_id`'s own `! is_string(...)` guards inside __invoke()
// (lines ~119-122 / ~158-161) are dead code from any *real* HTTP request:
// SearchController only ever builds its Request\SearchQueryRequest via
// ::fromGlobals() (reads bare $_GET, whose leaf values are always
// string|array, never int/float/bool), and InputValidator::validate()
// -- called from inside SearchQueryRequest::fromArray() itself, with
// isArray=false for both params -- already fatal-errors on any array
// value (`! is_scalar($paramValue)`) before SearchController ever
// inspects catId/tagId. So by the time __invoke() reaches its own
// is_string() check, the value has necessarily already survived
// is_scalar() while originating from $_GET -- meaning it's necessarily a
// string. The two tests below (array cat_id / array tag_id) still return
// a real 500 with the identical "[Hacking attempt]... is not valid"
// message SearchController's own fatalError would produce, but that 500
// is actually emitted by InputValidator::validate()'s own fatalError
// call, one frame earlier -- SearchController's own duplicate guard
// never runs. This can only be exercised directly (bypassing
// fromGlobals()'s $_GET read) via a Unit test invoking the DTO/method
// with a hand-constructed non-string scalar, which doesn't fit this
// file's real-HTTP-request Browser-suite convention.
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

/**
 * Closes the "logged-in user, filters_views has last_filters_conf=true"
 * branch (lines ~91-97): a real, non-guest/non-generic session reads its
 * default active fields from PreferencesService::getParam
 * ('gallery_search_filters', ...) instead of the config's own per-filter
 * 'default' flags. Also closes the `! is_array($filt_conf)` `continue`
 * guard (line ~81): 'last_filters_conf' is persisted as a lone boolean
 * flag alongside the array-shaped per-filter entries in the SAME
 * 'filters_views' config value (see SearchFilterRenderer's own docblock),
 * so the filters_conf rebuild loop hits a non-array entry for that one
 * key on every real request once it's been enabled.
 *
 * The 'gallery_search_filters' preference itself is seeded by a REAL
 * prior search (pwg.images.filteredSearch.create, same mechanism
 * SearchFilterRendererDataFiltersTest.php's own fixtures use) with
 * filesize_min/filesize_max active -- SearchService::saveSearch()
 * unconditionally persists `array_keys($rules['fields'])` into that
 * preference for any non-guest/non-generic user, regardless of
 * last_filters_conf. Loading bare search.php afterwards (as the SAME
 * admin session, no relevant $_GET params of its own) then re-derives
 * $fields from that stale preference, landing 'filesize_min'/
 * 'filesize_max' in the numeric-range foreach at line ~206-210 and
 * assigning the placeholder '' value (line ~208).
 */
it('reads default active filters from a logged-in user\'s saved preference once last_filters_conf is enabled, including a numeric-range field', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'file_size' => ['access' => 'everybody', 'default' => false],
        'last_filters_conf' => true,
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Prefs Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];

        // Persists 'gallery_search_filters' = ['filesize_min', 'filesize_max']
        // (plus 'cat') onto the admin user's own preferences row.
        $search = H::wsCall($page, 'pwg.images.filteredSearch.create', [
            'categories' => $albumId,
            'filesize_min' => 100,
            'filesize_max' => 4000,
        ]);
        $searchResult = $search['result'] ?? null;
        if (! is_array($searchResult) || ! is_string($searchResult['search_url'] ?? null)) {
            throw new RuntimeException('pwg.images.filteredSearch.create did not return a search_url: ' . var_export($search, true));
        }

        // Bare search.php reload, same admin session, no cat_id/q/tag_id
        // of its own -- $fields comes entirely from the just-saved
        // preference now.
        $page = H::navigateOk($page, '/search.php');
        $currentUrl = H::rawWebpage($page)->url();
        expect($currentUrl)->not->toContain('search.php');
        expect($currentUrl)->toContain('index.php?/search/');
        H::assertNoServerErrors($page, 'search.php redirect target reading a saved gallery_search_filters preference');
    } finally {
        H::restoreConfig($snapshot);
    }
});
