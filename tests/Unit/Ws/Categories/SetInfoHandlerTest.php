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
use Piwigo\Ws\Categories\SetInfoHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Categories\SetInfoHandler -- `pwg.categories.setInfo`
 * (admin_only, post_only). Resolved via `Kernel::container()->get()`,
 * same rationale as GetListHandlerTest.php (Comments).
 *
 * Covers both an explicitly wrong token and an entirely absent one (SEC
 * finding 5 -- checkSecurityToken() used to be called with
 * required: false here, so an omitted pwg_token skipped CSRF validation
 * entirely). The real category update needs a real category row and is
 * not attempted here.
 */
function pwgCategoriesSetInfoHandlerTestSubject(): SetInfoHandler
{
    $handler = Kernel::container()->get(SetInfoHandler::class);
    if (! $handler instanceof SetInfoHandler) {
        throw new LogicException('Container returned an unexpected type for ' . SetInfoHandler::class);
    }

    return $handler;
}

function pwgCategoriesSetInfoHandlerTestServer(): Server
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

test('returns a 403 WsErrorResponse when a submitted pwg_token does not match the real CSRF token', function (): void {
    $handler = pwgCategoriesSetInfoHandlerTestSubject();
    $server = pwgCategoriesSetInfoHandlerTestServer();

    $result = $handler([
        'category_id' => 1,
        'name' => null,
        'comment' => null,
        'status' => null,
        'visible' => null,
        'commentable' => null,
        'apply_commentable_to_subalbums' => null,
        'pwg_token' => 'wrong-token',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});

test('returns a 403 WsErrorResponse when pwg_token is absent entirely', function (): void {
    $handler = pwgCategoriesSetInfoHandlerTestSubject();
    $server = pwgCategoriesSetInfoHandlerTestServer();

    $result = $handler([
        'category_id' => 1,
        'name' => null,
        'comment' => null,
        'status' => null,
        'visible' => null,
        'commentable' => null,
        'apply_commentable_to_subalbums' => null,
        'pwg_token' => null,
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(403);
    }
});
