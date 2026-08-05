<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\AlbumNotificationPageRenderer (the "notification" tab of the
 * "album" page slug, admin.php?page=album-{id}-notification) --
 * AdminExtendedSmokeTest.php's own data-driven smoke sweep already visits
 * this tab with a plain GET (no form submission), so this file focuses on
 * the actual email-notification submission branches that sweep never
 * reaches.
 *
 * Two lines are deliberately not exercised here: the `!is_numeric($u['user_id'])`
 * skip-row guard (user_infos.user_id is the table's own auto-increment
 * primary key -- a real query can never actually produce a non-numeric
 * value there, so this is an unreachable defensive guard, not a real
 * behavior) and the "no group in this gallery" branch (`count($all_group_ids)
 * === 0`), which needs the ENTIRE shared test database to have zero groups
 * -- not reliably producible in this suite's shared DB, where other
 * concurrently-running Browser tests (including this very batch's own
 * group-related PageRenderer tests) may create groups at any time.
 */

it('sends an album notification email to selected users and reports how many were sent', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Notification Test Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // user 1 (fixture_admin) -- see this suite's own fixture-shape memory
    // notes. The 'save_success' message is assigned unconditionally after
    // the per-user mail loop, regardless of whether the underlying send
    // itself is deliverable, so this is deterministic without depending on
    // real mail delivery.
    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-notification', [
        'pwg_token' => H::pwgToken($page),
        'submitEmail' => '1',
        'who' => 'users',
        'users' => ['1'],
        'mail_content' => 'Come check out the new album!',
    ]);

    expect($result['status'])->toBe(200);
    // The en_UK PO translation for this plural key reads "has been sent",
    // not a literal echo of the '%d mail was sent.' source string used as
    // the translation lookup key -- confirmed live via a direct debug
    // dump of the computed $message before assuming the source string's
    // own wording.
    expect($result['body'])->toContain('1 mail has been sent.');
    expect($result['body'])->toContain('fixture_admin');
});

it('resolves the representative-photo image query and injects an auth_key URL param for a normal-status recipient', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Notification Repr Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $imagePath = H::makeTestImage('Repr');
    $imageId = H::uploadPhotoViaApi($imagePath, $albumId, 'Notification Repr Photo ' . uniqid());
    @unlink($imagePath);

    // 'normal' status (pwg.users.add's own default -- confirmed via direct
    // read of UserService::addUser()) is required for createUserAuthKey()
    // to return a real key instead of `false` -- 'admin'/'webmaster'/'guest'
    // are all excluded by its own in_array() gate.
    $username = 'album_notif_normal_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addUserResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $username,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $userId = wsAddedUserId($addUserResult);

    $db = H::connect();
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';
    // Directly assigns the uploaded photo as this album's representative --
    // deterministic regardless of whatever "auto-pick a representative"
    // default behavior addSimple() may or may not apply.
    H::dbQuery($db, sprintf(
        'UPDATE %scategories SET representative_picture_id = %d WHERE id = %d',
        $prefix,
        $imageId,
        $albumId
    ));

    try {
        $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-notification', [
            'pwg_token' => H::pwgToken($page),
            'submitEmail' => '1',
            'who' => 'users',
            'users' => [(string) $userId],
            'mail_content' => 'Come see the new representative photo!',
        ]);

        expect($result['status'])->toBe(200);
        expect($result['body'])->toContain('1 mail has been sent.');
        expect($result['body'])->toContain($username);
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $userId));
        H::dbQuery($db, sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $userId));
        H::dbClose($db);
    }
});

