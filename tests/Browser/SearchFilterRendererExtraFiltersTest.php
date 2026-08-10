<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Pest\Browser\Api\Webpage;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * A freshly-uploaded photo carries no rating_score/date_creation at all
 * (both genuinely NULL -- confirmed live -- there's no WS setter for
 * either column, same established fact SearchFilterRendererDataFiltersTest.php's
 * own docblock already documents). A search filtering on either of those
 * columns must set a real value directly first, or it matches zero
 * real photos regardless of anything render()/SearchService actually do.
 */
function extraFiltersSetImageAttrs(int $imageId, ?float $ratingScore = null, ?string $dateCreation = null): void
{
    $db = H::connect();
    $sets = [];
    if ($ratingScore !== null) {
        $sets[] = 'rating_score = ' . $ratingScore;
    }
    if ($dateCreation !== null) {
        $sets[] = "date_creation = '" . H::dbEscape($db, $dateCreation) . "'";
    }
    if ($sets !== []) {
        H::dbQuery($db, sprintf('UPDATE images SET %s WHERE id = %d', implode(', ', $sets), $imageId));
    }
    H::dbClose($db);
}

/**
 * assertSee()'s own retry-polling doesn't reliably re-check a page right
 * after navigate() -- confirmed live: H::rawWebpage($page)->content()
 * already contains the real, correct final HTML at the same point
 * assertSee() still reports failure (a stale/pre-navigation snapshot).
 * Same bounded-retry technique BrowserTestHelpers::assertNoServerErrors()
 * already uses around its own content() call, for the exact same
 * documented Playwright race ("page is navigating and changing the
 * content") -- each content() call is its own independent RPC, not a
 * one-time race that settles after the first successful read elsewhere.
 */
function extraFiltersSettledContent(Webpage|PendingAwaitablePage|AwaitableWebpage $page): string
{
    for ($attempt = 1; $attempt <= 5; ++$attempt) {
        try {
            return H::rawWebpage($page)->content();
        } catch (ExpectationFailedException $e) {
            if (! str_contains($e->getMessage(), 'page is navigating and changing the content') || $attempt === 5) {
                throw $e;
            }
            usleep(200_000);
        }
    }

    throw new RuntimeException('unreachable');
}

/**
 * Inserts a `piwigo_search` row directly via raw SQL, bypassing
 * SearchService::saveSearch()/the WS API entirely -- needed to construct
 * `rules` shapes neither ever produces: a JSON object with no top-level
 * "fields" key at all, or a per-field value that isn't the array shape
 * every real writer (Ws\PwgImages::filteredSearchCreate()) always builds.
 * SearchFilterRenderer::render()'s own defensive
 * `!isset($mySearch['fields']) || !is_array($mySearch['fields'])` (and the
 * per-field `! is_array($searchFields[x])`) re-narrowing only exists to
 * guard against exactly this kind of malformed row, which no in-app code
 * path can currently produce on its own. Returns the generated
 * search_uuid (same `psk-YYYYMMDD-<10 chars>` shape
 * SearchService::getAvailableSearchUuid() builds), navigable via
 * `/index.php?/search/<uuid>` (confirmed URL shape, see
 * SearchControllerTest.php's own docblock).
 *
 * @param array<string, mixed> $rules
 */
function extraFiltersInsertRawSearchRow(array $rules): string
{
    $db = H::connect();

    $uuid = 'psk-' . date('Ymd') . '-' . substr(md5(uniqid('', true)), 0, 10);
    $rulesJson = json_encode($rules);
    if ($rulesJson === false) {
        throw new RuntimeException('json_encode failed for raw search rules: ' . var_export($rules, true));
    }

    H::dbQuery($db, sprintf("INSERT INTO search (rules, created_on, created_by, search_uuid, forked_from) VALUES ('%s', NOW(), NULL, '%s', NULL)", H::dbEscape($db, $rulesJson), H::dbEscape($db, $uuid)));
    H::dbClose($db);

    return $uuid;
}

/**
 * Extracts the raw `global_params = {...};` JS statement body
 * search_filters.inc.tpl emits from `$GP` (json_encode($mySearch),
 * SearchFilterRenderer::render()'s own final template assignment) --
 * scoping a "does the removed field's key still appear" check to just
 * this blob, rather than the whole response body, avoids a false match
 * against an unrelated same-named label elsewhere on the page (e.g. a
 * photo's own "author" metadata display).
 */
