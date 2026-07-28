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

it('shows the unlock-gallery link instead of lock-gallery when the gallery is locked', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        $page->assertPresent('a[href*="action=unlock_gallery"]');
        $page->assertMissing('a[href*="action=lock_gallery"]');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the lock-gallery link instead of unlock-gallery when the gallery is unlocked', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'false');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

        $page->assertPresent('a[href*="action=lock_gallery"]');
        $page->assertMissing('a[href*="action=unlock_gallery"]');
    } finally {
        H::restoreConfig($snapshot);
    }
});