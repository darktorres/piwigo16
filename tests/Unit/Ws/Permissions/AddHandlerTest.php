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
use Piwigo\Ws\Permissions\AddHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Permissions\AddHandler -- `pwg.permissions.add` (admin_only,
 * post_only). Resolved via `Kernel::container()->get()`, same rationale
 * as GetListHandlerTest.php (Comments).
 *
 * `AddHandler` checks `CsrfService::getToken() !== $input->pwgToken` as
 * its very first statement, before touching `categoryService`/
 * `permissionService` at all -- a wrong token is therefore a cheap,
 * DB-free 403 branch, same shape as this campaign's established
 * CSRF-mismatch pattern. Its real permission-grant logic needs real
 * category/group/user rows and is not attempted here.
 */
function pwgPermissionsAddHandlerTestSubject(): AddHandler
{
    $handler = Kernel::container()->get(AddHandler::class);
    if (! $handler instanceof AddHandler) {
        throw new LogicException('Container returned an unexpected type for ' . AddHandler::class);
    }

    return $handler;
}

/**
 * This handler never reaches `$service->invoke()` -- the CSRF guard
 * fires before the real `$server->invoke('pwg.permissions.getList',
 * ...)` call. A bare, unregistered Server only needs to satisfy the
 * type.
 */
function pwgPermissionsAddHandlerTestServer(): Server
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

test('returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgPermissionsAddHandlerTestSubject();
    $server = pwgPermissionsAddHandlerTestServer();

    $result = $handler([
        'cat_id' => [1],
        'recursive' => false,
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403)
            ->and($result->message())
            ->toBe('Invalid security token');
    }
});
