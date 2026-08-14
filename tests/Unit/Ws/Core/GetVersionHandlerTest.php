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
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Core\GetVersionHandler;
use Piwigo\Ws\Server;

/**
 * Piwigo\Ws\Core\GetVersionHandler -- `pwg.getVersion`. Resolved via
 * `Kernel::container()->get()`, same rationale as GetListHandlerTest.php
 * (Comments). Covers the trivial constant return -- its only branch.
 */
function pwgCoreGetVersionHandlerTestSubject(): GetVersionHandler
{
    $handler = Kernel::container()->get(GetVersionHandler::class);
    if (! $handler instanceof GetVersionHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetVersionHandler::class);
    }

    return $handler;
}

function pwgCoreGetVersionHandlerTestServer(): Server
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
    $handler = pwgCoreGetVersionHandlerTestSubject();
    $server = pwgCoreGetVersionHandlerTestServer();

    $result = $handler([], $server);

    expect($result)
        ->toBe(AppInfo::VERSION);
});
