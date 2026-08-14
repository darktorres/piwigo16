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
use Piwigo\Ws\Comments\ValidateHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Comments\ValidateHandler -- `pwg.userComments.validate`
 * (admin_only, post_only). Resolved via `Kernel::container()->get()`,
 * same rationale as GetListHandlerTest.php. Covers only the
 * CSRF-token-mismatch 403 guard, same established pattern as
 * PermissionsTest.php -- a real validate needs a real comment row and is
 * not attempted here.
 */
function pwgValidateHandlerTestSubject(): ValidateHandler
{
    $handler = Kernel::container()->get(ValidateHandler::class);
    if (! $handler instanceof ValidateHandler) {
        throw new LogicException('Container returned an unexpected type for ' . ValidateHandler::class);
    }

    return $handler;
}

/**
 * This handler never reaches `$service->invoke()` -- a bare,
 * unregistered Server only needs to satisfy the type, same rationale as
 * PermissionsTest.php's own pwgPermissionsTestServer() helper.
 */
function pwgValidateHandlerTestServer(): Server
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
    $handler = pwgValidateHandlerTestSubject();
    $server = pwgValidateHandlerTestServer();

    $result = $handler([
        'comment_id' => [1],
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
