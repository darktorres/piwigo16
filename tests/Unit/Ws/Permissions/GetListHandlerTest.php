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
use Piwigo\Ws\Permissions\GetListHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Permissions\GetListHandler -- `pwg.permissions.getList`
 * (admin_only). Resolved via `Kernel::container()->get()`, same
 * rationale as GetListHandlerTest.php (Comments).
 *
 * Covers its own pure "too many parameters" guard (no DB access at all)
 * plus a real DB read against a deliberately non-existent `cat_id`
 * (999999) -- deterministic empty-result shape, no shared fixture
 * dependency, same B2-pattern real-DB approach as this campaign's
 * Repository/Service tests.
 */
function pwgPermissionsGetListHandlerTestSubject(): GetListHandler
{
    $handler = Kernel::container()->get(GetListHandler::class);
    if (! $handler instanceof GetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetListHandler::class);
    }

    return $handler;
}

/**
 * This handler never reaches `$service->invoke()` -- its "too many
 * parameters" guard fires first for the guard test, and the real-DB
 * test never needs a registered method either. A bare, unregistered
 * Server only needs to satisfy the type.
 */
function pwgPermissionsGetListHandlerTestServer(): Server
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

test('rejects more than one of cat_id/group_id/user_id at once', function (): void {
    $handler = pwgPermissionsGetListHandlerTestSubject();
    $server = pwgPermissionsGetListHandlerTestServer();

    $result = $handler([
        'cat_id' => [1],
        'group_id' => [1],
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Too many parameters, provide cat_id OR user_id OR group_id');
    }
});

test('returns an empty categories list for a cat_id with no real access rows', function (): void {
    $handler = pwgPermissionsGetListHandlerTestSubject();
    $server = pwgPermissionsGetListHandlerTestServer();

    $result = $handler([
        'cat_id' => [999999],
    ], $server);

    expect($result)
        ->toBeArray();
    if (is_array($result)) {
        expect($result['categories']->content)->toBe([]);
    }
});
