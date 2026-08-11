<?php

declare(strict_types=1);

use Piwigo\Admin\ExtendForTemplatesPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;

/**
 * Piwigo\Admin\ExtendForTemplatesPageRenderer -- zero-constructor,
 * method-param-injected (B3 Tier 1 shape). No dedicated Integration/
 * Browser spec -- reached only via the "extend_for_templates" page slug.
 *
 * Only the default (no $_POST submission, no configured extents, no
 * on-disk theme templates found, both real fixture categories have
 * permalink=NULL) happy path is covered -- $extents stays genuinely null
 * (not an empty array) on this path per the context's own docblock, and
 * a real submission needs a much larger $_POST shape for marginal extra
 * branch coverage.
 */
function extendForTemplatesTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extend-for-templates-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    mkdir($root . 'themes', 0o777, true);
    // AdminUiHelper::getExtents()'s own real bug fix -- anchored to
    // $paths->root . 'template-extension', not a cwd-relative path --
    // opendir() warns (fails the suite under failOnWarning) if this
    // directory genuinely doesn't exist.
    mkdir($root . 'template-extension', 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function extendForTemplatesTestRrmdir(string $dir): void
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
        is_dir($path) ? extendForTemplatesTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function extendForTemplatesTestAccessControl(): AccessControl
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

function extendForTemplatesTestCategoryService(): CategoryService
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

test('render() has nothing to show when no extents are configured, no theme templates exist on disk, and no permalinks are active', function (): void {
    $root = extendForTemplatesTestRoot();
    unset($_POST['submit']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'extend_for_templates.tpl', 'title={$ADMIN_PAGE_TITLE}');
        $template->set_template_dir($tplDir);

        $conn = DbConnection::build();

        new ExtendForTemplatesPageRenderer()
            ->render(
                LangTestFactory::get(),
                extendForTemplatesTestAccessControl(),
                UrlServiceTestFactory::build(),
                new ConfigService(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), new EventDispatcher(), new CurrentConfig()),
                new PageState(),
                CurrentTemplate::current(),
                extendForTemplatesTestCategoryService(),
                CurrentConfigTestFactory::get(),
                Paths::fromRoot($root),
            );

        expect($template->get_template_vars('ADMIN_PAGE_TITLE'))
            ->toBe('Extend for templates')
            ->and($template->get_template_vars('extents'))
            ->toBeNull()
            ->and($template->get_template_vars('ADMIN_CONTENT'))
            ->toBe('title=Extend for templates');
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        extendForTemplatesTestRrmdir($root);
    }
});