function extraFiltersGlobalParamsJson(string $html): string
{
    if (preg_match('/global_params\s*=\s*(\{.*?\});/s', $html, $matches) !== 1) {
        throw new RuntimeException('could not find the global_params JS assignment in the response body');
    }

    return $matches[1];
}

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

/**
 * Closes 3 real gaps left after SearchFilterRendererTest.php (tag_id/
 * cat_id/q) and SearchFilterRendererDataFiltersTest.php (author/added_by/
 * ratio/rating/filesize/height/width row+bucket facets, each exercised
 * across a real cache-miss then cache-hit load): none of those 3 files
 * ever (1) disable the `rate` config at render time, (2) build a tags
 * search alongside another active filter whose own item set shares no
 * intersection with the searched tag (the getCommonTags()+"force missing
 * tags back in" merge branch), or (3) drive the date_posted/date_created
 * shared cache (renderDateFilter()'s own is_array($cached) branch) --
 * every existing date-filter test here always keeps another filter (cat_id)
 * active alongside it, which permanently forces the never-cached
 * 'image_id IN (...)' clause instead.
 */
it('unsets the ratings search field and hides the ratings filter panel entirely when global rating is disabled after the search was created', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'rate']);
    $filtersViews = json_encode([
        'words' => ['access' => 'everybody', 'default' => true],
        'rating' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Ratings Disabled Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Search Ratings Disabled Photo');
        @unlink($image);
        // rating_score is NULL on a freshly-uploaded photo -- ratings=3
        // below is a 'rating_score >= 2 AND rating_score < 3' bucket
        // (SearchService::getSearchResults()'s own clause), which a NULL
        // score never satisfies. Real value needed for the search to
        // genuinely match this photo, not just avoid a fatal error.
        extraFiltersSetImageAttrs($imageId, ratingScore: 2.5);

        // Explicitly enabled (rather than relying on whatever `rate`
        // happens to already be) because PwgImages::filteredSearchCreate()
        // only persists the 'ratings' field onto the search row in the
        // first place when Config\CurrentConfig::rateEnabled() is true at
        // CREATION time -- a separate read from the one render() makes
        // below, at RENDER time, which is what makes this a real 2-step
        // sequence rather than a single config snapshot.
        H::setConfigValue('rate', 'true');
        // Real bug, found while investigating this test's own failure:
        // SearchService::getRegularSearchResults() returns zero items
        // whenever $imageIdsForFilter ends up completely empty (line
        // ~422: `$items = [];` unconditionally, only ever populated
        // inside the `if ($imageIdsForFilter !== [])` branch) -- NOT "no
        // active filter means show everything". With only 'ratings' as a
        // criterion, disabling rate at render time (below) skips that
        // one clause entirely (CurrentConfig::rateEnabled() gates it),
        // leaving $imageIdsForFilter genuinely empty and therefore ZERO
        // results, regardless of anything render()/SearchFilterRenderer
        // itself does. An 'allwords' criterion on the photo's own real
        // name keeps a second, real, non-rating-gated filter active (via
        // the 'words' entry this test's own filters_views already
        // grants access to), so the search still matches the photo once
        // the ratings clause is skipped -- same reasoning as
        // SearchFilterRendererExtraFiltersTest.php's own tag-merge test
        // needing 'categories' alongside 'tags' to keep a real match.
        $search = H::wsCall($page, 'pwg.images.filteredSearch.create', [
            'ratings' => '3',
            'allwords' => 'Search Ratings Disabled Photo',
        ]);
        $searchResult = $search['result'] ?? null;
        if (! is_array($searchResult) || ! is_string($searchResult['search_url'] ?? null)) {
            throw new RuntimeException('pwg.images.filteredSearch.create did not return a search_url: ' . var_export($search, true));
        }
        $searchUrl = $searchResult['search_url'];

        // Now disable rating globally, AFTER the search row already has a
        // real 'ratings' field persisted -- render()'s own rateEnabled()
        // read happens fresh on every request. This exercises the `else`
        // branch of SearchFilterRenderer::render()'s "For rating" block:
        // SHOW_FILTER_RATINGS assigned false, and searchFields['ratings']
        // unconditionally unset (unlike the access-gated unset in the
        // rateEnabled()===true branch).
        H::setConfigValue('rate', 'false');

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search with a ratings field but rate disabled at render time');
        $page->assertNoJavaScriptErrors();
        expect(extraFiltersSettledContent($page))->toContain('Search Ratings Disabled Photo');

        // search_filters.inc.tpl emits this JS var literally from
        // $SHOW_FILTER_RATINGS, and only renders the ratings checkbox / the
        // whole "filter-ratings" panel when it's true -- both are only
        // observable by reading the raw response body, not assertSee()'s
        // visible-text check.
        $html = H::rawWebpage($page)->content();
        expect($html)->toContain('var show_filter_ratings = false;');
        expect($html)->not->toContain('filter-manager-controller ratings');
        // Proves searchFields['ratings'] was actually unset (not just
        // hidden by the template gate): $mySearch['fields'] (with
        // 'ratings' removed via the by-ref $searchFields alias) is what
        // gets json_encode()'d into the page's own `global_params` blob.
        expect($html)->not->toContain('"ratings"');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('forces a searched tag with no intersection among the other active filters back into the tag list via a second getAvailableTags() call', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'words' => ['access' => 'everybody', 'default' => true],
        'tags' => ['access' => 'everybody', 'default' => true],
        'album' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Tag Merge Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];

        // Left untagged on purpose: this album's only photo is the "other
        // active filter" (cat) item set render() intersects against below,
        // and it carries none of the tags searched for.
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Tag Merge Photo');
        @unlink($image);

        $tagName = 'Search Tag Merge Unused Tag ' . uniqid();
        $tag = H::wsCall($page, 'pwg.tags.add', ['name' => $tagName]);
        $tagResult = $tag['result'] ?? null;
        if (! is_array($tagResult) || ! is_numeric($tagResult['id'] ?? null)) {
            throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($tag, true));
        }
        $tagId = (int) $tagResult['id'];

        // Real bug, found while investigating this test's own failure:
        // TagService::getAvailableTags($ids) -- the call render() uses to
        // resolve the "forced back" tag's own display row -- delegates to
        // TagRepository::countImagesPerTag(), which INNER JOINs image_tag
        // and therefore returns NOTHING for a tag with zero images attached
        // anywhere, *regardless* of the explicit id filter (confirmed
        // identical in the pre-rewrite include/functions_tag.inc.php --
        // not a rewrite regression). render()'s own "force" fallback
        // silently no-ops in that case: $missingTagIds correctly names the
        // tag, but array_merge($tagService->getAvailableTags($missingTagIds), ...)
        // merges in an empty array. The scenario render()'s own comment
        // actually describes -- "2 or more tags that have no
        // intersection" -- implies each searched tag DOES have its own
        // non-empty image set (just no shared overlap), so give this tag a
        // real image elsewhere (a second photo, in a different album, so
        // it never enters the 'cat' filter's own item set) to exercise
        // that real path rather than the zero-image edge case, which is a
        // separate, pre-existing upstream gap outside this coverage pass's
        // scope.
        $otherAlbum = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Tag Merge Other Album ' . uniqid()]);
        $otherAlbumResult = $otherAlbum['result'] ?? null;
        if (! is_array($otherAlbumResult) || ! is_numeric($otherAlbumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($otherAlbum, true));
        }
        $otherAlbumId = (int) $otherAlbumResult['id'];
        $otherImage = H::makeTestImage(uniqid());
        $otherImageId = H::uploadPhotoViaApi($otherImage, $otherAlbumId, 'Search Tag Merge Other Photo');
        @unlink($otherImage);
        $setInfo = H::wsCall($page, 'pwg.images.setInfo', [
            'image_id' => $otherImageId,
            'tag_ids' => (string) $tagId,
            'multiple_value_mode' => 'append',
        ]);
        if (($setInfo['stat'] ?? null) !== 'ok') {
            throw new RuntimeException('pwg.images.setInfo failed to tag the other photo: ' . var_export($setInfo, true));
        }

        // 'cat' + 'tags' both active means getItemsForFilter('tags', ...)'s
        // own $otherFilters is non-empty (['cat']), so render() takes the
        // getCommonTags() branch rather than the "no other filters active
        // -> getAvailableTags()" one (already covered by
        // SearchFilterRendererTest.php's tag_id=1 case). Since the album's
        // only photo carries no tags at all, getCommonTags() over that
        // photo's id alone returns nothing -- so $missingTagIds ends up
        // containing this tag, forcing it back into the rendered list via
        // render()'s own documented fallback ("the user may have started a
        // search on 2 or more tags that have no intersection... We have to
        // 'force' them in the list").
        $search = H::wsCall($page, 'pwg.images.filteredSearch.create', [
            'tags' => $tagId,
            'categories' => $albumId,
        ]);
        $searchResult = $search['result'] ?? null;
        if (! is_array($searchResult) || ! is_string($searchResult['search_url'] ?? null)) {
            throw new RuntimeException('pwg.images.filteredSearch.create did not return a search_url: ' . var_export($search, true));
        }
        $searchUrl = $searchResult['search_url'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search tag merge with no intersection among other active filters');
        $page->assertNoJavaScriptErrors();

        // The tag select's <option> text is only reachable by reading the
        // raw response body -- it lives inside a <select multiple>, which
        // assertSee()'s visible-text check (Playwright's getByText()) can't
        // resolve reliably (same reasoning as
        // AlbumNotificationPageRendererTest.php's own H::rawWebpage(...)
        // ->content() use for a similarly form-hidden value).
        $html = extraFiltersSettledContent($page);
        expect($html)->toContain($tagName);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('serves the date-filter row/counter data from cache on a second load of a date-only search (no other active filter)', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'words' => ['access' => 'everybody', 'default' => true],
        'creation_date' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Date Cache Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Search Date Cache Photo');
        @unlink($image);
        // date_creation is NULL on a freshly-uploaded photo (no WS setter
        // for it, no EXIF metadata on a GD-generated test image -- same
        // established fact SearchFilterRendererDataFiltersTest.php's own
        // docblock documents) -- a 'date_created_preset=30d' filter can
        // never match a NULL date_creation, regardless of anything
        // render()/SearchService actually do. Real value needed, and
        // deliberately NOT combined with any other search field (see the
        // comment below on why this test needs 'date_created' to stay
        // the *only* active filter).
        extraFiltersSetImageAttrs($imageId, dateCreation: '2026-08-01 00:00:00');

        // 'date_created_preset' alone (no categories/tags/authors/...) is
        // the ONLY active search field: getItemsForFilter('date_created',
        // ...) then finds no OTHER active filter and returns false, so
        // getClauseForFilter() builds the plain permissions-only clause
        // ('1=1' + forbidden) instead of an 'image_id IN (...)' one -- the
        // only shape that makes $cacheApplicable true in
        // renderDateFilter(). SearchFilterRendererExtraFiltersTest.php's
        // OWN existing date-filter test above always drives it via
        // `cat_id`, which keeps 'cat' active alongside 'creation_date' and
        // so permanently takes the un-cached path instead -- it never
        // reaches this cache-hit branch.
        $search = H::wsCall($page, 'pwg.images.filteredSearch.create', [
            'date_created_preset' => '30d',
        ]);
        $searchResult = $search['result'] ?? null;
        if (! is_array($searchResult) || ! is_string($searchResult['search_url'] ?? null)) {
            throw new RuntimeException('pwg.images.filteredSearch.create did not return a search_url: ' . var_export($search, true));
        }
        $searchUrl = $searchResult['search_url'];

        // 1st load: cache miss -- computes pre_counters/list_of_dates from
        // a real DB query (this photo's own, just-uploaded date_creation
        // falls within the last 30 days) and stores the result.
        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'date-only search (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        expect(extraFiltersSettledContent($page))->toContain('Search Date Cache Photo');

        // 2nd load: same search -- exercises the is_array($cached) &&
        // is_array($cached['pre_counters']) && is_array($cached['list_of_dates'])
        // cache-hit branch instead of re-querying.
        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'date-only search (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        expect(extraFiltersSettledContent($page))->toContain('Search Date Cache Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Closes the render()-entry-shape gaps left after every test above (all of
 * which reach SearchFilterRenderer::render() through a real
 * pwg.images.filteredSearch.create()-persisted row, which always carries a
 * `fields` key -- SearchController's own default-filter mechanism, driven
 * through here, always pre-declares `'fields' => []` too): a directly
 * persisted `piwigo_search` row whose `rules` JSON has no top-level
 * "fields" key at all (render()'s own `$mySearch['fields'] = []`
 * fallback), and one whose `fields.tags`/`fields.author` values aren't the
 * array shape any real writer ever produces (render()'s own per-field
 * `! is_array($searchFields[x])` re-narrowing, reached only after that
 * field's own access check already passed).
 */
it('falls back to an empty search-fields array (and leaves has_filters_filled false) for a directly-persisted search row whose rules JSON has no "fields" key at all', function (): void {
    $page = H::loginAsAdmin($this);

    $uuid = extraFiltersInsertRawSearchRow(['mode' => 'AND']);

    $page = H::navigateOk($page, '/index.php?/search/' . $uuid);
    H::assertNoServerErrors($page, 'search row with rules missing the fields key entirely');
    $page->assertNoJavaScriptErrors();
});

it('resets a non-array "tags"/"author" search-field value back to an empty array before use', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'album' => ['access' => 'everybody', 'default' => true],
        'tags' => ['access' => 'everybody', 'default' => true],
        'author' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Malformed Fields Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Malformed Fields Photo');
        @unlink($image);

        // 'cat' carries a real, well-formed value so the search still
        // finds this photo despite 'tags'/'author' being malformed --
        // proving render() recovered from the bad shape rather than
        // merely not-crashing on an otherwise-empty search.
        $uuid = extraFiltersInsertRawSearchRow([
            'fields' => [
                'tags' => 'not-an-array',
                'author' => 'not-an-array',
                'cat' => ['words' => [$albumId], 'sub_inc' => false],
            ],
        ]);

        $page = H::navigateOk($page, '/index.php?/search/' . $uuid);
        H::assertNoServerErrors($page, 'search row with non-array tags/author field values');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Search Malformed Fields Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Closes render()'s own access-loop `else` branch (every configured
 * filter's `$displayFilters[$filtName]['access'] = false`) together with
 * the per-filter `elseif (isset($searchFields[x])) unset($searchFields[x])`
 * access-denied branch it feeds -- for every filter type besides
 * 'ratings'/'date_posted'/'date_created'/'cat', which prior coverage work
 * already closed this exact shape for. 'nobody' is not a real
 * filters_views access value (only 'everybody'/'admins-only'/
 * 'registered-users' are) -- deliberately so, since it fails every branch
 * of the access check regardless of which role is logged in, unlike
 * 'admins-only' (which an admin session would actually satisfy).
 */
it('denies access to and unsets every per-filter search field whose own filters_views access rule matches nobody', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'rate']);
    $filtersViews = json_encode([
        'words' => ['access' => 'nobody', 'default' => true],
        'expert' => ['access' => 'nobody', 'default' => true],
        'tags' => ['access' => 'nobody', 'default' => true],
        'album' => ['access' => 'everybody', 'default' => true],
        'author' => ['access' => 'nobody', 'default' => true],
        'added_by' => ['access' => 'nobody', 'default' => true],
        'file_type' => ['access' => 'nobody', 'default' => true],
        'ratio' => ['access' => 'nobody', 'default' => true],
        'rating' => ['access' => 'nobody', 'default' => true],
        'file_size' => ['access' => 'nobody', 'default' => true],
        'height' => ['access' => 'nobody', 'default' => true],
        'width' => ['access' => 'nobody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    // 'ratings' below is only persisted onto the search row when
    // rateEnabled() is true at CREATE time (same 2-step dependency
    // documented on the ratings-disabled test above) -- kept enabled at
    // RENDER time too, unlike that test: this one targets the
    // *access-denied* unset branch (rateEnabled()===true, 'rating' access
    // false), not the rateEnabled()===false branch that test covers.
    H::setConfigValue('rate', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Access Denied Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Access Denied Photo');
        @unlink($image);

        $tag = H::wsCall($page, 'pwg.tags.add', ['name' => 'Search Access Denied Tag ' . uniqid()]);
        $tagResult = $tag['result'] ?? null;
        if (! is_array($tagResult) || ! is_numeric($tagResult['id'] ?? null)) {
            throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($tag, true));
        }
        $tagId = (int) $tagResult['id'];

        $uniqueWord = 'accessdeniedword' . uniqid();

        // Every one of these fields is a real, non-empty value --
        // isset($searchFields[x]) is true for each below, so it's really
        // render()'s own per-filter access-denied unset that removes it,
        // not an absent field short-circuiting the check trivially. Only
        // 'album' stays accessible (see filters_views above), so the
        // search still finds a real photo through it.
        $search = H::wsCall($page, 'pwg.images.filteredSearch.create', [
            'allwords' => $uniqueWord,
            'expert' => 'Data',
            'tags' => $tagId,
            'authors' => 'Access Denied Author',
            'added_by' => 1,
            'categories' => $albumId,
            'filetypes' => 'jpg',
            'ratios' => 'square',
            'ratings' => '3',
            'filesize_min' => 100,
            'filesize_max' => 4000,
            'height_min' => 50,
            'height_max' => 1000,
            'width_min' => 50,
            'width_max' => 1000,
        ]);
        $searchResult = $search['result'] ?? null;
        if (! is_array($searchResult) || ! is_string($searchResult['search_url'] ?? null)) {
            throw new RuntimeException('pwg.images.filteredSearch.create did not return a search_url: ' . var_export($search, true));
        }
        $searchUrl = $searchResult['search_url'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search with every per-filter field denied except album');
        $page->assertNoJavaScriptErrors();
        expect(extraFiltersSettledContent($page))->toContain('Search Access Denied Photo');

        // Proves each field was actually unset from $mySearch['fields']
        // (not just hidden by a template gate): $GP is
        // json_encode($mySearch) after every access-denied unset above
        // has already run -- same technique the ratings-disabled test
        // above uses, scoped here to just the `global_params` JS blob to
        // rule out an unrelated same-named label elsewhere on the page.
        $globalParams = extraFiltersGlobalParamsJson(H::rawWebpage($page)->content());
        expect($globalParams)->not->toContain('"allwords"')
            ->and($globalParams)->not->toContain('"expert"')
            ->and($globalParams)->not->toContain('"tags"')
            ->and($globalParams)->not->toContain('"author"')
            ->and($globalParams)->not->toContain('"added_by"')
            ->and($globalParams)->not->toContain('"filetypes"')
            ->and($globalParams)->not->toContain('"ratios"')
            ->and($globalParams)->not->toContain('"ratings"')
            ->and($globalParams)->not->toContain('"filesize_min"')
            ->and($globalParams)->not->toContain('"height_min"')
            ->and($globalParams)->not->toContain('"width_min"')
            ->and($globalParams)->toContain('"cat"');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Closes renderAlbumsFound()'s own forbidden-category early return: this
 * method's own docblock documents that SearchService::searchAllwords()'s
 * category-name/comment match applies no forbidden-categories condition at
 * all, so `matching_cat_ids` can genuinely include a category the current
 * viewer can't see -- filterAccessibleCategoryIds() is what's supposed to
 * strip it back out before ALBUMS_FOUND ever gets assigned. Every other
 * allwords/ALBUMS_FOUND test in this suite runs as admin, who bypasses
 * category permissions entirely (forbiddenCategories always empty), so
 * this needs a genuine anonymous viewer instead.
 */
it('skips the ALBUMS_FOUND search hint entirely when every allwords-matched album is forbidden for the current (guest) viewer', function (): void {
    $page = H::loginAsAdmin($this);
    $uniqueWord = 'forbiddenalbumhint' . uniqid();
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Album ' . $uniqueWord]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    try {
        H::setCategoryPrivate($albumId, true);

        // A fresh visit() (not navigate() on the already-authenticated
        // $page above) starts a brand-new browser context with no
        // session cookie -- a genuine anonymous/guest viewer, whose own
        // CurrentUser::get()->forbiddenCategories now really does include
        // this just-privatized album (same mechanism
        // DerivativePermissionTest.php's own "denies an anonymous
        // request" tests already rely on).
        $guestPage = H::gotoOk($this, '/search.php?q=' . urlencode($uniqueWord));
        H::assertNoServerErrors($guestPage, 'guest allwords search matching only a forbidden album');
        $guestPage->assertNoJavaScriptErrors();

        // Not a bare ->not->toContain($uniqueWord) -- confirmed live: the
        // search page's own "you searched for" summary (.search-words) and
        // its global_params JS blob both legitimately echo back the raw
        // query text regardless of whether anything matched, so that
        // assertion could never pass for *any* search term. The real
        // security-relevant signal is renderAlbumsFound() actually assigning
        // ALBUMS_FOUND -- which only ever renders a link to the matched
        // category, via getCatDisplayNameCache() -- so the meaningful check
        // is that no such link to this specific forbidden category exists.
        expect(extraFiltersSettledContent($guestPage))->not->toContain('/category/' . $albumId);
    } finally {
        H::setCategoryPrivate($albumId, false);
    }
});
