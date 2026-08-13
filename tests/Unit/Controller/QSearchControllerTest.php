<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\QSearchController;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Http\ResponseReadyException;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\QSearchController -- 3 constructor deps, no
 * rendering of its own; always redirects to search.php?q=... (qsearch.php's
 * replacement). No dedicated Integration/Browser spec of its own.
 *
 * RedirectServiceInterface::redirect()'s own dispatcher checks a
 * container-resolved CurrentConfig::defaultRedirectMethod() (schema
 * default 'http') and headers_sent() (always false here, no prior
 * output) -- both hold by default, routing to the real
 * RedirectService::redirectHttp(), which just throws a
 * ResponseReadyException wrapping a real 302 Location response, no
 * Template/PageHeaderRenderer/PageTail bootstrap needed at all (unlike
 * redirectHtml(), the wall this campaign already hit and deferred once
 * for PictureCoiSubController). $lang/$userService/$eventDispatcher/
 * $pageState are never touched on this path -- type-satisfying instances
 * only.
 */
function qSearchTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-qsearch-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));

    return $root;
}

function qSearchTestRrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? qSearchTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function qSearchTestLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

function qSearchTestUserService(): UserService
{
    $conn = DbConnection::build();

    return new UserService(
        qSearchTestLang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), new CurrentConfig()),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
        HtmlServiceTestFactory::build(),
        $conn,
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
        new EventDispatcher(),
        new DeploymentPolicy(),
        new CurrentUser(new CurrentConfig()),
        new CurrentConfig(),
        new InstallationFlag(),
        new ProcessCache(),
        Paths::fromRoot(sys_get_temp_dir()),
    );
}

function qSearchTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(2),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: ThemeId::from('default'),
        status: UserStatus::Guest,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

test('__invoke redirects to search.php with the given q parameter', function (): void {
    $root = qSearchTestRoot();

    try {
        $redirectService = new RedirectService(qSearchTestLang(), qSearchTestUserService(), new EventDispatcher(), new PageState());
        $controller = new QSearchController(qSearchTestAccessControl(), $redirectService, UrlServiceTestFactory::build());
        $request = new ServerRequest('GET', '/qsearch.php?q=nature');

        $exception = null;
        try {
            $controller($request);
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        $response = $exception->response();
        expect($response->getStatusCode())
            ->toBe(302)
            ->and($response->getHeaderLine('Location'))
            ->toContain('search.php?q=nature');
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        qSearchTestRrmdir($root);
    }
});

test('__invoke redirects with an empty q when the query param is missing', function (): void {
    $root = qSearchTestRoot();

    try {
        $redirectService = new RedirectService(qSearchTestLang(), qSearchTestUserService(), new EventDispatcher(), new PageState());
        $controller = new QSearchController(qSearchTestAccessControl(), $redirectService, UrlServiceTestFactory::build());
        $request = new ServerRequest('GET', '/qsearch.php');

        $exception = null;
        try {
            $controller($request);
        } catch (ResponseReadyException $e) {
            $exception = $e;
        }

        expect($exception)
            ->toBeInstanceOf(ResponseReadyException::class);
        if (! $exception instanceof ResponseReadyException) {
            return; // unreachable -- the assertion above already failed the test otherwise.
        }
        expect($exception->response()->getHeaderLine('Location'))
            ->toContain('search.php?q=');
    } finally {
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        qSearchTestRrmdir($root);
    }
});
