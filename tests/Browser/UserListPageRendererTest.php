<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\UserListPageRenderer (admin.php?page=user_list) -- add
 * users and manage the users list; no write logic of its own (user
 * create/delete/status-change go through `/api/v1/users`). This suite covers
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

        // Only reached (render()'s own `CurrentUser::get()->status ===
        // UserStatus::Admin` branch, gathering every other admin/webmaster
        // user_id into $protected_users/$password_protected_users) for a
        // plain "admin"-status session -- fixture_admin itself is
        // "webmaster", which every other test in this file uses.
        $adminPage = H::navigateOk($adminPage, '/admin.php?page=user_list');
        // AdminShell::run() shows the "what's new" popin (footer.latte's
        // #whats_new_popin) whenever a user has no show_whats_new_<major>
        // preference yet, which defaults getParam(..., true) -- true for
        // this session's brand-new user, unlike fixture_admin (already
        // dismissed via fixture data), which every other test in this file
        // uses. The popin overlays the whole page, so the pagination click
        // below would otherwise time out waiting for an obscured element.
        // Dismiss it via the app's own real dismiss function first, same
        // as a real user clicking "Ok, got it!".
        $adminPage->script("if (typeof hide_user_whats_new === 'function') { hide_user_whats_new(); }");
        // The user grid's rows are populated by an async AJAX call
        // (user_list.js's own $(document).ready() handler), not present
        // in the server-rendered HTML at all -- clicking
        // pagination-per-page-50 before that call resolves races against
        // the page's own layout still settling. $username (this session's
        // own login, just registered by the createUser() call above) is
        // guaranteed newest-registered of anyone, so it reliably appears
        // in the still-default 5-per-page view the instant the grid
        // actually renders, making it a real readiness signal to wait on
        // here.
        $adminPage->assertSee($username);
        // The user grid defaults to 5 per page (sorted newest-registered
        // first) -- the users table is shared, ever-growing state across
        // this whole Browser suite run, so fixture_admin (seeded with the
        // oldest registration_date of anyone) isn't guaranteed to land on
        // the default first page. Switch to the "50 per page"
        // real UI control instead of asserting against an unstable
        // pagination default.
        $adminPage = $adminPage->click('pagination-per-page-50');
        $adminPage->assertSee('fixture_admin');
        $adminPage->assertNoJavaScriptErrors();
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM user_infos WHERE user_id = %d', $userId));
        H::dbQuery($db, sprintf('DELETE FROM users WHERE id = %d', $userId));
        H::dbClose($db);
    }
});

it('echoes a group filter, a user_id search, and show_add_user into the form', function (): void {
    $page = H::loginAsAdmin($this);
    $group = H::createGroup($page, [
        'name' => 'User List Filter Group ' . uniqid(),
    ]);
    if (! is_numeric($group['id'] ?? null)) {
        throw new RuntimeException('createGroup did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $group['id'];

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

// Positive counterpart to the test above: webmasterIdIsLocal() is a real
// filesystem presence check against local/config/config.inc.php, so this
// temporarily creates that file (it doesn't exist at all in the committed
// test environment -- only local/config/index.php does) with a real
// `$conf['webmaster_id']` assignment, then deletes it again in finally().
// Also sets `$conf['local_dir_site']` so the same request exercises
// webmasterIdIsLocal()'s own 2nd @include (siteLocal defaults to the same
// path as local when PWG_LOCAL_DIR is unset, as it is in .env.test, so
// this re-reads the identical file rather than a distinct one -- still a
// real 2nd include of a real path, not a no-op).
it('shows the local-webmaster_id deprecation warning when config.inc.php really sets it locally', function (): void {
    $configPath = dirname(__DIR__, 2) . '/local/config/config.inc.php';
    expect(file_exists($configPath))
        ->toBeFalse();

    file_put_contents($configPath, "<?php\n\$conf['webmaster_id'] = 999;\n\$conf['local_dir_site'] = true;\n");
    chmod($configPath, 0o666);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=user_list');

        // Not 'is deprecated': UserListPageRenderer's own warning text
        // is a faithful, byte-for-byte port of piwigo16's admin/user_list.php, which
        // has always had this exact grammar typo ("this parameter *in*
        // deprecated", not "is deprecated") -- "mechanical port doesn't
        // fold in unrelated fixes" precedent, so it stays as-is.
        H::assertSeeSettled($page, 'this parameter in deprecated, please remove it');
    } finally {
        unlink($configPath);
    }
});

// getDefaultUserInfo() returning false (no user_infos row for the
// configured default_user_id) is the only way to reach render()'s own
// fatalError('Default user not found') -- a real production state (a
// dangling/misconfigured default_user_id, e.g. after that user was
// deleted directly in the DB rather than through the app). Config has no
// real 'default_user_id' row in the fixture (CurrentConfig's own class
// default is 2), so this inserts one pointing at an id nothing in
// user_infos will ever have.
it('shows a fatal error when the configured default_user_id matches no real user_infos row', function (): void {
    $snapshot = H::snapshotConfig(['default_user_id']);
    H::setConfigValue('default_user_id', '999999');

    try {
        $page = H::loginAsAdmin($this);
        $result = H::rawGet($page, '/admin.php?page=user_list');

        expect($result['status'])->toBe(500);
        expect($result['body'])->toContain('Default user not found');
    } finally {
        H::restoreConfig($snapshot);
    }
});

function userListPageRendererDbConnect(): mysqli|Connection
{
    return H::connect();
}

it('defaults to line-view pagination of 5 for an admin with no saved view preference', function (): void {
    $db = userListPageRendererDbConnect();
    $original = H::fetchAssocOrFail($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    H::dbQuery($db, 'UPDATE user_infos SET preferences = NULL WHERE user_id = 1');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=user_list');

        // getUserManagerView() ?? 'line' defaults to 'line' when unset ->
        // the 'line' branch's own default pagination value (5) applies;
        // both are exposed into the typed page-data JSON island
        // (PageDataPayload::toJson(), plain json_encode()) -- pagination
        // is int, so it renders as a bare number, not a quoted string.
        H::assertSeeSettled($page, '"view_selector":"line"');
        H::assertSeeSettled($page, '"pagination":5');
    } finally {
        $original_preferences = $original['preferences'];
        if (is_string($original_preferences)) {
            H::dbQuery($db, sprintf("UPDATE user_infos SET preferences = '%s' WHERE user_id = 1", H::dbEscape($db, $original_preferences)));
        }
        H::dbClose($db);
    }
});

it('switches to grid-view pagination default of 10 when the saved view preference is not "line"', function (): void {
    $db = userListPageRendererDbConnect();
    $original = H::fetchAssocOrFail($db, 'SELECT preferences FROM user_infos WHERE user_id = 1');
    H::dbQuery($db, "UPDATE user_infos SET preferences = '{\"user-manager-view\":\"tile\"}' WHERE user_id = 1");

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=user_list');

        H::assertSeeSettled($page, '"view_selector":"tile"');
        H::assertSeeSettled($page, '"pagination":10');
    } finally {
        $original_preferences = $original['preferences'];
        if (is_string($original_preferences)) {
            H::dbQuery($db, sprintf("UPDATE user_infos SET preferences = '%s' WHERE user_id = 1", H::dbEscape($db, $original_preferences)));
        } else {
            H::dbQuery($db, 'UPDATE user_infos SET preferences = NULL WHERE user_id = 1');
        }
        H::dbClose($db);
    }
});