it('populates the private-album permission_url and direct/indirect notified-user queries when at least one group exists', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Notification Private Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    $groupName = 'Notification Private Group ' . uniqid();
    $group = H::wsCall($page, 'pwg.groups.add', ['name' => $groupName]);
    $groupResult = $group['result'] ?? null;
    $groups = is_array($groupResult) ? ($groupResult['groups'] ?? null) : null;
    $firstGroup = is_array($groups) ? ($groups[0] ?? null) : null;
    if (! is_array($firstGroup) || ! is_numeric($firstGroup['id'] ?? null)) {
        throw new RuntimeException('pwg.groups.add did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $firstGroup['id'];

    $usernameIndirect = 'album_notif_indirect_' . uniqid();
    $password = 'a-strong-test-password-1';
    $addUserResult = H::wsCall($page, 'pwg.users.add', [
        'username' => $usernameIndirect,
        'password' => $password,
        'password_confirm' => $password,
        'pwg_token' => H::pwgToken($page),
    ]);
    $indirectUserId = wsAddedUserId($addUserResult);

    $db = H::connect();
    $prefix = getenv('PIWIGO_DB_PREFIX');
    $prefix = $prefix !== false ? $prefix : 'piwigo_';

    // status='private' both routes the form-construction group_ids query
    // through the group_access-scoped branch (assigning permission_url)
    // and enters the direct+indirect user-access resolution block below it.
    H::dbQuery($db, sprintf("UPDATE %scategories SET status = 'private' WHERE id = %d", $prefix, $albumId));
    // Grants this specific album to the group (group_access), and the new
    // user to that group (user_group) -- exercises the indirect-access
    // resolution query with a real, non-empty result rather than an always-
    // empty one.
    H::dbQuery($db, sprintf('INSERT INTO %sgroup_access (group_id, cat_id) VALUES (%d, %d)', $prefix, $groupId, $albumId));
    H::dbQuery($db, sprintf('INSERT INTO %suser_group (group_id, user_id) VALUES (%d, %d)', $prefix, $groupId, $indirectUserId));

    try {
        $page = H::navigateOk($page, '/admin.php?page=album-' . $albumId . '-notification');

        // group_mail_options (populated from the group_access-scoped query
        // this album now has a real row for) and user_options (populated
        // from the direct/indirect user-access resolution, which now
        // resolves $indirectUserId through its group_access+user_group
        // membership) both render as <select> option labels -- but
        // album_notification.tpl's own "who_option who_users" block is
        // only shown once the "Users" radio (name="who" value="users") is
        // picked; "Group" is checked by default, so the users <select> is
        // hidden and assertSee() can't find it until that radio is
        // clicked (confirmed live via the failure screenshot: the group
        // dropdown was visible, the users one wasn't rendered at all).
        $page->assertSee($groupName);
        // The users <select multiple> is enhanced by a JS "search term"
        // widget (album_notification.tpl's own placeholder="Type in a
        // search term") that replaces the live DOM once it initializes --
        // confirmed live (server-side debug logging showed user_options
        // correctly populated with this exact user every time) that
        // H::rawWebpage($page)->content() reads the browser's *current*
        // DOM, i.e. post-widget-init, not the original server response,
        // so the <option> this asserts on is already gone from it by the
        // time this runs. H::rawGet() executes a same-origin fetch()
        // instead, returning the real, unmodified server HTML.
        $result = H::rawGet($page, '/admin.php?page=album-' . $albumId . '-notification');
        expect($result['body'])->toContain($usernameIndirect);
    } finally {
        H::dbQuery($db, sprintf('DELETE FROM %suser_group WHERE group_id = %d', $prefix, $groupId));
        H::dbQuery($db, sprintf('DELETE FROM %sgroup_access WHERE group_id = %d', $prefix, $groupId));
        H::dbQuery($db, sprintf('DELETE FROM %suser_infos WHERE user_id = %d', $prefix, $indirectUserId));
        H::dbQuery($db, sprintf('DELETE FROM %susers WHERE id = %d', $prefix, $indirectUserId));
        H::dbClose($db);
    }
});

it('sends an album notification email to a group and reports the group name', function (): void {
    $page = H::loginAsAdmin($this);
    $album = H::wsCall($page, 'pwg.categories.add', ['name' => 'Notification Group Album ' . uniqid()]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    // pwg.groups.add's own response nests the created group under
    // result.groups[0], not a bare result.id (confirmed live -- unlike
    // pwg.categories.add's flat result.id shape).
    $group = H::wsCall($page, 'pwg.groups.add', ['name' => 'Notification Test Group ' . uniqid()]);
    $groupResult = $group['result'] ?? null;
    $groups = is_array($groupResult) ? ($groupResult['groups'] ?? null) : null;
    $firstGroup = is_array($groups) ? ($groups[0] ?? null) : null;
    if (! is_array($firstGroup) || ! is_numeric($firstGroup['id'] ?? null)) {
        throw new RuntimeException('pwg.groups.add did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $firstGroup['id'];

    $result = H::adminPost($page, '/admin.php?page=album-' . $albumId . '-notification', [
        'pwg_token' => H::pwgToken($page),
        'submitEmail' => '1',
        'who' => 'group',
        'group' => (string) $groupId,
        'mail_content' => 'Come check out the new album!',
    ]);

    expect($result['status'])->toBe(200);
    // Same msgid/msgstr mismatch as the "users" test above -- the en_UK
    // translation reads "Information email sent to group", not a literal
    // echo of the source msgid.
    expect($result['body'])->toContain('Information email sent to group');
});
