<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\WsError;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Groups;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Groups -- the `pwg.groups.*` WS methods (8 registrations).
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) since `GroupService` alone has 7
 * further constructor deps none of the branches under test touch. No
 * dedicated Integration/Browser spec of its own.
 *
 * `getList()` covers its own pure "invalid order" regex guard (no DB
 * access at all) plus a real DB read against a deliberately
 * non-existent `group_id` -- deterministic empty-result shape, no
 * shared fixture dependency, same B2-pattern real-DB approach as this
 * campaign's Repository/Service tests.
 *
 * Every other method (`add`/`delete`/`setInfo`/`addUser`/`merge`/
 * `duplicate`/`deleteUser`) checks `CsrfService::getToken() !==
 * $params['pwg_token']` as its very first statement (`add()` is the
 * one exception -- see below), before touching `groupService` at all --
 * a wrong token is therefore a cheap, DB-free 403 branch, same
 * established pattern as `PermissionsTest.php`. `add()` has no CSRF
 * check of its own (confirmed by reading its full body) and goes
 * straight into a real `GroupService::create()` write, so it is not
 * attempted here. The real group CRUD logic behind every CSRF guard
 * needs real group/user rows and is not attempted here either.
 */
function pwgGroupsTestSubject(): Groups
{
    $ws = Kernel::container()->get(Groups::class);
    if (! $ws instanceof Groups) {
        throw new LogicException('Container returned an unexpected type for ' . Groups::class);
    }

    return $ws;
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * -- a bare, unregistered Server only needs to satisfy the by-ref
 * type, same rationale as `PermissionsTest.php`'s own
 * pwgPermissionsTestServer() helper.
 */
function pwgGroupsTestServer(): Server
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
    $accessControl = new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );

    return new Server(new EventDispatcher(), $accessControl, new ApiKeyRequestFlag(), $currentConfig);
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('getList rejects a malformed order parameter', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->getList([
        'per_page' => 10,
        'page' => 0,
        'order' => '!!!not-a-real-order!!!',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($result->message())
            ->toBe('Invalid input parameter order');
    }
});

test('getList returns an empty groups list for a group_id with no real matches', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->getList([
        'group_id' => [999999],
        'per_page' => 10,
        'page' => 0,
        'order' => 'name',
    ], $server);

    expect($result)
        ->toBeArray();
    if (is_array($result)) {
        expect($result['groups']->content)->toBe([]);
    }
});

test('delete returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->delete([
        'group_id' => [1],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('setInfo returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->setInfo([
        'group_id' => 1,
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('addUser returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->addUser([
        'group_id' => 1,
        'user_id' => [1],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('merge returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->merge([
        'destination_group_id' => 1,
        'merge_group_id' => [2],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('duplicate returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->duplicate([
        'group_id' => 1,
        'copy_name' => 'copy',
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('deleteUser returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgGroupsTestSubject();
    $server = pwgGroupsTestServer();

    $result = $ws->deleteUser([
        'group_id' => 1,
        'user_id' => [1],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
