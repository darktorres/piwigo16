<?php

declare(strict_types=1);

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\WsError;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Piwigo\Ws\Permissions;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsErrorResponse;

/**
 * Piwigo\Ws\Permissions -- the `pwg.permissions.*` WS methods (3
 * registrations, all admin_only per WsDefaultMethods). No dedicated
 * Integration/Browser spec of its own.
 *
 * `getList()` covers its own pure "too many parameters" guard (no DB
 * access at all) plus a real DB read against a deliberately non-existent
 * `cat_id` (999999) -- deterministic empty-result shape, no shared
 * fixture dependency, same B2-pattern real-DB approach as this
 * campaign's Repository/Service tests.
 *
 * `add()`/`remove()` both check `CsrfService::getToken() !==
 * $params['pwg_token']` as their very first statement, before touching
 * `categoryService`/`permissionService` at all -- a wrong token is
 * therefore a cheap, DB-free 403 branch, same shape as this campaign's
 * established CSRF-mismatch pattern (`MaintenanceSubControllerTest.php`)
 * even though `CsrfService::checkOrFail()` isn't used here (this class
 * compares the token directly and returns a `WsErrorResponse` rather than
 * throwing). Their real permission-grant/deny logic needs real
 * category/group/user rows and is not attempted here.
 *
 * Kernel::boot() is required file-wide: LangTestFactory::get() (needed
 * by CategoryService's own constructor) resolves the container-shared
 * Lang instance.
 */
beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

function pwgPermissionsTestSubject(): Permissions
{
    $conn = DbConnection::build();
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

    $permissionService = new PermissionService(
        new PermissionRepository(EntityManagerFactory::build($conn)),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        $currentUser,
        new FilterState(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );

    $categoryService = new CategoryService(
        LangTestFactory::get(),
        new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig),
        $permissionService,
        $currentConfig,
        new EventDispatcher(),
        new Translator($currentConfig),
        new AccessLevelChecker($currentUser, $currentConfig),
    );

    return new Permissions($permissionService, $categoryService, $currentConfig);
}

/**
 * None of the branches under test here ever reach `$service->invoke()`
 * (getList()'s guard fires first; add()/remove()'s CSRF check fires
 * before their own real `$service->invoke('pwg.permissions.getList',
 * ...)` call) -- a bare, unregistered Server only needs to satisfy
 * the by-ref type, same rationale as ServerTest.php's own
 * pwgServerTestServer() helper.
 */
function permissionsTestServer(): Server
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

test('getList rejects more than one of cat_id/group_id/user_id at once', function (): void {
    $ws = pwgPermissionsTestSubject();
    $server = permissionsTestServer();

    $result = $ws->getList([
        'cat_id' => [1],
        'group_id' => [1],
    ], $server);

    expect($result)
        ->toBeInstanceOf(WsErrorResponse::class);
    if ($result instanceof WsErrorResponse) {
        expect($result->code())
            ->toBe(WsError::INVALID_PARAM)
            ->and($result->message())
            ->toBe('Too many parameters, provide cat_id OR user_id OR group_id');
    }
});

test('getList returns an empty categories list for a cat_id with no real access rows', function (): void {
    $ws = pwgPermissionsTestSubject();
    $server = permissionsTestServer();

    $result = $ws->getList([
        'cat_id' => [999999],
    ], $server);

    expect($result)
        ->toBeArray();
    if (is_array($result)) {
        expect($result['categories']->content)->toBe([]);
    }
});

test('add returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgPermissionsTestSubject();
    $server = permissionsTestServer();

    $result = $ws->add([
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

test('remove returns a 403 WsErrorResponse when the submitted pwg_token does not match the real CSRF token', function (): void {
    $ws = pwgPermissionsTestSubject();
    $server = permissionsTestServer();

    $result = $ws->remove([
        'cat_id' => [1],
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
