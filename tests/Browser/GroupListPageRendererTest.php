<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\GroupListPageRenderer (admin.php?page=group_list) -- a
 * read-only listing page. `?delete=`/`?toggle_is_default=` are documented,
 * confirmed-dead params (GroupListSubController's own docblock: group
 * create/delete/rename all go through `/api/v1/groups`, nothing in this page
 * ever acts on them) that STILL gate a real CSRF check
 * (GroupListActionRequest::requiresCsrfCheck), so this suite exercises
 * that CSRF gate directly rather than any actual mutation.
 */
it('renders the group list with real groups, member counts, and member names', function (): void {
    $page = H::loginAsAdmin($this);
    $group = H::createGroup($page, [
        'name' => 'Group List Test Group ' . uniqid(),
    ]);
    if (! is_numeric($group['id'] ?? null)) {
        throw new RuntimeException('createGroup did not return a numeric id: ' . var_export($group, true));
    }
    $groupId = (int) $group['id'];
    $groupName = is_string($group['name'] ?? null) ? $group['name'] : '';

    H::addGroupUser($page, [
        'group_id' => (string) $groupId,
        'user_id' => '1',
        'pwg_token' => H::pwgToken($page),
    ]);

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
