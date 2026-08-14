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
use Piwigo\Ws\Activity\GetListHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Activity\GetListHandler -- `pwg.activity.getList` (admin_only).
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers the pure "invalid date_min" guard and the present-but-empty
 * uid/id regression (both reachable without any real ActivityService/DB
 * call beyond the paginated read itself).
 */
function pwgActivityGetListHandlerTestSubject(): GetListHandler
{
    $handler = Kernel::container()->get(GetListHandler::class);
    if (! $handler instanceof GetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetListHandler::class);
    }

    return $handler;
}

function pwgActivityGetListHandlerTestServer(): Server
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

test('getList rejects an unparsable date_min', function (): void {
    $handler = pwgActivityGetListHandlerTestSubject();
    $server = pwgActivityGetListHandlerTestServer();

    $result = $handler([
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

test('getList treats a present-but-empty uid/id the same as absent, not a fatal type error', function (): void {
    // Both are WsParamType::ID (optional, null default) --
    // Server::checkType() deliberately skips type coercion for an
    // empty-string value on an OPTIONAL param, so a real client that
    // sends 'uid='/'id=' (e.g. Users\Admin\user_activity.js's own
    // uid_filter/additional_filt_value, undefined/null on first page
    // load) reaches this method with the raw string '', not int|null.
    // Real bug reproduced live: this previously threw
    // "UserId::from(): Argument #1 ($value) must be of type int, string
    // given" / "ActivityListCriteria::__construct(): Argument #6
    // ($objectId) must be of type ?int, string given" instead of
    // returning a result.
    $handler = pwgActivityGetListHandlerTestSubject();
    $server = pwgActivityGetListHandlerTestServer();

    $result = $handler([
        'page' => 0,
        'offset' => 0,
        'uid' => '',
        'date_min' => null,
        'date_max' => null,
        'id' => '',
        'object' => null,
        'action' => null,
    ], $server);

    expect($result)
        ->not->toBeInstanceOf(WsErrorResponse::class)
        ->toHaveKey('result_lines');
});
