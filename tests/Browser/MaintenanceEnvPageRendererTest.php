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