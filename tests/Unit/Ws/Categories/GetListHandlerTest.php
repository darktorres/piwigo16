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
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Categories\GetListHandler;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Categories\GetListHandler -- `pwg.categories.getList`.
 * Resolved via `Kernel::container()->get()`, same rationale as
 * GetListHandlerTest.php (Comments).
 *
 * Covers its own 2 pure validation guards (invalid `thumbnail_size`,
 * incompatible `recursive`+`limit`), both reached after
 * `$this->currentUser->get()` (needs a real, initialized `CurrentUser`)
 * but before any real category tree computation.
 */
function pwgCategoriesGetListHandlerTestSubject(): GetListHandler
{
    $handler = Kernel::container()->get(GetListHandler::class);
    if (! $handler instanceof GetListHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetListHandler::class);
    }

    return $handler;
}

function pwgCategoriesGetListHandlerTestServer(): Server
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
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Admin,
        enabledHigh: false,
    ));
});

afterEach(function (): void {
    Kernel::reset();
});

test('rejects an unknown thumbnail_size', function (): void {
    $handler = pwgCategoriesGetListHandlerTestSubject();
    $server = pwgCategoriesGetListHandlerTestServer();

    $result = $handler([
        'cat_id' => null,
        'recursive' => false,
        'public' => false,
        'tree_output' => false,
        'fullname' => false,
        'thumbnail_size' => 'not-a-real-size',
        'search' => null,
        'limit' => null,
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Invalid thumbnail_size');
    }
});

test('rejects recursive combined with a non-null limit', function (): void {
    $handler = pwgCategoriesGetListHandlerTestSubject();
    $server = pwgCategoriesGetListHandlerTestServer();

    $result = $handler([
        'cat_id' => null,
        'recursive' => true,
        'public' => false,
        'tree_output' => false,
        'fullname' => false,
        'thumbnail_size' => 'medium',
        'search' => null,
        'limit' => 5,
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::InvalidParam->value)
            ->and($result->message())
            ->toBe('Cannot use both recursive and limit parameters at the same time');
    }
});
