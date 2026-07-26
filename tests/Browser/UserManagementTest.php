<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Narrows the `result.users[0].id` of a pwg.users.add WS response to an
 * int. PwgUsers::add() (src/Piwigo/Ws/PwgUsers.php) internally
 * re-invokes pwg.users.getList and returns its {users: [...]} shape
 * directly, so that's the real path; a flatter {id: ...} shape is also
 * tolerated defensively in case that internal call ever changes.
 *
 * @param  array<string, mixed>  $response
 */
function wsAddedUserId(array $response): int
{
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException('pwg.users.add response missing result: ' . var_export($response, true));
    }

    $users = $result['users'] ?? null;
    if (is_array($users) && is_array($users[0] ?? null) && is_numeric($users[0]['id'] ?? null)) {
        return (int) $users[0]['id'];
    }

    $id = $result['id'] ?? null;
    if (is_numeric($id)) {
        return (int) $id;
    }

    throw new RuntimeException('pwg.users.add did not return a numeric user id: ' . var_export($response, true));
}

/**
 * Narrows the `result.users` of a pwg.users.getList WS response to a list
 * of row arrays, skipping any entry that isn't itself an array
 * (array_column needs array-shaped rows, not scalars).
 *
 * @param  array<string, mixed>  $response
 * @return list<array<string, mixed>>
 */
function wsListedUsers(array $response): array
{
    $result = $response['result'] ?? null;
    if (!is_array($result)) {
        throw new RuntimeException('pwg.users.getList response missing result: ' . var_export($response, true));
    }

    $users = $result['users'] ?? null;
    if (!is_array($users)) {
        throw new RuntimeException('pwg.users.getList response missing users: ' . var_export($response, true));
    }

    $out = [];
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }

        // pwg.users.getList rows are JSON objects keyed by field name
        // (id, username, email, status, ...), so decoding always yields
        // string keys here.
        /** @var array<string, mixed> $user */
        $out[] = $user;
    }

    return $out;
}

it('creates and deletes a user', function (): void {
    $page = H::loginAsAdmin($this);
    $pwgToken = H::pwgToken($page);

    $username = 'browser_test_user_' . uniqid();
    $create = H::wsCall($page, 'pwg.users.add', [
        'username'  => $username,
        'password'  => 'SecurePass123!',
        'email'     => $username . '@example.test',
        'pwg_token' => $pwgToken,
    ]);
    expect($create['stat'])->toBe('ok');
    $userId = wsAddedUserId($create);
    expect($userId)->toBeGreaterThan(0);

    $list = H::wsCall($page, 'pwg.users.getList');
    expect($list['stat'])->toBe('ok');
    $usernames = array_column(wsListedUsers($list), 'username');
    expect($usernames)->toContain($username);

    $delete = H::wsCall($page, 'pwg.users.delete', ['user_id' => $userId, 'pwg_token' => $pwgToken]);
    expect($delete['stat'])->toBe('ok');

    $afterDelete = H::wsCall($page, 'pwg.users.getList');
    $afterUsernames = array_column(wsListedUsers($afterDelete), 'username');
    expect($afterUsernames)->not->toContain($username);
});

it('admin user list page loads without errors', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_list');
    $page->assertNoJavaScriptErrors();
});

it('admin group list page loads without errors', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=group_list');
    $page->assertNoJavaScriptErrors();
});
