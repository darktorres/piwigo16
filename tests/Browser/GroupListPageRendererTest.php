<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\GroupListPageRenderer (admin.php?page=group_list) -- a
 * read-only listing page. `?delete=`/`?toggle_is_default=` are documented,
 * confirmed-dead params (GroupListSubController's own docblock: group
 * create/delete/rename all go through the WS API, nothing in this page
 * ever acts on them) that STILL gate a real CSRF check
 * (GroupListActionRequest::requiresCsrfCheck), so this suite exercises
 * that CSRF gate directly rather than any actual mutation.
 */
it('renders the group list with real groups, member counts, and member names', function (): void {
    $page = H::loginAsAdmin($this);
    $group = H::wsCall($page, 'pwg.groups.add', [
        'name' => 'Group List Test Group ' . uniqid(),
    ]);
    $groupResult = $group['result'] ?? null;
    if (! is_array($groupResult) || ! is_numeric($groupResult['id'] ?? null)) {
        throw new RuntimeException('pwg.groups.add did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $groupResult['id'];
    $groupName = is_string($groupResult['name'] ?? null) ? $groupResult['name'] : '';

    $addUserResult = H::wsCall($page, 'pwg.groups.addUser', [
        'group_id' => (string) $groupId,
        'user_id' => '1',
        'pwg_token' => H::pwgToken($page),
    ]);
    expect($addUserResult['stat'] ?? null)->toBe('ok');

    $page = H::navigateOk($page, '/admin.php?page=group_list');

    $page->assertSee($groupName);
    $page->assertSee('fixture_admin');
    $page->assertNoJavaScriptErrors();
});

it('rejects a delete param without a CSRF token, even though the param itself is a documented no-op', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=group_list&delete=999999');

    expect($result['status'])->toBe(400);
});

it('accepts a delete param with a valid CSRF token and still renders normally (the param is a no-op)', function (): void {
    $page = H::loginAsAdmin($this);
    $token = H::pwgToken($page);

    $result = H::rawGet($page, '/admin.php?page=group_list&delete=999999&pwg_token=' . $token);

    expect($result['status'])->toBe(200);
    expect($result['body'])->not->toContain('Fatal error');
});

it('rejects a toggle_is_default param without a CSRF token', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=group_list&toggle_is_default=999999');

    expect($result['status'])->toBe(400);
});
