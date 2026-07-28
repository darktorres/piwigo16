<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\IntroSubController (admin.php, page slug
 * "intro" -- the admin dashboard: pending-comments/orphans/locked-album
 * warnings, the activity chart, and the storage chart). Already ~65%
 * covered incidentally (every admin-page test lands here by default), so
 * this file targets specifically what a bare visit never exercises: the
 * newsletter-hide action, and the orphan-photo/locked-album warning
 * banners (neither of which the fixture triggers by default -- category
 * 1/2 are both visible, and all 5 fixture photos are linked to a
 * category).
 *
 * Deliberately skips: the newsletter-promotion panel (needs an account
 * 2+ weeks old with 3+ albums and 30+ photos -- unrealistic to fabricate
 * for this fixture), and the "latest Piwigo news" panel
 * (`show_piwigo_latest_news`, fixture default false) -- enabling it would
 * make `getLatestNews()` perform a real outbound HTTP request to
 * piwigo.org, which has no place in a deterministic test.
 */
function introDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function introDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

/** Inserts an unlinked (orphan) image row and returns its id. */
function introInsertOrphanImage(): int
{
    $db = introDbConnect();
    $file = 'ct_orphan_' . uniqid() . '.jpg';
    $db->query(sprintf(
        "INSERT INTO %simages (file, path, hit, level) VALUES ('%s', '%s', 0, 0)",
        introDbPrefix(),
        $db->real_escape_string($file),
        $db->real_escape_string($file)
    ));
    $id = (int) $db->insert_id;
    $db->close();

    return $id;
}

function introDeleteImage(int $imageId): void
{
    $db = introDbConnect();
    $db->query(sprintf('DELETE FROM %simages WHERE id = %d', introDbPrefix(), $imageId));
    $db->close();
}

function introSetCategoryVisible(int $categoryId, bool $visible): void
{
    $db = introDbConnect();
    $db->query(sprintf(
        'UPDATE %scategories SET visible = %d WHERE id = %d',
        introDbPrefix(),
        $visible ? 1 : 0,
        $categoryId
    ));
    $db->close();
}

it('renders the admin dashboard', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php');
    $page->assertNoJavaScriptErrors();
});

it('renders the admin dashboard twice in the same session, exercising the activity-cache hit', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php');
    $page->assertNoJavaScriptErrors();
    $page = H::navigateOk($page, '/admin.php');
    $page->assertNoJavaScriptErrors();
});

it('hides the newsletter subscription banner via the action param', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::adminPost($page, '/admin.php?page=intro&action=hide_newsletter_subscription', []);

    expect($result['status'])->toBe(200);
});

it('shows an orphans warning when orphan photos exist', function (): void {
    // ImageService::countOrphans() memoizes its result into the
    // `count_orphans` config param and only recomputes while that value
    // is NULL (AdminShell.php's own "only calculate ... if not huge"
    // comment) -- global, persistent, shared-across-the-whole-suite state,
    // not a per-request count. Any earlier admin-page visit this run
    // already cached a (likely zero) value, so inserting a fresh orphan
    // row alone would be invisible until the cache is cleared.
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['count_orphans']);
    $imageId = introInsertOrphanImage();

    try {
        H::setConfigValue('count_orphans', null);

        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Orphans');
    } finally {
        introDeleteImage($imageId);
        H::restoreConfig($snapshot);
    }
});

it('shows a locked-album warning when an album is hidden', function (): void {
    $page = H::loginAsAdmin($this);
    introSetCategoryVisible(2, false);

    try {
        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
        $page->assertSee('Locked album');
    } finally {
        introSetCategoryVisible(2, true);
    }
});

it('computes zero comments when comments are disabled', function (): void {
    $page = H::loginAsAdmin($this);
    $snapshot = H::snapshotConfig(['activate_comments']);

    try {
        H::setConfigValue('activate_comments', 'false');

        $page = H::navigateOk($page, '/admin.php');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});
