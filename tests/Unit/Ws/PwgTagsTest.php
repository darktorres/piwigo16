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
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\PwgTags;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\PwgTags -- the `pwg.tags.*` WS methods (8 registrations).
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) since `TagService` alone has several
 * further constructor deps none of the branches under test touch. No
 * dedicated Integration/Browser spec of its own.
 *
 * `delete()`/`rename()`/`duplicate()`/`merge()` all check
 * `CsrfService::getToken() !== $params['pwg_token']` as their very
 * first statement, before touching `tagService` at all -- a wrong
 * token is therefore a cheap, DB-free 403 branch, same established
 * pattern as `PwgPermissionsTest.php`/`PwgGroupsTest.php`. `getList()`/
 * `getAdminList()`/`getImages()`/`add()` have no CSRF check of their
 * own and go straight into real `tagService` reads/writes, so are not
 * attempted here.
 */
function pwgTagsTestSubject(): PwgTags
{
    $ws = Kernel::container()->get(PwgTags::class);
    if (! $ws instanceof PwgTags) {
        throw new LogicException('Container returned an unexpected type for ' . PwgTags::class);
    }

    return $ws;
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * -- a bare, unregistered Server only needs to satisfy the by-ref
 * type, same rationale as `PwgPermissionsTest.php`'s own
 * pwgPermissionsTestServer() helper.
 */
function pwgTagsTestServer(): Server
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

test('delete returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgTagsTestSubject();
    $server = pwgTagsTestServer();

    $result = $ws->delete([
        'tag_id' => [1],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('rename returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgTagsTestSubject();
    $server = pwgTagsTestServer();

    $result = $ws->rename([
        'tag_id' => 1,
        'new_name' => 'new',
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
    $ws = pwgTagsTestSubject();
    $server = pwgTagsTestServer();

    $result = $ws->duplicate([
        'tag_id' => 1,
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

test('merge returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgTagsTestSubject();
    $server = pwgTagsTestServer();

    $result = $ws->merge([
        'destination_tag_id' => 1,
        'merge_tag_id' => [2],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
