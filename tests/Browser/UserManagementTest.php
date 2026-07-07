<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

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
    $userId = (int) ($create['result']['users'][0]['id'] ?? $create['result']['id'] ?? 0);
    expect($userId)->toBeGreaterThan(0);

    $list = H::wsCall($page, 'pwg.users.getList');
    expect($list['stat'])->toBe('ok');
    $usernames = array_column($list['result']['users'], 'username');
    expect($usernames)->toContain($username);

    $delete = H::wsCall($page, 'pwg.users.delete', ['user_id' => $userId, 'pwg_token' => $pwgToken]);
    expect($delete['stat'])->toBe('ok');

    $afterDelete = H::wsCall($page, 'pwg.users.getList');
    $afterUsernames = array_column($afterDelete['result']['users'], 'username');
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
