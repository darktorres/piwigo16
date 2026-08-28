<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Matches config/container.php's own SearchResultsCachePool::class
 * factory entry's literal namespace/TTL exactly -- built directly here,
 * not container-resolved: this Browser suite exercises the real app over
 * HTTP, with no Kernel::boot() of its own in this test process, and a
 * throwaway instance pointed at the same namespace clears the identical
 * shared backing (see AbstractNamedCachePool's own docblock -- pool
 * identity carries no correctness risk).
 */
function searchFilterRendererDataFiltersTestSearchResultsCachePool(): SearchResultsCachePool
{
    return new SearchResultsCachePool(CacheFactory::create(namespace: 'piwigo.search_results', defaultLifetime: 30));
}

function searchFilterDataDb(): mysqli|Connection
{
    return H::connect();
}

function searchFilterDataSetImageStats(int $imageId, int $width, int $height, ?float $ratingScore, int $filesize, ?string $dateCreation): void
{
    $db = searchFilterDataDb();
    $ratingSql = $ratingScore === null ? 'NULL' : (string) $ratingScore;
    $dateSql = $dateCreation === null ? 'NULL' : "'" . H::dbEscape($db, $dateCreation) . "'";
    H::dbQuery($db, sprintf('UPDATE images SET width = %d, height = %d, rating_score = %s, filesize = %d, date_creation = %s WHERE id = %d', $width, $height, $ratingSql, $filesize, $dateSql, $imageId));
    H::dbClose($db);
}

/**
 * Looks up H::ADMIN_USER's real id, rather than assuming 1 -- 'added_by'
 * needs the real uploader id to have any matching rows at all.
 */
function searchFilterDataAdminUserId(): int
{
    $db = searchFilterDataDb();
    $row = H::dbFetchAssoc($db, sprintf("SELECT id FROM users WHERE username = '%s'", H::dbEscape($db, H::ADMIN_USER)));
    H::dbClose($db);
    if (! is_array($row) || ! is_numeric($row['id'] ?? null)) {
        throw new RuntimeException('could not resolve the admin user id for username ' . H::ADMIN_USER);
    }

    return (int) $row['id'];
}

/**
 * `added_by` is nullable (`ON DELETE SET NULL` on its FK to users) but no
 * API setter exists for it -- every real upload always records the
 * uploading user's own id.
 */
function searchFilterDataSetAddedByNull(int $imageId): void
{
    $db = searchFilterDataDb();
    H::dbQuery($db, sprintf('UPDATE images SET added_by = NULL WHERE id = %d', $imageId));
    H::dbClose($db);
}

/**
 * `width`/`height` are both nullable -- no API setter for either, same
 * established fact this file's own docblock already documents for
 * filesize/rating_score/date_creation.
 */
function searchFilterDataSetImageDimsNull(int $imageId): void
{
    $db = searchFilterDataDb();
    H::dbQuery($db, sprintf('UPDATE images SET width = NULL, height = NULL WHERE id = %d', $imageId));
    H::dbClose($db);
}

/**
 * `filesize` is nullable -- no API setter for it either.
 */
function searchFilterDataSetImageFilesizeNull(int $imageId): void
{
    $db = searchFilterDataDb();
    H::dbQuery($db, sprintf('UPDATE images SET filesize = NULL WHERE id = %d', $imageId));
    H::dbClose($db);
}

