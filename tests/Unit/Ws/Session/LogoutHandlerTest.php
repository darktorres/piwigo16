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
use Piwigo\Ws\Server;
use Piwigo\Ws\Session\LogoutHandler;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Session\LogoutHandler -- `pwg.session.logout`. Resolved via
 * `Kernel::container()->get()`, same rationale as GetListHandlerTest.php
 * (Comments).
 *
 * Covers the shared "cannot use this method with an api key" 401 guard
 * -- the very first check, before any real AuthService/accessControl call.
 */
function pwgSessionLogoutHandlerTestSubject(): LogoutHandler
{
    $handler = Kernel::container()->get(LogoutHandler::class);
    if (! $handler instanceof LogoutHandler) {
        throw new LogicException('Container returned an unexpected type for ' . LogoutHandler::class);
    }

    return $handler;
}

function pwgSessionLogoutHandlerTestServer(): Server
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

    return new Server(new EventDispatcher(), $accessControl, new ApiKeyRequestFlag(), $currentConfig, Kernel::container());
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('logout returns a 401 WsErrorResponse when called with an active API key', function (): void {
    $handler = pwgSessionLogoutHandlerTestSubject();
    $server = pwgSessionLogoutHandlerTestServer();
    $apiKeyRequestFlag = Kernel::container()->get(ApiKeyRequestFlag::class);
    if (! $apiKeyRequestFlag instanceof ApiKeyRequestFlag) {
        throw new LogicException('Container returned an unexpected type for ' . ApiKeyRequestFlag::class);
    }
    $apiKeyRequestFlag->activate();

    $result = $handler([], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(401)
            ->and($result->message())
            ->toBe('Cannot use this method with an api key');
    }
});
