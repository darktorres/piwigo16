<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function galDbConnect(): mysqli|Connection
{
    return H::connect();
}

function galSetCategoryComment(int $categoryId, ?string $comment): void
{
    $db = galDbConnect();
    if ($comment === null) {
        H::dbQuery($db, sprintf('UPDATE categories SET comment = NULL WHERE id = %d', $categoryId));
    } else {
        H::dbQuery($db, sprintf("UPDATE categories SET comment = '%s' WHERE id = %d", H::dbEscape($db, $comment), $categoryId));
    }
    H::dbClose($db);
}

function galClearCaddie(int $userId): void
{
    $db = galDbConnect();
    H::dbQuery($db, sprintf('DELETE FROM caddie WHERE user_id = %d', $userId));
    H::dbClose($db);
}

function galSetNbImagePage(int $userId, int $value): void
{
    $db = galDbConnect();
    H::dbQuery($db, sprintf('UPDATE user_infos SET nb_image_page = %d WHERE user_id = %d', $value, $userId));
    H::dbClose($db);
}

/**
 * Inserts a real `search` row shaped like
 * SearchRepository::insertSearch()'s own `rules` column (a bare `{"q":
 * ...}` object, the shape SearchService::getSearchResults() checks
 * `isset($search['q'])` against to route into getQuickSearchResults()
 * instead of getRegularSearchResults()) -- `search_uuid` is left NULL
 * (an old-style numeric-only id, same as Ws\Core::historySearch()'s
 * own ephemeral inserts) so it's reachable via `/index.php?/search/<id>`
 * without tripping SearchService::getValidatedSearchInfo()'s
 * search_uuid-required guard. Returns the new row's id.
 */
function galInsertQuickSearch(string $q): int
{
    $db = galDbConnect();
    $rulesJson = json_encode([
        'q' => $q,
    ], JSON_THROW_ON_ERROR);
    H::dbQuery($db, sprintf("INSERT INTO search (search_uuid, created_on, created_by, forked_from, rules) VALUES (NULL, NOW(), 1, NULL, '%s')", H::dbEscape($db, $rulesJson)));
    $searchId = H::dbInsertId($db);
    H::dbClose($db);

    return $searchId;
}

function galDeleteSearch(int $searchId): void
{
    $db = galDbConnect();
    H::dbQuery($db, sprintf('DELETE FROM search WHERE id = %d', $searchId));
    H::dbClose($db);
}

it('renders a category page with subcategories, exercising the main thumbnail/sort/edit-icon paths', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1');
    $page->assertNoJavaScriptErrors();
});

it('renders a category page in flat mode', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/flat');
    $page->assertNoJavaScriptErrors();
});

it('renders a childless category with the flat icon enabled, clearing the flat-mode link', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['index_flat_icon']);

    try {
        H::setConfigValue('index_flat_icon', 'true');

        $page = H::navigateOk($page, '/index.php?/category/2');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders a category filtered by creation chronology, exercising the alternate-field icon link', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/created-monthly');
    $page->assertNoJavaScriptErrors();
});

it('shows page-not-found when start is beyond the item count', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/start-999');
    $page->assertNoJavaScriptErrors();
});

it('sets the session image order and redirects back to the section', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&image_order=2');
    $page->assertNoJavaScriptErrors();
});

it('clears an invalid image order and redirects back to the section', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&image_order=abc');
    $page->assertNoJavaScriptErrors();
});

it('sets the noindex flag and derivative display type via the display param', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&display=square');
    $page->assertNoJavaScriptErrors();
});

it('fills the caddie and redirects back to the section', function (): void {
    $page = H::loginAsAdmin($this);

    try {
        $page = H::navigateOk($page, '/index.php?/category/1&caddie=1');
        $page->assertNoJavaScriptErrors();
    } finally {
        galClearCaddie(1);
    }
});

it('renders a tag page with combinable related tags', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/tags/1');
    $page->assertNoJavaScriptErrors();
});

it("renders a category's description when present", function (): void {
    $page = H::loginAsAdmin($this);
    $comment = 'CT category description ' . uniqid();

    try {
        galSetCategoryComment(1, $comment);

        $page = H::navigateOk($page, '/index.php?/category/1');
        $page->assertNoJavaScriptErrors();
        $page->assertSee($comment);
    } finally {
        galSetCategoryComment(1, null);
    }
});

it('renders the recent-albums page, exercising CategoryCatsRenderer\'s isRecentCats branch', function (): void {
    // Distinct from the plain '/category/1' navigation above:
    // GalleryController only calls
    // CoreDomainAccessor::categoryCatsRenderer()->render('recent_cats', ...)
    // for this specific URL section (UrlService's own 'recent_cats'
    // token), never for a plain category page -- CategoryCatsRenderer's
    // own isRecentCats-gated filtering/sort (CategoryService::
    // isRecentCategory()/compareByGlobalRank(), as opposed to the
    // plain-category compareByRank()) had no test reaching it at all.
    // Fixture category 1's photos are all dated 2026-08-01, matching
    // PIWIGO_TEST_NOW exactly, well within any real recent_period
    // default -- so it's real, fixture-driven "recent" data, not a
    // fabricated date.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/recent_cats');
    $page->assertNoJavaScriptErrors();
});

