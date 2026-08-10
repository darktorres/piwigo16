<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\ExtendForTemplatesSubController;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Controller\Admin\ExtendForTemplatesSubController -- a
 * genuinely thin delegate, all 9 constructor deps standard/already-
 * factory-covered. No dedicated Integration/Browser spec of its own.
 *
 * Reuses ExtendForTemplatesPageRendererTest.php's own default (no
 * submission, no configured extents, nothing on disk) happy path, just
 * reached through handle() instead of a direct render() call.
 */
function extendForTemplatesSubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extend-for-templates-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    mkdir($root . 'themes', 0o777, true);
    mkdir($root . 'template-extension', 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function extendForTemplatesSubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? extendForTemplatesSubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function extendForTemplatesSubControllerTestAccessControl(): AccessControl
{
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $currentUser->set(new User(
        id: UserId::from(1),
        username: null,
        email: null,
        language: LangCode::from('en_UK'),
        theme: '',
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    return new AccessControl(
        HtmlServiceTestFactory::build(),
        new AccessControlTestFakeRedirectServiceNeverCalled(),
        new AccessLevelChecker($currentUser, $currentConfig),
    );
}

function extendForTemplatesSubControllerTestCategoryService(): CategoryService
{
    $conn = DbConnection::build();
    $currentUser = new CurrentUser(new CurrentConfig());

    return new CategoryService(
        LangTestFactory::get(),
        new CategoryRepository(EntityManagerFactory::build($conn), new CurrentConfig()),
        new PermissionService(
            new PermissionRepository(EntityManagerFactory::build($conn)),
            EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
            new CategoryRepository(EntityManagerFactory::build($conn), new CurrentConfig()),
            $currentUser,
            new FilterState(),
            new AccessLevelChecker($currentUser, new CurrentConfig()),
        ),
        new CurrentConfig(),
        new EventDispatcher(),
        new Translator(new CurrentConfig()),
        new AccessLevelChecker($currentUser, new CurrentConfig()),
    );
}

test('handle() delegates to ExtendForTemplatesPageRenderer::render() with nothing configured', function (): void {
    $root = extendForTemplatesSubControllerTestRoot();
    unset($_POST['submit']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'extend_for_templates.tpl', 'title={$ADMIN_PAGE_TITLE}');
        $template->set_template_dir($tplDir);

        $conn = DbConnection::build();

        $subController = new ExtendForTemplatesSubController(
            LangTestFactory::get(),
            extendForTemplatesSubControllerTestAccessControl(),
            UrlServiceTestFactory::build(),
            new ConfigService(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), new EventDispatcher(), new CurrentConfig()),
            new PageState(),
            CurrentTemplate::current(),
            extendForTemplatesSubControllerTestCategoryService(),
            CurrentConfigTestFactory::get(),
            Paths::fromRoot($root),
        );

        $subController->handle(new ServerRequest('GET', '/admin.php'));

        expect($template->get_template_vars('ADMIN_PAGE_TITLE'))->toBe('Extend for templates')
            ->and($template->get_template_vars('extents'))->toBeNull()
            ->and($template->get_template_vars('ADMIN_CONTENT'))->toBe('title=Extend for templates');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        extendForTemplatesSubControllerTestRrmdir($root);
    }
});
