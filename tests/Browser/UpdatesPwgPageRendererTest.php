<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\UpdatesPwgPageRenderer (admin.php?page=updates, the default
 * "pwg" tab) -- already GET-tested by the extension-tabs smoke route.
 * CoreUpdateService::getPiwigoNewVersions()/upgradeTo() both talk to
 * piwigo.org over the network with no injectable seam (documented
 * limitation shared with PemCatalog/CoreUpdateService's own Unit tests),
 * and upgradeTo() is a REAL core-file download+extract over the live
 * application -- this suite deliberately never supplies a valid pwg_token
 * alongside a real step=2/3 + submit=1 POST, since that combination is
 * the actual trigger condition for that dangerous mutation. Only the
 * CSRF-rejection path (which returns before ever calling upgradeTo()) and
 * the enableCoreUpdate()/non-webmaster guards are exercised.
 */
it('shows a fatal error when core updates are disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_core_update']);
    H::setConfigValue('enable_core_update', 'false');

    try {
        $page = H::asAdmin($this);

        $result = H::rawGet($page, '/admin.php?page=updates');

        expect($result['body'])->toContain('Piwigo core update system is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects a step=2 upgrade submission without a valid CSRF token, never reaching upgradeTo()', function (): void {
    $snapshot = H::snapshotConfig(['enable_core_update']);
    H::setConfigValue('enable_core_update', 'true');

    try {
        $page = H::asAdmin($this);

        $result = H::adminPost($page, '/admin.php?page=updates&step=2', [
            'submit' => '1',
            'upgrade_to' => '99.0.0',
        ]);

        expect($result['status'])->toBe(400);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('rejects a step=3 upgrade submission without a valid CSRF token, never reaching upgradeTo()', function (): void {
    $snapshot = H::snapshotConfig(['enable_core_update']);
    H::setConfigValue('enable_core_update', 'true');

    try {
        $page = H::asAdmin($this);

        $result = H::adminPost($page, '/admin.php?page=updates&step=3', [
            'submit' => '1',
            'upgrade_to' => '99.0.0',
        ]);

        expect($result['status'])->toBe(400);
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('does not attempt an upgrade when step=2 is visited with no form submission', function (): void {
    $snapshot = H::snapshotConfig(['enable_core_update']);
    H::setConfigValue('enable_core_update', 'true');

    try {
        $page = H::asAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=updates&step=2');

        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'updates step=2 plain visit');
    } finally {
        H::restoreConfig($snapshot);
    }
});

it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $snapshot = H::snapshotConfig(['enable_core_update']);
    H::setConfigValue('enable_core_update', 'true');

    $page = H::asAdmin($this);
    $username = 'updates_pwg_admin_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addResult = H::createUser($page, [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = addedUserId($addResult);

    $db = H::connect();
    H::dbQuery($db, sprintf("UPDATE user_infos SET status = 'admin' WHERE user_id = %d", $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)
            ->fill('password', $password)
            ->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=updates');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
        H::restoreConfig($snapshot);
    }
});