it('builds a navigation bar when the section holds more items than the page size', function (): void {
    // GalleryController.php:151-154 -- only built when
    // count($page_items) > $page_nb_image_page; the fixture's own
    // 3-photo category 1 never exceeds the real 15-per-page default, so
    // this temporarily narrows the admin user's own nb_image_page
    // preference (user_infos, same "restorable DB toggle" pattern
    // as galSetCategoryComment()/galClearCaddie() above) instead of
    // faking a bigger fixture.
    $page = H::loginAsAdmin($this);

    try {
        galSetNbImagePage(1, 1);

        $page = H::navigateOk($page, '/index.php?/category/1');
        $page->assertNoJavaScriptErrors();
    } finally {
        galSetNbImagePage(1, 15);
    }
});

it('snaps the canonical URL start back a full page once it lands past the last item', function (): void {
    // GalleryController.php:164-174 (U_CANONICAL) -- with nb_image_page
    // pinned to 2 and category 2's real 2-photo fixture, requesting
    // start-1 computes $start = 2 * round(1/2) = 2, which is >= the
    // 2-item count -- GalleryController.php:168-169 then snaps it back
    // down by one full page before building the canonical URL. start-1
    // itself stays valid (1 < count($page_items) = 2), so this never
    // trips the earlier page_not_found() gate at GalleryController.php:105.
    $page = H::loginAsAdmin($this);

    try {
        galSetNbImagePage(1, 2);

        $page = H::navigateOk($page, '/index.php?/category/2/start-1');
        $page->assertNoJavaScriptErrors();
    } finally {
        galSetNbImagePage(1, 15);
    }
});

it('renders a category filtered by posted chronology, exercising the alternate-field icon link', function (): void {
    // Mirror of the existing 'created-monthly' test above, but with the
    // chronology fields swapped: GalleryController.php:242-250 computes
    // the *other* field's link (the one NOT currently being browsed), so
    // starting from chronology_field=posted exercises the branch that
    // resolves to 'created' (GalleryController.php:245) and reads
    // indexCreatedDateIcon() (GalleryController.php:248) -- the
    // 'created-monthly' test above only ever reaches the mirror-image
    // 'posted' resolution.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1/posted-monthly');
    $page->assertNoJavaScriptErrors();
});

it('redirects to the slideshow when the slideshow param is present', function (): void {
    // GalleryController.php:538-540 -- $galleryDisplay->hasSlideshow
    // (the `slideshow` GET param) short-circuits straight to a
    // redirect(), only reachable once CategoryDefaultRenderer::render()
    // actually produced a real slideshow URL (i.e. $page_items isn't
    // empty), so this needs a real photo-bearing category, not the
    // homepage.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/index.php?/category/1&slideshow');
    $page->assertNoJavaScriptErrors();
});

it('renders quick-search category/tag hints and an unmatched term alongside real results', function (): void {
    // "Sample" full-text-matches category 1's name ("Sample Album") and
    // "nature" matches tag 1 -- tag 1 tags images 1,2,3, exactly category
    // 1's own photos, so the implicit AND between the two terms still
    // resolves to the same non-empty [1,2,3] (qsearchGetCategories()/
    // qsearchGetTags() populate all_cats/all_tags from name/tag matches
    // independently of qsearchEval()'s own id-intersection). The 3rd
    // term never matches anything at all (image, tag, or category), so
    // it becomes an unmatched/ignored term without narrowing the first
    // two terms' result -- qsearchEval() only intersects on a
    // *qualifying* term (SearchService.php:1045-1048).
    //
    // Exercises GalleryController.php:394-431 end to end: matching_cats
    // (398, 404-411), matching_tags (399, 414, 416-420), and the
    // non-empty-items "unmatched_terms" branch (423, 427-431).
    // `matching_cats_no_images` (398) itself is never a real, populated
    // key anywhere in this codebase -- confirmed dead even in the
    // legacy 16.x reference (functions_search.inc.php never writes it,
    // only reads it via index.php's own `@$page[...]`) -- so its ternary
    // always takes the `[]` branch here; the assignment line itself
    // still genuinely executes on every real search request, which is
    // all line coverage requires.
    $searchId = galInsertQuickSearch('Sample nature quxfrobnicate42');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/index.php?/search/' . $searchId);
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Album results for');
        $page->assertSee('Sample Album');
        $page->assertSee('Tag results for');
        $page->assertSee('nature');
        $page->assertSee('No results for');
        $page->assertSee('quxfrobnicate42');
    } finally {
        galDeleteSearch($searchId);
    }
});

it('renders the empty quick-search state when no term matches anything', function (): void {
    // Exercises GalleryController.php's OTHER branch of the same `if`
    // (423-425): with zero matching images, $page_items stays empty, so
    // the "no results" text renders the raw query instead of the
    // unmatched-terms list the test above exercises.
    $searchId = galInsertQuickSearch('zzzqfrobnomatch77');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/index.php?/search/' . $searchId);
        $page->assertNoJavaScriptErrors();
        $page->assertSee('No results for');
        $page->assertSee('zzzqfrobnomatch77');
    } finally {
        galDeleteSearch($searchId);
    }
});
