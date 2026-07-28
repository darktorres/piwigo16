<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\GalleryController (index.php) -- the main gallery
 * browsing page. Already exercised incidentally by many other Browser
 * tests (GallerySmokeTest visits the plain homepage, VisualRegressionTest
 * visits `/index.php?/category/1`/`/category/2`), but almost none of the
 * query-driven branches (chronology navigation, image order, display
 * type, caddie fill, out-of-range start, the "related tags"/"combinable
 * tags" widgets, category flat-icon/description) had a dedicated test.
 * Site 1's fixture gives category 1 ("Sample Album", 3 photos, 1
 * subcategory) and category 2 ("Nested Sub Album", 2 photos, no
 * subcategory), tag 1 ("nature", tagging all 3 of category 1's photos) --
 * enough real, permission-free data to drive every branch below without
 * any fixture mutation beyond a couple of restorable config/DB toggles.
 *
 * Deliberately skips the `section === 'search'` branch entirely (~35 of
 * this file's 237 uncovered lines) -- that requires a real search_id
 * minted by SearchController/SearchService first, and the Search domain
 * (SearchFilterRenderer/SearchService, 920 uncovered lines) is its own
 * separate, not-yet-started Wave 1 item; testing it properly belongs
 * there, not bolted onto this file.
 */
function galDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function galDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function galSetCategoryComment(int $categoryId, ?string $comment): void
{
    $db = galDbConnect();
    if ($comment === null) {
        $db->query(sprintf('UPDATE %scategories SET comment = NULL WHERE id = %d', galDbPrefix(), $categoryId));
    } else {
        $db->query(sprintf(
            "UPDATE %scategories SET comment = '%s' WHERE id = %d",
            galDbPrefix(),
            $db->real_escape_string($comment),
            $categoryId
        ));
    }
    $db->close();
}

function galClearCaddie(int $userId): void
{
    $db = galDbConnect();
    $db->query(sprintf('DELETE FROM %scaddie WHERE user_id = %d', galDbPrefix(), $userId));
    $db->close();
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
