<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\MaintenanceActionsPageRenderer (admin.php?page=maintenance,
 * the default "actions" tab).
 */
function maintenanceActionsDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

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

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = maintenanceActionsDbPrefix();
    $db->query(sprintf("UPDATE %suser_infos SET status = 'admin' WHERE user_id = %d", $prefix, $userId));
    $db->close();

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        H::assertNoServerErrors($adminPage, 'plain-admin identification page');
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=maintenance');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        $db = new mysqli(
            (string) getenv('PIWIGO_DB_HOST'),
            (string) getenv('PIWIGO_DB_USER'),
            (string) getenv('PIWIGO_DB_PASSWORD'),
            (string) getenv('PIWIGO_DB_BASE')
        );
        $db->query(sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        $db->query(sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        $db->close();
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
