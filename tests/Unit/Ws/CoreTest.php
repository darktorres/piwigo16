<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\WsError;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Core;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Core -- the `pwg.getVersion`/`pwg.getInfos`/
 * `pwg.getMissingDerivatives`/`pwg.session.*`/`pwg.getActivityList`/
 * `pwg.history.*`/etc WS methods. Resolved via `Kernel::container()->get()`
 * (same rationale as `UpdatesSubControllerTest.php`) -- 27 constructor
 * deps, none touched by the guard branches under test. No dedicated
 * Integration/Browser spec of its own.
 *
 * Covers: `getVersion()`'s trivial constant return; `getMissingDerivatives()`'s
 * pure "no requested type matches a real defined type" guard;
 * `sessionLogin()`/`sessionLogout()`'s shared "cannot use this method
 * with an api key" 401 guard (both are the very first check, before any
 * real `AuthService`/`accessControl` call); `getActivityList()`'s pure
 * "invalid date_min" guard.
 *
 * Every other method (`getInfos`/`getCacheSize`/`caddieAdd`/`ratesDelete`/
 * `sessionGetStatus`/`historyLog`/`historyGet`/`historySearch`) has no
 * CSRF/pure guard of its own reachable this cheaply -- confirmed by
 * reading each -- and is not attempted here.
 */
function pwgCoreTestSubject(): Core
{
    $ws = Kernel::container()->get(Core::class);
    if (! $ws instanceof Core) {
        throw new LogicException('Container returned an unexpected type for ' . Core::class);
    }

    return $ws;
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * -- a bare, unregistered Server only needs to satisfy the by-ref
 * type, same rationale as `PermissionsTest.php`'s own
 * pwgPermissionsTestServer() helper.
 */
function pwgCoreTestServer(): Server
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

test('getVersion returns the real app version constant', function (): void {
    $ws = pwgCoreTestSubject();
    $server = pwgCoreTestServer();

    $result = $ws->getVersion([], $server);

    expect($result)
        ->toBe(AppInfo::VERSION);
});

test('getMissingDerivatives rejects a types list with no real defined type match', function (): void {
    $ws = pwgCoreTestSubject();
    $server = pwgCoreTestServer();

    $result = $ws->getMissingDerivatives([
        'types' => ['not-a-real-type'],
        'ids' => [],
        'max_urls' => 200,
        'prev_page' => null,
        'f_min_rate' => null,
        'f_max_rate' => null,
        'f_min_hit' => null,
        'f_max_hit' => null,
        'f_min_ratio' => null,
        'f_max_ratio' => null,
        'f_max_level' => null,
        'f_min_date_available' => null,
        'f_max_date_available' => null,
        'f_min_date_created' => null,
        'f_max_date_created' => null,
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($result->message())
            ->toBe('Invalid types');
    }
});

test('sessionLogin returns a 401 WsErrorResponse when called with an active API key', function (): void {
    $ws = pwgCoreTestSubject();
    $server = pwgCoreTestServer();
    $apiKeyRequestFlag = Kernel::container()->get(ApiKeyRequestFlag::class);
    if (! $apiKeyRequestFlag instanceof ApiKeyRequestFlag) {
        throw new LogicException('Container returned an unexpected type for ' . ApiKeyRequestFlag::class);
    }
    $apiKeyRequestFlag->activate();

    $result = $ws->sessionLogin([
        'username' => 'someone',
        'password' => 'anything',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Cannot use this method with an api key');
    }
});

test('sessionLogout returns a 401 WsErrorResponse when called with an active API key', function (): void {
    $ws = pwgCoreTestSubject();
    $server = pwgCoreTestServer();
    $apiKeyRequestFlag = Kernel::container()->get(ApiKeyRequestFlag::class);
    if (! $apiKeyRequestFlag instanceof ApiKeyRequestFlag) {
        throw new LogicException('Container returned an unexpected type for ' . ApiKeyRequestFlag::class);
    }
    $apiKeyRequestFlag->activate();

    $result = $ws->sessionLogout([], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Cannot use this method with an api key');
    }
});

test('getActivityList rejects an unparsable date_min', function (): void {
    $ws = pwgCoreTestSubject();
    $server = pwgCoreTestServer();

    $result = $ws->getActivityList([
        'page' => null,
        'offset' => 0,
        'uid' => null,
        'date_min' => 'not-a-real-date',
        'date_max' => null,
        'id' => null,
        'object' => null,
        'action' => null,
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($result->message())
            ->toBe('Invalid date_min');
    }
});
