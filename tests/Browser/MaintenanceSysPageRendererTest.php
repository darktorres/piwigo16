<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\MaintenanceSysPageRenderer (admin.php?page=maintenance&tab=sys)
 * -- already GET-tested as webmaster by AdminExtendedSmokeTest; this file
 * adds the one branch that needs a plain "admin"-status session: the
 * webmaster-required warning in the `else` of its own is_webmaster() gate.
 */
it('shows the webmaster-required warning for a plain "admin"-status user', function (): void {
    $page = H::asAdmin($this);
    $username = 'maint_sys_admin_' . uniqid();
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

        $adminPage = H::navigateOk($adminPage, '/admin.php?page=maintenance&tab=sys');
        $adminPage->assertSee('status is required to edit parameters');
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});
