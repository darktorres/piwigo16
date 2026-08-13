<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

// redirectHttp() throws ResponseReadyException instead of calling
// header()/exit() directly, which makes it possible to unit test
// (exit()-terminated methods can't be asserted on).
//
// redirectHttp() is typed `: never`, so PHPStan proves any code
// following a call to it never runs, in or out of a try block -- the
// exception is captured into a variable already declared before the try
// (never reassigned inside it under normal control flow) and asserted on
// afterwards, so every assertion sits in code PHPStan doesn't consider
// provably dead.

// RedirectService takes UserService via constructor injection -- neither
// test below ever touches it (redirectHttp() never reaches
// $this->userService), so a throwaway, never-queried instance is enough.
// Doctrine's DBAL connection is lazy (build() never opens a real
// connection until a query runs), so this is safe to construct with no
// reachable test DB.
/**
 * This suite never boots a Kernel (redirectHttp() never reaches
 * $this->lang either, same as $this->userService above), so resolving
 * the real container-shared Lang instance (a live container resolve)
 * isn't available -- a real, throwaway instance built from its 4 real,
 * cheap, DB-free collaborators is enough.
 */
function redirect_service_test_lang(): Lang
{
    return new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

function redirect_service_test_user_service(): UserService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $accessLevelChecker = new AccessLevelChecker($currentUser, $currentConfig);
    $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $currentUser, new FilterState(), $accessLevelChecker);

    return new UserService(
        redirect_service_test_lang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
        HtmlServiceTestFactory::build(),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $currentConfig),
        new EventDispatcher(),
        new DeploymentPolicy(),
        $currentUser,
        $currentConfig,
        new InstallationFlag(),
        new ProcessCache(),
        Paths::fromRoot(sys_get_temp_dir()),
        EntityManagerFactory::build($conn),
        $permissionService,
        new CategoryService(redirect_service_test_lang(), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $permissionService, $currentConfig, new EventDispatcher(), new Translator($currentConfig), $accessLevelChecker, new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig)),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
    );
}

test('redirectHttp throws ResponseReadyException with a 302 redirect to the given URL', function (): void {
    $service = new RedirectService(redirect_service_test_lang(), redirect_service_test_user_service(), new EventDispatcher(), new PageState());
    $exception = null;
    try {
        $service->redirectHttp('http://example.test/target.php');
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    $response = $exception->response();
    expect($response->getStatusCode())
        ->toBe(302);
    expect($response->getHeaderLine('Location'))
        ->toBe('http://example.test/target.php');
});

test('redirectHttp html_entity_decode()s the URL before redirecting', function (): void {
    $service = new RedirectService(redirect_service_test_lang(), redirect_service_test_user_service(), new EventDispatcher(), new PageState());
    $exception = null;
    try {
        $service->redirectHttp('http://example.test/target.php?a=1&amp;b=2');
    } catch (ResponseReadyException $e) {
        $exception = $e;
    }

    expect($exception->response()->getHeaderLine('Location'))
        ->toBe('http://example.test/target.php?a=1&b=2');
});
