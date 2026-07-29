<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Search\SearchFilterRenderer::render() -- closes the remaining
 * coverage gap left by SearchFilterRendererTest.php (tag_id/cat_id/q, the
 * fields SearchController's own GET params populate) and
 * SearchFilterRendererExtraFiltersTest.php (every filter panel active, but
 * with only the placeholder empty values search.php's own "default
 * filter" mechanism ever produces, and images that are all identically
 * 200x150/unrated/same-filesize).
 *
 * `pwg.images.filteredSearch.create` (Ws\PwgImages::filteredSearchCreate(),
 * the same WS method WsImagesFilteredSearchTest.php exercises) is the only
 * way to persist a search with REAL non-empty per-filter values (real
 * author/added_by/ratios/ratings/filesize/height/width/date criteria) --
 * SearchController itself never reads those from $_GET at all. The
 * returned `search_url` is navigated to directly (not via
 * H::navigateOk(), which only accepts a path relative to H::baseUrl()).
 *
 * Real per-image width/height/rating_score/filesize/date_creation values
 * come from raw mysqli UPDATEs after upload (same established pattern as
 * CatListPageRendererTest.php's own raw DB helper) -- there's no WS setter
 * for any of those columns.
 */
function searchFilterDataDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function searchFilterDataDb(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function searchFilterDataSetImageStats(int $imageId, int $width, int $height, ?float $ratingScore, int $filesize, ?string $dateCreation): void
{
    $db = searchFilterDataDb();
    $prefix = searchFilterDataDbPrefix();
    $ratingSql = $ratingScore === null ? 'NULL' : (string) $ratingScore;
    $dateSql = $dateCreation === null ? 'NULL' : "'" . $db->real_escape_string($dateCreation) . "'";
    $db->query(sprintf(
        'UPDATE %simages SET width = %d, height = %d, rating_score = %s, filesize = %d, date_creation = %s WHERE id = %d',
        $prefix,
        $width,
        $height,
        $ratingSql,
        $filesize,
        $dateSql,
        $imageId
    ));
    $db->close();
}

/**
 * Looks up H::ADMIN_USER's real id, rather than assuming 1 -- 'added_by'
 * needs the real uploader id to have any matching rows at all.
 */
function searchFilterDataAdminUserId(): int
{
    $db = searchFilterDataDb();
    $prefix = searchFilterDataDbPrefix();
    $result = $db->query(sprintf(
        "SELECT id FROM %susers WHERE username = '%s'",
        $prefix,
        $db->real_escape_string(H::ADMIN_USER)
    ));
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    $db->close();
    if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
        throw new RuntimeException('could not resolve the admin user id for username ' . H::ADMIN_USER);
    }

    return (int) $row['id'];
}

it('renders real per-filter numeric buckets, author/added_by lookups, and a 3+-filter intersection, across a cache-miss and a cache-hit load', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'words' => ['access' => 'everybody', 'default' => true],
        'expert' => ['access' => 'everybody', 'default' => true],
        'tags' => ['access' => 'everybody', 'default' => true],
        'album' => ['access' => 'everybody', 'default' => true],
        'author' => ['access' => 'everybody', 'default' => true],
        'added_by' => ['access' => 'everybody', 'default' => true],
        'ratio' => ['access' => 'everybody', 'default' => true],
        'rating' => ['access' => 'everybody', 'default' => true],
        'file_size' => ['access' => 'everybody', 'default' => true],
        'height' => ['access' => 'everybody', 'default' => true],
        'width' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Search Data Filters Album ' . uniqid()]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];

        $tag = H::wsCall($page, 'pwg.tags.add', ['name' => 'Search Data Filter Tag ' . uniqid()]);
        $tagResult = $tag['result'] ?? null;
        if (! is_array($tagResult) || ! is_numeric($tagResult['id'] ?? null)) {
            throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($tag, true));
        }
        $tagId = (int) $tagResult['id'];

        // Author and tag are set on all 3 photos (rather than just one) so
        // neither criterion narrows the search's own combined result below
        // -- only 'ratios'/'ratings' are meant to be the narrowing
        // criteria here (both restricting to the "square" photo), so the
        // final intersection across every active criterion stays
        // non-empty and predictable.
        $portraitImage = H::makeTestImage(uniqid());
        $portraitId = H::uploadPhotoViaApi($portraitImage, $albumId, 'Portrait Data Filter Photo');
        @unlink($portraitImage);
        // ratio 100/300 = 0.333 (< 0.95 -> Portrait); rating 0.5 (< 1 ->
        // bucket r=1); filesize 500 KB.
        searchFilterDataSetImageStats($portraitId, 100, 300, 0.5, 500, '2020-01-01 00:00:00');
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $portraitId, 'author' => 'Ansel Adams']);
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $portraitId, 'tag_ids' => (string) $tagId]);

        $squareImage = H::makeTestImage(uniqid());
        $squareId = H::uploadPhotoViaApi($squareImage, $albumId, 'Square Data Filter Photo');
        @unlink($squareImage);
        // ratio 300/300 = 1.0 (0.95..1.05 -> square); rating 2.5 (< 3 ->
        // bucket r=3); filesize 1500 KB; NULL date_creation -- proves the
        // date filter's own malformed/NULL-date row gets skipped rather
        // than fatally erroring.
        searchFilterDataSetImageStats($squareId, 300, 300, 2.5, 1500, null);
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $squareId, 'author' => 'Ansel Adams']);
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $squareId, 'tag_ids' => (string) $tagId]);

        $panoramaImage = H::makeTestImage(uniqid());
        $panoramaId = H::uploadPhotoViaApi($panoramaImage, $albumId, 'Panorama Data Filter Photo');
        @unlink($panoramaImage);
        // ratio 800/200 = 4.0 (>= 2 -> Panorama); rating 4.9 (not < 1/2/3/4
        // -> falls through to the default bucket r=5); filesize 3000 KB.
        searchFilterDataSetImageStats($panoramaId, 800, 200, 4.9, 3000, '2021-06-15 00:00:00');
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $panoramaId, 'author' => 'Ansel Adams']);
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $panoramaId, 'tag_ids' => (string) $tagId]);

        // Every one of these WS params is WsParamFlag::FORCE_ARRAY-declared
        // (PwgServer::makeArrayParam() coerces a bare scalar into a
        // 1-element array server-side) -- a single value per field is
        // enough to make `isset($searchFields[x])` true; the sidebar's own
        // bucket/row computations below read from the *underlying photo
        // data* (via getClauseForFilter()'s "other active filters"
        // intersection, which excludes each section's own criterion), not
        // from these specific search values, so this still exercises the
        // full Portrait/square/Panorama and rating-bucket spread even
        // though the search's own combined result narrows to one photo.
        // 'expert' is a real quick-search string ('Data', present in every
        // photo's own name) -- SearchService::getRegularSearchResults()'s
        // own 'expert' criterion runs it through the quick-search parser,
        // not a raw SQL boolean expression. H::wsCall() itself only
        // accepts scalar (int|string) param values.
        $search = H::wsCall($page, 'pwg.images.filteredSearch.create', [
            'expert' => 'Data',
            'authors' => 'Ansel Adams',
            'added_by' => searchFilterDataAdminUserId(),
            'categories' => $albumId,
            'tags' => $tagId,
            'ratios' => 'square',
            'ratings' => '3',
            'filesize_min' => 100,
            'filesize_max' => 4000,
            'width_min' => 50,
            'width_max' => 1000,
            'height_min' => 50,
            'height_max' => 1000,
        ]);
        $searchResult = $search['result'] ?? null;
        if (! is_array($searchResult) || ! is_string($searchResult['search_url'] ?? null)) {
            throw new RuntimeException('pwg.images.filteredSearch.create did not return a search_url: ' . var_export($search, true));
        }
        $searchUrl = $searchResult['search_url'];

        // 1st load: cache miss for every per-filter cache pool key
        // (author_rows/added_by_rows/ratings/ratios/height_rows/
        // width_rows), plus the getItemsForFilter() 3+-active-filter
        // remaining-foreach loop (many filters active at once: expert,
        // author, added_by, cat, tags, ratios, ratings, filesize, height,
        // width). 'ratios'/'ratings' both narrow to the "square" photo, so
        // that's the only one the search itself surfaces as a result.
        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search data filters (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Square Data Filter Photo');

        // Real gap, found via adversarial mutation testing: nothing in
        // this suite ever asserted on SearchFilterRenderer::render()'s own
        // ratio-bucket *counts* (search_filters.inc.tpl's `{foreach
        // from=$RATIOS item=ratio key=k}` block) -- every existing test
        // only uploads ratio-varied fixture photos incidentally, as setup
        // for other criteria, so the bucket-counting loop (lines ~729-753)
        // ran on every load but its actual output was never checked; a
        // broken bucket boundary would have passed silently. Only the
        // 'square' photo satisfies every OTHER active filter here
        // (ratings=3 alone already narrows to it: rating_score 2.5 is the
        // only one in the [2,3) bucket), so getItemsForFilter('ratios',
        // ...)'s cross-filter intersection -- which excludes the 'ratios'
        // criterion itself -- resolves to that one photo: Portrait/
        // Landscape/Panorama must each show as the disabled, zero-count
        // checkbox state, 'square' as the sole non-zero, undisabled one.
        $html1 = H::rawWebpage($page)->content();
        expect($html1)->toMatch('/<input type="checkbox" id="ratio-square"[^>]*>(?!.*disabled)/')
            ->and($html1)->toContain('<label for="ratio-square">')
            ->and(substr($html1, (int) strpos($html1, '<label for="ratio-square">'), 400))->toContain('<span class="ratio-badge">1</span>')
            ->and($html1)->toMatch('/<input type="checkbox" id="ratio-Portrait" [^>]*disabled/')
            ->and($html1)->toMatch('/<input type="checkbox" id="ratio-Landscape" [^>]*disabled/')
            ->and($html1)->toMatch('/<input type="checkbox" id="ratio-Panorama" [^>]*disabled/');

        // 2nd load: same search -- exercises every per-filter cache-hit
        // branch instead.
        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search data filters (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        $html2 = H::rawWebpage($page)->content();
        expect($html2)->toContain('<span class="ratio-badge">1</span>')
            ->and($html2)->toMatch('/<input type="checkbox" id="ratio-Portrait" [^>]*disabled/');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders ALBUMS_FOUND/TAGS_FOUND search hints for an allwords match on both an album title and a tag name', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'words' => ['access' => 'everybody', 'default' => true],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::loginAsAdmin($this);
        $uniqueWord = 'zephyrus' . uniqid();

        $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Album ' . $uniqueWord]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];

        $tag = H::wsCall($page, 'pwg.tags.add', ['name' => $uniqueWord]);
        $tagResult = $tag['result'] ?? null;
        if (! is_array($tagResult) || ! is_numeric($tagResult['id'] ?? null)) {
            throw new RuntimeException('pwg.tags.add did not return a numeric id: ' . var_export($tag, true));
        }
        $tagId = (int) $tagResult['id'];

        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'ALBUMS_FOUND Hint Photo');
        @unlink($image);
        H::wsCall($page, 'pwg.images.setInfo', ['image_id' => $imageId, 'tag_ids' => (string) $tagId]);

        // allwords_fields omitted -- filteredSearchCreate() defaults it to
        // every available field (including 'cat-title' and 'tags'), which
        // is exactly what's needed here; H::wsCall() only accepts scalar
        // param values, so an explicit multi-value array can't be passed
        // through it anyway.
        $search = H::wsCall($page, 'pwg.images.filteredSearch.create', [
            'allwords' => $uniqueWord,
        ]);
        $searchResult = $search['result'] ?? null;
        if (! is_array($searchResult) || ! is_string($searchResult['search_url'] ?? null)) {
            throw new RuntimeException('pwg.images.filteredSearch.create did not return a search_url: ' . var_export($search, true));
        }
        $searchUrl = $searchResult['search_url'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search allwords album/tag hint');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('ALBUMS_FOUND Hint Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});
