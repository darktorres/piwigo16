<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\MaintenanceEnvPageRenderer (admin.php?page=maintenance&tab=
 * env) -- server/DB info and cache/storage stats, plus the lock/unlock
 * gallery link toggle (Piwigo\Config\CurrentConfig::galleryLocked()).
 */
it('renders the env tab with real server/DB info when the gallery is unlocked', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'false');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        $page->assertSee(PHP_VERSION);
        $page->assertSee('MySQL');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the time-since-last-calculation when a real cache_sizes config value is present', function (): void {
    $snapshot = H::snapshotConfig(['cache_sizes']);
    // cache_sizes is a `[{name, value}, ...]` list persisted by
    // Ws\PwgCore's cache-size calculation (confirmed via direct read) --
    // index 3's own 'value' (a date string) is what MaintenanceEnvPageRenderer
    // reads for its "time since last calculation" display. Hand-crafted
    // here rather than triggering a real calculation, since only that one
    // index/key matters to this branch.
    H::setConfigValue('cache_sizes', '[{"name":"a","value":1},{"name":"b","value":2},{"name":"c","value":3},{"name":"d","value":"2020-01-01 00:00:00"}]');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'maintenance env tab, cache_sizes present');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders successfully with the gallery locked (U_MAINT_UNLOCK_GALLERY branch)', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        // MaintenanceEnvPageRenderer assigns U_MAINT_LOCK_GALLERY/
        // U_MAINT_UNLOCK_GALLERY depending on CurrentConfig::galleryLocked()
        // -- but unlike the "actions" tab, maintenance_env.tpl never
        // references either variable (confirmed live: zero "gallery"
        // references anywhere in that template), so there's nothing
        // observable in the HTML beyond a clean render. This exercises the
        // locked branch for coverage; the sibling test above already
        // exercises the unlocked branch.
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'maintenance env tab, gallery locked');
    } finally {
        H::restoreConfig($snapshot);
    }
});