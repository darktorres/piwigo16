<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Narrows a `POST /api/v1/users` response
 * ({@see \Piwigo\Controller\Api\Users\UserCreateController}) to its `id`.
 *
 * @param  array<array-key, mixed>  $response
 */
function addedUserId(array $response): int
{
    $id = $response['id'] ?? null;
    if (is_numeric($id)) {
        return (int) $id;
    }

    throw new RuntimeException('createUser() did not return a numeric user id: ' . var_export($response, true));
}

/**
 * Narrows a `GET /api/v1/users` response to a list of row arrays, skipping
 * any entry that isn't itself an array (array_column needs array-shaped
 * rows, not scalars).
 *
 * @param  array<array-key, mixed>  $response
 * @return list<array<string, mixed>>
 */
function listedUsers(array $response): array
{
    $users = $response['users'] ?? null;
    if (! is_array($users)) {
        throw new RuntimeException('listUsers() response missing users: ' . var_export($response, true));
    }

    $out = [];
    foreach ($users as $user) {
        if (! is_array($user)) {
            continue;
        }

        // Rows are JSON objects keyed by field name (id, username, email,
        // status, ...), so decoding always yields string keys here.
        /** @var array<string, mixed> $user */
        $out[] = $user;
    }

    return $out;
}

it('creates and deletes a user', function (): void {
    $page = H::asAdmin($this);
    $pwgToken = H::pwgToken($page);

    $username = 'browser_test_user_' . uniqid();
    $create = H::createUser($page, [
        'username' => $username,
        'password' => 'SecurePass123!',
        'email' => $username . '@example.test',
        'pwg_token' => $pwgToken,
    ]);
    $userId = addedUserId($create);
    expect($userId)
        ->toBeGreaterThan(0);

    $list = H::listUsers($page);
    $usernames = array_column(listedUsers($list), 'username');
    expect($usernames)
        ->toContain($username);

    H::deleteUser($page, [
        'user_id' => $userId,
        'pwg_token' => $pwgToken,
    ]);

    $afterDelete = H::listUsers($page);
    $afterUsernames = array_column(listedUsers($afterDelete), 'username');
    expect($afterUsernames)
        ->not->toContain($username);
});

it('admin user list page loads without errors', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=user_list');
    $page->assertNoJavaScriptErrors();
});

it('admin group list page loads without errors', function (): void {
    $page = H::asAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=group_list');
    $page->assertNoJavaScriptErrors();
});
