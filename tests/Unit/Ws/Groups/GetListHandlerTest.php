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
use Piwigo\Ws\Groups\GetListHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Groups\GetListHandler -- `pwg.groups.getList` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers its own pure "invalid order" regex guard (no DB access at all)
 * plus a real DB read against a deliberately non-existent `group_id` --
 * deterministic empty-result shape, no shared fixture dependency, same
 * B2-pattern real-DB approach as this campaign's Repository/Service
 * tests.
 */
function pwgGroupsGetListHandlerTestSubject(): GetListHandler
{
    $handler = Kernel::container()->get(GetListHandler::class);
    if (! $handler instanceof GetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetListHandler::class);
    }

    return $handler;
}

function pwgGroupsGetListHandlerTestServer(): Server
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

test('rejects a malformed order parameter', function (): void {
    $handler = pwgGroupsGetListHandlerTestSubject();
    $server = pwgGroupsGetListHandlerTestServer();

    $result = $handler([
        'per_page' => 10,
        'page' => 0,
        'order' => '!!!not-a-real-order!!!',
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Invalid input parameter order');
    }
});

test('returns an empty groups list for a group_id with no real matches', function (): void {
    $handler = pwgGroupsGetListHandlerTestSubject();
    $server = pwgGroupsGetListHandlerTestServer();

    $result = $handler([
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
