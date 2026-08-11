<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('renders the global gallery actions fieldset with no webmaster warning for the webmaster fixture user', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance');

    // PHP_VERSION/DB_VERSION are assigned by this class but only ever
    // rendered by maintenance_env.tpl (confirmed live: maintenance_actions.
    // tpl never references $PHP_VERSION/$DB_VERSION at all) -- the
    // "Global Gallery Actions" fieldset (gated behind isWebmaster==1) is
    // this tab's own real, distinctive content.
    $page->assertSee('Global Gallery Actions');
    $page->assertDontSee('status is required to edit parameters');
    $page->assertNoJavaScriptErrors();
});

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'maint_actions_admin_' . uniqid();
    $password = 'a-strong-test-password-1';

    $addResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = wsAddedUserId($addResult);

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE user_infos SET status = 'admin' WHERE user_id = %d", $userId));
    H::dbClose($db);

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        H::assertNoServerErrors($adminPage, 'plain-admin identification page');
        $adminPage = $adminPage->fill('username', $username)
            ->fill('password', $password)
            ->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=maintenance');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        $db = H::connect();
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});

it('shows the unlock-gallery link and hides lock-gallery when the gallery is locked', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'true');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance');

        $page->assertPresent('a[href*="action=unlock_gallery"]');
        $page->assertMissing('a[href*="action=lock_gallery"]');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the lock-gallery link and hides unlock-gallery when the gallery is unlocked', function (): void {
    $snapshot = H::snapshotConfig(['gallery_locked']);
    H::setConfigValue('gallery_locked', 'false');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance');

        $page->assertPresent('a[href*="action=lock_gallery"]');
        $page->assertMissing('a[href*="action=unlock_gallery"]');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders successfully forced onto the "imagick" graphics library branch', function (): void {
    $snapshot = H::snapshotConfig(['graphics_library']);
    // Default 'auto' resolves to 'ext_imagick' in this test env (both the
    // imagick PHP extension and the `convert`/`identify` CLI binaries are
    // present, confirmed live) -- forcing 'imagick' here exercises the
    // sibling PHP-extension-only branch instead.
    H::setConfigValue('graphics_library', '"imagick"');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'maintenance actions, graphics_library=imagick');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('renders successfully forced onto the "gd" graphics library branch', function (): void {
    $snapshot = H::snapshotConfig(['graphics_library']);
    H::setConfigValue('graphics_library', '"gd"');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'maintenance actions, graphics_library=gd');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the time-since-last-calculation-derived cache size info when a real cache_sizes config value is present', function (): void {
    $snapshot = H::snapshotConfig(['cache_sizes']);
    // Same hand-crafted shape as MaintenanceEnvPageRendererTest's own
    // equivalent test -- index 3's 'value' is what this class reads too.
    H::setConfigValue('cache_sizes', '[{"name":"a","value":1},{"name":"b","value":2},{"name":"c","value":3},{"name":"d","value":"2020-01-01 00:00:00"}]');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=maintenance');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'maintenance actions, cache_sizes present');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the empty-lounge link and counter when the upload lounge has real items', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => 'Maintenance Actions Lounge Album ' . uniqid(),
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];
    $image = H::makeTestImage(uniqid());
    $imageId = H::uploadPhotoViaApi($image, $albumId, 'Maintenance Actions Lounge Photo');
    @unlink($image);

    $db = H::connect();
    H::dbQuery($db, sprintf('INSERT INTO lounge (image_id, category_id) VALUES (%d, %d)', $imageId, $albumId));

    try {
        // countLoungeItems() counts every row in the whole table, shared
        // with every other concurrently-running Browser test in this
        // suite -- read the real total back rather than assuming this
        // insert is the only row, so this stays correct either way.
        $countAssoc = H::dbFetchAssoc($db, sprintf('SELECT COUNT(*) AS c FROM lounge'));
        $expectedCount = is_array($countAssoc) ? (int) $countAssoc['c'] : -1;
        expect($expectedCount)
            ->toBeGreaterThan(0);

        $page = H::navigateOk($page, '/admin.php?page=maintenance');

        $page->assertPresent('a[href*="action=empty_lounge"]');
        $page->assertSeeIn('a[href*="action=empty_lounge"] .multiple-pictures-sizes', (string) $expectedCount);
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM lounge WHERE image_id = %d AND category_id = %d', $imageId, $albumId));
        H::dbClose($db);
    }
});
