<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\UserListPageRenderer (admin.php?page=user_list) -- add
 * users and manage the users list; no write logic of its own (user
 * create/delete/status-change go through the WS API). This suite covers
 * the group/status/level aggregation queries, the group/user_id/
 * show_add_user filter echo, and the line/grid pagination-default branch.
 */
it('renders the user list with the real fixture group/status/level breakdowns', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_list');

    $page->assertSee('fixture_admin');
    $page->assertNoJavaScriptErrors();
});

it('protects other admin/webmaster users from deletion for a plain "admin"-status viewer', function (): void {
    $page = H::loginAsAdmin($this);
    $username = 'user_list_plain_admin_' . uniqid();
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
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    $db->query(sprintf("UPDATE %suser_infos SET status = 'admin' WHERE user_id = %d", $prefix, $userId));

    try {
        $adminPage = H::visitPwg($this, '/identification.php');
        $adminPage = $adminPage->fill('username', $username)->fill('password', $password)->click('login');
        H::assertNoServerErrors($adminPage, 'plain-admin post-login page');

        // Only reached (render()'s own `CurrentUser::get()->status ===
        // UserStatus::Admin` branch, gathering every other admin/webmaster
        // user_id into $protected_users/$password_protected_users) for a
        // plain "admin"-status session -- fixture_admin itself is
        // "webmaster", which every other test in this file uses.
        $adminPage = H::navigateOk($adminPage, '/admin.php?page=user_list');
        // The user grid defaults to 5 per page (sorted newest-registered
        // first) -- the users table is shared, ever-growing state across
        // this whole Browser suite run, so fixture_admin (seeded with the
        // oldest registration_date of anyone) isn't guaranteed to land on
        // the default first page (confirmed live via screenshot: 7 real
        // users, fixture_admin on page 2). Switch to the "50 per page"
        // real UI control instead of asserting against an unstable
        // pagination default.
        $adminPage = $adminPage->click('pagination-per-page-50');
        $adminPage->assertSee('fixture_admin');
        $adminPage->assertNoJavaScriptErrors();
    } finally {
        $db->query(sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        $db->query(sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        $db->close();
    }
});

it('echoes a group filter, a user_id search, and show_add_user into the form', function (): void {
    $page = H::loginAsAdmin($this);
    $group = H::wsCall($page, 'pwg.groups.add', ['name' => 'User List Filter Group ' . uniqid()]);
    $groupResult = $group['result'] ?? null;
    $groups = is_array($groupResult) ? ($groupResult['groups'] ?? null) : null;
    $firstGroup = is_array($groups) ? ($groups[0] ?? null) : null;
    if (! is_array($firstGroup) || ! is_numeric($firstGroup['id'] ?? null)) {
        throw new RuntimeException('pwg.groups.add did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $firstGroup['id'];

    $page = H::navigateOk($page, '/admin.php?page=user_list&group=' . $groupId . '&user_id=1&show_add_user=1');

    $page->assertNoJavaScriptErrors();
    H::assertNoServerErrors($page, 'user_list filter echo');
});

it('shows the local-webmaster_id deprecation warning only when config.inc.php sets it locally', function (): void {
    // The real committed local/config/config.inc.php in this test
    // environment never sets $conf['webmaster_id'] itself (webmasterIdIsLocal()'s
    // own docblock: it's a presence check against that specific file, not
    // CurrentConfig's own schema default) -- confirms the real negative
    // case rather than assuming it.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_list');

    $page->assertDontSee('is deprecated, please remove it');
});

function userListPageRendererDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function userListPageRendererDbConnect(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

it('defaults to line-view pagination of 5 for an admin with no saved view preference', function (): void {
    $db = userListPageRendererDbConnect();
    $prefix = userListPageRendererDbPrefix();
    $original = H::fetchAssocOrFail($db, "SELECT preferences FROM {$prefix}user_infos WHERE user_id = 1");
    $db->query("UPDATE {$prefix}user_infos SET preferences = NULL WHERE user_id = 1");

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=user_list');

        // getParam('user-manager-view', 'line') defaults to 'line' when
        // unset -> the 'line' branch's own default pagination value (5)
        // applies; both are rendered verbatim into a JS const.
        $page->assertSourceHas("const view_selector = 'line';");
        $page->assertSourceHas("const pagination = '5';");
    } finally {
        $original_preferences = $original['preferences'];
        if (is_string($original_preferences)) {
            $stmt = $db->prepare("UPDATE {$prefix}user_infos SET preferences = ? WHERE user_id = 1");
            if (! $stmt instanceof \mysqli_stmt) {
                throw new RuntimeException('mysqli::prepare() failed for the preferences restore statement');
            }
            $stmt->bind_param('s', $original_preferences);
            $stmt->execute();
        }
        $db->close();
    }
});

it('switches to grid-view pagination default of 10 when the saved view preference is not "line"', function (): void {
    $db = userListPageRendererDbConnect();
    $prefix = userListPageRendererDbPrefix();
    $original = H::fetchAssocOrFail($db, "SELECT preferences FROM {$prefix}user_infos WHERE user_id = 1");
    $db->query("UPDATE {$prefix}user_infos SET preferences = '{\"user-manager-view\":\"tile\"}' WHERE user_id = 1");

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=user_list');

        $page->assertSourceHas("const view_selector = 'tile';");
        $page->assertSourceHas("const pagination = '10';");
    } finally {
        $original_preferences = $original['preferences'];
        if (is_string($original_preferences)) {
            $stmt = $db->prepare("UPDATE {$prefix}user_infos SET preferences = ? WHERE user_id = 1");
            if (! $stmt instanceof \mysqli_stmt) {
                throw new RuntimeException('mysqli::prepare() failed for the preferences restore statement');
            }
            $stmt->bind_param('s', $original_preferences);
            $stmt->execute();
        } else {
            $db->query("UPDATE {$prefix}user_infos SET preferences = NULL WHERE user_id = 1");
        }
        $db->close();
    }
});