it('renders real per-filter numeric buckets, author/added_by lookups, and a 3+-filter intersection, across a cache-miss and a cache-hit load', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'rate']);
    $filtersViews = json_encode([
        'words' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'expert' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'tags' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'album' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'author' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'added_by' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'ratio' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'rating' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'file_size' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'height' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'width' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    // Explicitly enabled (rather than relying on whatever `rate` happens
    // to already be) -- this test's own 'ratings' criterion (rating_score
    // 2-3, the only bucket the "square" photo satisfies) is what narrows
    // the 3+-filter intersection down to it; Images::filteredSearchCreate()
    // only persists 'ratings' onto the search row when rateEnabled() is
    // true at creation time, so an ambient-disabled `rate` silently drops
    // it from the active-filter set, breaking the ratio-bucket assertions
    // below the same way SearchFilterRendererExtraFiltersTest.php's own
    // rate-disable test's docblock already documents.
    H::setConfigValue('rate', 'true');

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Data Filters Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];

        $tag = H::createTag($page, [
            'name' => 'Search Data Filter Tag ' . uniqid(),
        ]);
        if (! is_numeric($tag['id'] ?? null)) {
            throw new RuntimeException('createTag did not return a numeric id: ' . var_export($tag, true));
        }
        $tagId = (int) $tag['id'];

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
        H::updateImageInfo($page, [
            'image_id' => $portraitId,
            'author' => 'Ansel Adams',
        ]);
        H::updateImageInfo($page, [
            'image_id' => $portraitId,
            'tag_ids' => (string) $tagId,
        ]);

        $squareImage = H::makeTestImage(uniqid());
        $squareId = H::uploadPhotoViaApi($squareImage, $albumId, 'Square Data Filter Photo');
        @unlink($squareImage);
        // ratio 300/300 = 1.0 (0.95..1.05 -> square); rating 2.5 (< 3 ->
        // bucket r=3); filesize 1500 KB; NULL date_creation -- proves the
        // date filter's own malformed/NULL-date row gets skipped rather
        // than fatally erroring.
        searchFilterDataSetImageStats($squareId, 300, 300, 2.5, 1500, null);
        H::updateImageInfo($page, [
            'image_id' => $squareId,
            'author' => 'Ansel Adams',
        ]);
        H::updateImageInfo($page, [
            'image_id' => $squareId,
            'tag_ids' => (string) $tagId,
        ]);

        $panoramaImage = H::makeTestImage(uniqid());
        $panoramaId = H::uploadPhotoViaApi($panoramaImage, $albumId, 'Panorama Data Filter Photo');
        @unlink($panoramaImage);
        // ratio 800/200 = 4.0 (>= 2 -> Panorama); rating 4.9 (not < 1/2/3/4
        // -> falls through to the default bucket r=5); filesize 3000 KB.
        searchFilterDataSetImageStats($panoramaId, 800, 200, 4.9, 3000, '2021-06-15 00:00:00');
        H::updateImageInfo($page, [
            'image_id' => $panoramaId,
            'author' => 'Ansel Adams',
        ]);
        H::updateImageInfo($page, [
            'image_id' => $panoramaId,
            'tag_ids' => (string) $tagId,
        ]);

        // A single tag id is already a real, complete comma-joined tagIds
        // value on its own (ImageUpdateController explodes it into a
        // 1-element list server-side) -- enough to make
        // `isset($searchFields[x])` true; the sidebar's own
        // bucket/row computations below read from the *underlying photo
        // data* (via getClauseForFilter()'s "other active filters"
        // intersection, which excludes each section's own criterion), not
        // from these specific search values, so this still exercises the
        // full Portrait/square/Panorama and rating-bucket spread even
        // though the search's own combined result narrows to one photo.
        // 'expert' is a real quick-search string ('Data', present in every
        // photo's own name) -- SearchService::getRegularSearchResults()'s
        // own 'expert' criterion runs it through the quick-search parser,
        // not a raw SQL boolean expression. createFilteredSearch() itself
        // only accepts scalar (int|string) param values.
        $search = H::createFilteredSearch($page, [
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
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

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
        H::assertSeeSettled($page, 'Square Data Filter Photo');

        // Real gap, found via adversarial mutation testing: nothing in
        // this suite ever asserted on SearchFilterRenderer::render()'s own
        // ratio-bucket *counts* (search_filters.inc.latte's `{foreach
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
        expect($html1)
            ->toMatch('/<input type="checkbox" id="ratio-square"[^>]*>(?!.*disabled)/')
            ->and($html1)
            ->toContain('<label for="ratio-square">')
            ->and(substr($html1, (int) strpos($html1, '<label for="ratio-square">'), 400))
            ->toContain('<span class="ratio-badge">1</span>')
            ->and($html1)
            ->toMatch('/<input type="checkbox" id="ratio-Portrait" [^>]*disabled/')
            ->and($html1)
            ->toMatch('/<input type="checkbox" id="ratio-Landscape" [^>]*disabled/')
            ->and($html1)
            ->toMatch('/<input type="checkbox" id="ratio-Panorama" [^>]*disabled/');

        // 2nd load: same search -- exercises every per-filter cache-hit
        // branch instead.
        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search data filters (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        $html2 = H::rawWebpage($page)->content();
        expect($html2)
            ->toContain('<span class="ratio-badge">1</span>')
            ->and($html2)
            ->toMatch('/<input type="checkbox" id="ratio-Portrait" [^>]*disabled/');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders ALBUMS_FOUND/TAGS_FOUND search hints for an allwords match on both an album title and a tag name', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'words' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::asAdmin($this);
        $uniqueWord = 'zephyrus' . uniqid();

        $album = H::createCategory($page, [
            'name' => 'Album ' . $uniqueWord,
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];

        $tag = H::createTag($page, [
            'name' => $uniqueWord,
        ]);
        if (! is_numeric($tag['id'] ?? null)) {
            throw new RuntimeException('createTag did not return a numeric id: ' . var_export($tag, true));
        }
        $tagId = (int) $tag['id'];

        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'ALBUMS_FOUND Hint Photo');
        @unlink($image);
        H::updateImageInfo($page, [
            'image_id' => $imageId,
            'tag_ids' => (string) $tagId,
        ]);

        // allwords_fields omitted -- filteredSearchCreate() defaults it to
        // every available field (including 'cat-title' and 'tags'), which
        // is exactly what's needed here; createFilteredSearch() only
        // accepts scalar param values, so an explicit multi-value array
        // can't be passed through it anyway.
        $search = H::createFilteredSearch($page, [
            'allwords' => $uniqueWord,
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'search allwords album/tag hint');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'ALBUMS_FOUND Hint Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Closes the "single active filter" cache-branch gap left by the big
 * multi-filter test above: with expert/author/added_by/cat/tags/ratios/
 * ratings/filesize/height/width ALL active at once, getItemsForFilter()
 * always finds at least one OTHER active filter for every single one of
 * them, so getClauseForFilter() always builds an 'image_id IN (...)'
 * clause there -- never the plain permissions-only clause that's the ONLY
 * shape making `$this->cacheGet($cacheKey)`/`cacheSet($cacheKey, ...)`
 * (the `author_rows_<user>` cache pool entry, lines ~284-289) actually run.
 * Each of the 4 tests below isolates exactly one such facet as the sole
 * active field, matching the precedent
 * SearchFilterRendererExtraFiltersTest.php's own "date-only search" test
 * already set for date_created.
 */
it('serves the author-rows cache pool across a cache-miss then cache-hit load when author is the only active search filter', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'author' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Author Only Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Search Author Only Photo');
        @unlink($image);
        $authorName = 'Author Only ' . uniqid();
        H::updateImageInfo($page, [
            'image_id' => $imageId,
            'author' => $authorName,
        ]);

        // 'authors' alone (no categories/tags/...) is the ONLY active
        // search field.
        $search = H::createFilteredSearch($page, [
            'authors' => $authorName,
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'author-only search (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Author Only Photo');

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'author-only search (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Author Only Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Same "sole active filter" cache-branch gap as the author-only test
 * above, for the `added_by_rows_<user>` cache pool entry (lines ~394-399).
 * Also closes the `! is_string($addedById)` continue guard (line ~442)
 * that skips enriching a NULL `added_by` group with a username: a second,
 * unrelated photo has `added_by` forced to NULL via raw SQL (no API setter
 * for it -- every real upload always records the uploading user's own
 * id), landing in the same unscoped `added_by AS added_by_id` GROUP BY
 * result set this search's own permissions-only clause scans.
 */
it('serves the added_by-rows cache pool across a cache-miss then cache-hit load, skipping a NULL added_by row along the way', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'order_by']);
    $filtersViews = json_encode([
        'added_by' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    // Same "buries this test's own photo past page 1" reasoning as the
    // height-rows test below -- see its own comment. added_by=$adminId is
    // exactly as non-selective as height/width/ratios/filetypes here
    // (every fixture photo is uploaded via the same H::ADMIN_USER).
    H::setConfigValue('order_by', H::jsonEncode('ORDER BY id DESC'));

    try {
        $page = H::asAdmin($this);
        $adminId = searchFilterDataAdminUserId();
        $album = H::createCategory($page, [
            'name' => 'Search Added By Only Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Added By Only Photo');
        @unlink($image);

        $nullImage = H::makeTestImage(uniqid());
        $nullImageId = H::uploadPhotoViaApi($nullImage, $albumId, 'Search Added By Null Photo');
        @unlink($nullImage);
        searchFilterDataSetAddedByNull($nullImageId);

        // 'added_by' alone (no categories/tags/...) is the ONLY active
        // search field.
        $search = H::createFilteredSearch($page, [
            'added_by' => $adminId,
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'added_by-only search (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Added By Only Photo');

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'added_by-only search (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Added By Only Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Same "sole active filter" cache-branch gap, for the `height_rows_<user>`
 * cache pool entry (lines ~805-810). BrowserTestHelpers::makeTestImage()'s
 * fixed 200x150 dimensions (no per-test override needed) already fall
 * inside this range.
 */
it('serves the height-rows cache pool across a cache-miss then cache-hit load when height is the only active search filter', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'order_by']);
    $filtersViews = json_encode([
        'height' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    // height_min/max is deliberately non-selective (every makeTestImage()
    // fixture is an identical 200x150 real .jpg, so it matches every test
    // photo ever created, not just this test's own upload) -- the default
    // sort (`date_available DESC, file ASC, id ASC`) can't tell them apart
    // either, since every fixture photo shares the same frozen test-clock
    // date_available ('2026-08-01 00:00:00' on every row,
    // old and new alike), leaving `id ASC` as the real tie-break and
    // burying this test's own just-created (highest id) photo past page 1
    // once enough prior suite runs have piled up matching rows. Ordering
    // by id DESC instead puts it on page 1 deterministically, regardless
    // of how much test history the shared DB already carries.
    H::setConfigValue('order_by', H::jsonEncode('ORDER BY id DESC'));
    // The height_rows_<user> cache key (30s TTL) isn't scoped by search
    // criteria, only by user id -- an unrelated earlier test's own height
    // search by the same admin user, still within its TTL, would otherwise
    // make this test's own "1st load" a stale cache hit that predates the
    // photo uploaded below.
    searchFilterRendererDataFiltersTestSearchResultsCachePool()
        ->clear();

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Height Only Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Height Only Photo');
        @unlink($image);

        // 'height_min'/'height_max' alone (no categories/tags/...) is the
        // ONLY active search field.
        $search = H::createFilteredSearch($page, [
            'height_min' => 100,
            'height_max' => 200,
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'height-only search (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Height Only Photo');

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'height-only search (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Height Only Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Same "sole active filter" cache-branch gap, for the `width_rows_<user>`
 * cache pool entry (lines ~864-869).
 */
it('serves the width-rows cache pool across a cache-miss then cache-hit load when width is the only active search filter', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'order_by']);
    $filtersViews = json_encode([
        'width' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    // Same "buries this test's own photo past page 1" reasoning as the
    // height-rows test above -- see its own comment.
    H::setConfigValue('order_by', H::jsonEncode('ORDER BY id DESC'));
    // Same reasoning as the height-rows test above -- width_rows_<user> is
    // keyed only by user id, so a nearby earlier width search by the same
    // admin user can serve this test a stale, pre-upload cache hit on its
    // own "1st load" within the pool's 30s TTL.
    searchFilterRendererDataFiltersTestSearchResultsCachePool()
        ->clear();

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Width Only Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Width Only Photo');
        @unlink($image);

        // 'width_min'/'width_max' alone (no categories/tags/...) is the
        // ONLY active search field.
        $search = H::createFilteredSearch($page, [
            'width_min' => 100,
            'width_max' => 300,
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'width-only search (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Width Only Photo');

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'width-only search (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Width Only Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Same "sole active filter" cache-branch gap, for the `ratios_<user>`
 * cache pool entry (lines ~778-783) -- distinct from the ratio-bucket
 * *counting* test below, which deliberately keeps 'cat' active alongside
 * 'ratios' for an exact, scoped bucket count instead (the two concerns
 * can't be satisfied by the same request: any other active filter is
 * exactly what makes getClauseForFilter() skip the cache pool).
 * makeTestImage()'s fixed 200x150 dimensions are already a real
 * "Landscape" ratio (200/150 ≈ 1.33).
 */
it('serves the ratios cache pool across a cache-miss then cache-hit load when ratios is the only active search filter', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'order_by']);
    $filtersViews = json_encode([
        'ratio' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    // Same "buries this test's own photo past page 1" reasoning as the
    // height-rows test above -- see its own comment. "Landscape" is
    // exactly as non-selective as height/width here (every makeTestImage()
    // fixture's fixed 200x150 dimensions already fall in that ratio
    // bucket).
    H::setConfigValue('order_by', H::jsonEncode('ORDER BY id DESC'));
    // Same reasoning as the height-rows test above -- ratios_<user> is
    // keyed only by user id, so a nearby earlier ratios search by the same
    // admin user can serve this test a stale, pre-upload cache hit on its
    // own "1st load" within the pool's 30s TTL.
    searchFilterRendererDataFiltersTestSearchResultsCachePool()
        ->clear();

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Ratios Only Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Ratios Only Photo');
        @unlink($image);

        // 'ratios' alone (no categories/tags/...) is the ONLY active
        // search field.
        $search = H::createFilteredSearch($page, [
            'ratios' => 'Landscape',
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'ratios-only search (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Ratios Only Photo');

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'ratios-only search (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Ratios Only Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Same "sole active filter" cache-branch gap, for the `ratings_<user>`
 * cache pool entry (line ~641's cacheSet() call specifically -- distinct
 * from that same block's own access-denied unset branch, lines ~645-646,
 * which SearchFilterRendererExtraFiltersTest.php's own access-denied test
 * closes).
 */
it('serves the ratings cache pool across a cache-miss then cache-hit load when ratings is the only active search filter', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'rate']);
    $filtersViews = json_encode([
        'rating' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    H::setConfigValue('rate', 'true');

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Ratings Only Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Search Ratings Only Photo');
        @unlink($image);
        // rating 2.5 -> bucket r=3 (see the big multi-filter test above's
        // own comment on this bucketing rule).
        searchFilterDataSetImageStats($imageId, 200, 150, 2.5, 500, null);

        // 'ratings' alone (no categories/tags/...) is the ONLY active
        // search field.
        $search = H::createFilteredSearch($page, [
            'ratings' => '3',
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'ratings-only search (1st load, cache miss)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Ratings Only Photo');

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'ratings-only search (2nd load, cache hit)');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Ratings Only Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * ImageFilteredSearchCreateController used to accept `ratings` in any
 * shape at all -- a bare scalar (the real client's own submission shape,
 * per mcs.js's ".ratings-option input:checked" checkbox collection into
 * an array -- but nothing enforced it server-side) silently reached
 * search.fields.ratings un-normalized, which SearchService::
 * applyFilters() then treated as an empty filter (is_array() ?
 * array_filter(...) : []) -- the ratings filter silently did nothing --
 * and which the results page's own filter-panel JS (mcs.js's
 * global_params.fields.ratings.forEach(...)) crashed on outright, since
 * it assumes array shape unconditionally. Same array-of-strings
 * validation `ratios` already has, now mirrored for `ratings`.
 */
it('rejects a non-array ratings value with 422', function (): void {
    $snapshot = H::snapshotConfig(['rate']);
    H::setConfigValue('rate', 'true');

    try {
        $page = H::asAdmin($this);

        $result = H::rawPostJson($page, '/api/v1/images/searches', [
            'ratings' => '3',
        ]);

        expect($result['status'])->toBe(422);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects a ratings array containing a non-string element with 422', function (): void {
    $snapshot = H::snapshotConfig(['rate']);
    H::setConfigValue('rate', 'true');

    try {
        $page = H::asAdmin($this);

        $result = H::rawPostJson($page, '/api/v1/images/searches', [
            'ratings' => [3],
        ]);

        expect($result['status'])->toBe(422);
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * `null` here means the JS `fetch()` call passes no `Content-Type`
 * header at all -- but per the Fetch spec, a plain string body with no
 * explicit header still gets browsers' own default
 * (`text/plain;charset=UTF-8`), so this and the "wrong type" case both
 * exercise the same `JsonBody::decode()` branch (any media type other
 * than `application/json`) rather than a separate "header absent" one;
 * there isn't a distinct one to test.
 */
it('rejects a non-empty body with the wrong (or missing) Content-Type with 415', function (): void {
    $page = H::asAdmin($this);

    $result = H::rawPostRawBody($page, '/api/v1/images/searches', '{"tags":[1]}', 'text/plain');
    expect($result['status'])->toBe(415);

    $result = H::rawPostRawBody($page, '/api/v1/images/searches', '{"tags":[1]}', null);
    expect($result['status'])->toBe(415);
});

it('creates a distinct search on every call with no Idempotency-Key header', function (): void {
    $page = H::asAdmin($this);

    $first = H::rawPostJson($page, '/api/v1/images/searches', [
        'tags' => [1],
    ]);
    $second = H::rawPostJson($page, '/api/v1/images/searches', [
        'tags' => [1],
    ]);

    $firstId = json_decode($first['body'], true);
    $secondId = json_decode($second['body'], true);
    expect(is_array($firstId) ? $firstId['searchId'] ?? null : null)
        ->not->toBe(is_array($secondId) ? $secondId['searchId'] ?? null : null);
});

it('replays the stored response for a repeated Idempotency-Key with the same body', function (): void {
    $page = H::asAdmin($this);
    $key = 'test-idempotency-' . uniqid();

    $first = H::rawPostJson($page, '/api/v1/images/searches', [
        'tags' => [1],
    ], [
        'Idempotency-Key' => $key,
    ]);
    $second = H::rawPostJson($page, '/api/v1/images/searches', [
        'tags' => [1],
    ], [
        'Idempotency-Key' => $key,
    ]);

    expect($second['status'])->toBe($first['status']);
    expect($second['body'])->toBe($first['body']);
});

it('rejects a repeated Idempotency-Key with a different body with 400', function (): void {
    $page = H::asAdmin($this);
    $key = 'test-idempotency-conflict-' . uniqid();

    $first = H::rawPostJson($page, '/api/v1/images/searches', [
        'tags' => [1],
    ], [
        'Idempotency-Key' => $key,
    ]);
    expect($first['status'])->toBe(201);

    $second = H::rawPostJson($page, '/api/v1/images/searches', [
        'tags' => [2],
    ], [
        'Idempotency-Key' => $key,
    ]);
    expect($second['status'])->toBe(400);
});

/**
 * Closes the `filetypes`-is-the-only-active-filter branch (line ~585):
 * with no OTHER active filter, getClauseForFilter('filetypes', ...)
 * returns the plain permissions-only clause (not 'image_id IN (...)'), so
 * `$allExts` (every visible extension's own count, unnarrowed by the
 * search) is assigned straight to the FILETYPES template var instead of
 * the per-extension-intersected `$exts` the big multi-filter test above
 * exercises.
 */
it('assigns the un-narrowed FILETYPES extension counts when filetypes is the only active search filter', function (): void {
    $snapshot = H::snapshotConfig(['filters_views', 'order_by']);
    $filtersViews = json_encode([
        'file_type' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);
    // Same "buries this test's own photo past page 1" reasoning as the
    // height-rows test above -- see its own comment. 'jpg' is exactly as
    // non-selective as height/width here (every makeTestImage() fixture is
    // a real .jpg upload).
    H::setConfigValue('order_by', H::jsonEncode('ORDER BY id DESC'));
    // Same reasoning as the height-rows test above -- file_exts_<user> is
    // keyed only by user id, so a nearby earlier filetypes search by the
    // same admin user can serve this test a stale, pre-upload cache hit
    // within the pool's 30s TTL.
    searchFilterRendererDataFiltersTestSearchResultsCachePool()
        ->clear();

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Filetypes Only Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($image, $albumId, 'Search Filetypes Only Photo');
        @unlink($image);

        // 'filetypes' alone (no categories/tags/...) is the ONLY active
        // search field -- every test photo in this suite is a real .jpg
        // upload (BrowserTestHelpers::makeTestImage()).
        $search = H::createFilteredSearch($page, [
            'filetypes' => 'jpg',
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'filetypes-only search');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Filetypes Only Photo');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Closes the "Landscape" ratio-bucket increment (the one bucket the big
 * multi-filter test above's own Portrait/square/Panorama fixture set never
 * exercises) together with the ratio-bucket loop's own defensive skips:
 * a non-numeric width/height row (`continue`, line ~751 -- width/height
 * forced NULL via raw SQL, no API setter for either) and a
 * both-dimensions-zero row (`$rowWidth <= 0 and $rowHeight <= 0`, line
 * ~757-758). Deliberately scoped via 'cat' (unlike the ratios-only cache
 * test above) so the bucket counts below are exact and not diluted by
 * every other visible photo in the whole gallery.
 */
it('counts the "Landscape" ratio bucket and skips non-numeric/zero-dimension rows when computing ratio buckets', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'album' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'ratio' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Ratio Landscape Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];

        // 200/150 ≈ 1.33 -> Landscape (> 1.05 and < 2).
        $landscapeImage = H::makeTestImage(uniqid());
        H::uploadPhotoViaApi($landscapeImage, $albumId, 'Search Ratio Landscape Photo');
        @unlink($landscapeImage);

        $zeroImage = H::makeTestImage(uniqid());
        $zeroId = H::uploadPhotoViaApi($zeroImage, $albumId, 'Search Ratio Zero Dims Photo');
        @unlink($zeroImage);
        searchFilterDataSetImageStats($zeroId, 0, 0, null, 500, null);

        $nullDimsImage = H::makeTestImage(uniqid());
        $nullDimsId = H::uploadPhotoViaApi($nullDimsImage, $albumId, 'Search Ratio Null Dims Photo');
        @unlink($nullDimsImage);
        searchFilterDataSetImageDimsNull($nullDimsId);

        $search = H::createFilteredSearch($page, [
            'categories' => $albumId,
            'ratios' => 'Landscape',
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'ratio bucket landscape/zero/null-dims search');
        $page->assertNoJavaScriptErrors();
        H::assertSeeSettled($page, 'Search Ratio Landscape Photo');

        $html = H::rawWebpage($page)->content();
        // Only the Landscape photo counts -- the zero-dims and null-dims
        // rows are both skipped by the loop's own defensive guards, so
        // Portrait/square/Panorama all stay at their zero-count, disabled
        // state (same assertion style as the big multi-filter test's own
        // ratio-bucket assertions above).
        expect($html)
            ->toMatch('/<input type="checkbox" id="ratio-Landscape"[^>]*>(?!.*disabled)/')
            ->and($html)
            ->toContain('<label for="ratio-Landscape">')
            ->and(substr($html, (int) strpos($html, '<label for="ratio-Landscape">'), 400))
            ->toContain('<span class="ratio-badge">1</span>')
            ->and($html)
            ->toMatch('/<input type="checkbox" id="ratio-Portrait" [^>]*disabled/')
            ->and($html)
            ->toMatch('/<input type="checkbox" id="ratio-square" [^>]*disabled/')
            ->and($html)
            ->toMatch('/<input type="checkbox" id="ratio-Panorama" [^>]*disabled/');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * Closes the filesize-bucket loop's own `! is_numeric($row['filesize'])`
 * continue guard (line ~673, filesize forced NULL via raw SQL, no API
 * setter for it) together with the "no bucket values at all" arbitrary
 * fallback array (line ~679-686) it feeds into when every row in scope
 * gets skipped that way -- distinguishable from a genuinely-empty gallery
 * by its exact, fixed bounds (0..15), asserted below via the slider's own
 * `data-min`/`data-max` attributes (search_filters.inc.latte).
 */
it('falls back to the arbitrary filesize bucket set when every filesize row in scope is NULL', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'album' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'file_size' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Filesize Null Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];
        $image = H::makeTestImage(uniqid());
        $imageId = H::uploadPhotoViaApi($image, $albumId, 'Search Filesize Null Photo');
        @unlink($image);
        searchFilterDataSetImageFilesizeNull($imageId);

        // 'cat' scopes the filesize-bucket query to just this one,
        // NULL-filesize photo -- 'filesize_min'/'filesize_max' themselves
        // never match it (`filesize BETWEEN ? AND ?` is unknown/false for
        // a NULL filesize), so the search's own overall result is empty;
        // that's fine here, this test targets the sidebar bucket
        // computation, not the search's own item list.
        $search = H::createFilteredSearch($page, [
            'categories' => $albumId,
            'filesize_min' => 100,
            'filesize_max' => 4000,
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }
        $searchUrl = $search['searchUrl'];

        H::rawWebpage($page)->navigate($searchUrl);
        H::assertNoServerErrors($page, 'filesize bucket fallback search (every row NULL)');
        $page->assertNoJavaScriptErrors();

        $html = H::rawWebpage($page)->content();
        expect($html)
            ->toContain('data-min="0"')
            ->and($html)
            ->toContain('data-max="15"');
    } finally {
        H::restoreConfig($snapshot);
    }
});

/**
 * `width`/`height`/`filesize` are all nullable and none has an API setter,
 * so a test that needs specific values sets them directly -- the same
 * established fact this file's own docblock documents for the NULL-ing
 * helpers above.
 */
function searchFilterDataSetImageDims(int $imageId, int $width, int $height, int $filesize): void
{
    $db = searchFilterDataDb();
    H::dbQuery($db, sprintf(
        'UPDATE images SET width = %d, height = %d, filesize = %d WHERE id = %d',
        $width,
        $height,
        $filesize,
        $imageId,
    ));
    H::dbClose($db);
}

/**
 * P58 step 0b. search_filters.inc.latte emits 12 values from the filesize,
 * height and width sliders -- `['bounds']['min'|'max']` into the clear
 * button's data-min/data-max, and `['selected']['min'|'max']` into the
 * inputs' value= -- and 10 of them had no assertion anywhere. The two that
 * did (filesize's bounds, asserted by the fallback test above) only ever
 * ran on the path where every filesize row is NULL, so they checked the
 * arbitrary 0..15 bucket set rather than anything derived from real rows.
 *
 * That matters now because SearchFilterData is the largest single class in
 * P58-A's leaf-projection step (38 findings), and these are the expressions
 * that retype has to leave byte-identical. Asserting them afterwards would
 * prove nothing: the baseline would already contain whatever the retype
 * emitted.
 *
 * Three photos with distinct dimensions give bounds a real range instead of
 * the fixture's uniform 200x150, and the search's own min/max are chosen so
 * `selected` and `bounds` come out different, so neither can be mistaken for
 * the other. Filesize buckets are `filesize / 1024` to one decimal (the
 * column is KB, the slider is MB), so the three values are a megabyte apart
 * to land in three distinct buckets.
 */
it('emits real slider bounds and selected values for the filesize, height and width filters', function (): void {
    $snapshot = H::snapshotConfig(['filters_views']);
    $filtersViews = json_encode([
        'file_size' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'height' => [
            'access' => 'everybody',
            'default' => true,
        ],
        'width' => [
            'access' => 'everybody',
            'default' => true,
        ],
    ]);
    if ($filtersViews === false) {
        throw new RuntimeException('json_encode failed for the filters_views config value');
    }
    H::setConfigValue('filters_views', $filtersViews);

    try {
        $page = H::asAdmin($this);
        $album = H::createCategory($page, [
            'name' => 'Search Slider Bounds Album ' . uniqid(),
        ]);
        if (! is_numeric($album['id'] ?? null)) {
            throw new RuntimeException('createCategory did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $album['id'];

        $dims = [
            [600, 300, 1024],
            [650, 500, 2048],
            [700, 700, 4096],
        ];
        foreach ($dims as [$w, $h, $filesize]) {
            $image = H::makeTestImage(uniqid());
            $imageId = H::uploadPhotoViaApi($image, $albumId, sprintf('Slider Photo %dx%d', $w, $h));
            @unlink($image);
            searchFilterDataSetImageDims($imageId, $w, $h, $filesize);
        }

        $search = H::createFilteredSearch($page, [
            'categories' => $albumId,
            'filesize_min' => 2048,
            'filesize_max' => 4096,
            'height_min' => 500,
            'height_max' => 700,
            'width_min' => 600,
            'width_max' => 700,
        ]);
        if (! is_string($search['searchUrl'] ?? null)) {
            throw new RuntimeException('createFilteredSearch did not return a searchUrl: ' . var_export($search, true));
        }

        H::rawWebpage($page)->navigate($search['searchUrl']);
        H::assertNoServerErrors($page, 'slider bounds search');
        $html = H::rawWebpage($page)->content();

        preg_match_all('/data-(min|max)="([^"]*)"/', $html, $b, PREG_SET_ORDER);
        preg_match_all('/name="(filter_(?:filesize|height|width)_[a-z_]*)"\s*value="([^"]*)"/', $html, $v, PREG_SET_ORDER);

        // Each filter's own bucket set is deliberately computed under the
        // *other* active filters rather than its own -- the renderer says so
        // itself ("the min/max of the current search won't always be the
        // first/last values found"), via getClauseForFilter(). So filesize's
        // bounds are taken with height 500..700 and width 600..800 applied,
        // and height's with filesize 2048..4096 and width 600..800 applied.
        // Either way only the second and third photo qualify, so both pairs
        // are this album's own values and the 400x300/1024 one is excluded.
        //
        // That clause does NOT include the category filter, so the ranges
        // here have to exclude every other photo in the gallery by dimension
        // alone. Both halves of that were found by being wrong first, and
        // are why the numbers look arbitrary:
        //
        //  - a draft using 200x150 photos read a filesize lower bound of 0.0
        //    instead of its own album's 1.0, because every makeTestImage
        //    photo in the gallery (all fixed at 200x150, a few KB) fell
        //    inside its height/width range and bucketed to 0.0;
        //  - a draft using width 600..800 read a height lower bound of 200,
        //    because the ratio-bucket test above creates 800x200 panorama
        //    photos at filesize 3000, which sit inside both the width and
        //    the filesize range. Hence 600..700: it excludes them by width.
        //
        // Both only appear when the file runs as a whole, not when this test
        // runs alone.
        //
        // Exactly four data-min/data-max values, not six: the width filter's
        // clear button emits neither, unlike filesize's and height's. That
        // asymmetry is pre-existing and is left alone here -- P58 is type
        // work -- but pinning the count keeps it from changing unnoticed.
        expect(array_map(static fn (array $m): string => $m[1] . '=' . $m[2], $b))
            ->toBe(['min=2.0', 'max=4.0', 'min=500', 'max=700']);

        // The eight 'selected' values are the search's own rules echoed back,
        // and are chosen to sit strictly inside the bounds above so neither
        // can be mistaken for the other. filesize is the one that converts:
        // the column is KB and the slider is MB, so 2048 renders as '2.0'.
        expect(array_map(static fn (array $m): string => $m[1] . '=' . $m[2], $v))
            ->toBe([
                'filter_filesize_min_text=2.0',
                'filter_filesize_max_text=4.0',
                'filter_filesize_min=2.0',
                'filter_filesize_max=4.0',
                'filter_height_min=500',
                'filter_height_max=700',
                'filter_width_min=600',
                'filter_width_max=700',
            ]);
    } finally {
        H::restoreConfig($snapshot);
    }
});
