<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Admin\UserActivitySubController;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

/**
 * Piwigo\Controller\Admin\UserActivitySubController -- a genuinely thin
 * delegate, all 14 constructor deps standard/already-factory-covered
 * (neither it nor UserActivityPageRenderer's own render() takes
 * CurrentUser directly -- AccessControl's own internal one is what
 * matters for the admin-status gate). No dedicated Integration/Browser
 * spec of its own.
 *
 * Reuses UserActivityPageRendererTest.php's own default happy path AND
 * its piwigo_activity snapshot/restore hardening verbatim (see that
 * file's own docblock for why: no Unit test owns that table, and it
 * accumulates real side-effect rows from Browser/Integration runs
 * sharing this same dev DB), just reached through handle() instead of a
 * direct render() call.
 */
function userActivitySubControllerTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-user-activity-subcontroller-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

    return $root;
}

function userActivitySubControllerTestRrmdir(string $dir): void
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
        is_dir($path) ? userActivitySubControllerTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function userActivitySubControllerTestAccessControl(): AccessControl
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

function userActivitySubControllerTestActivityService(): ActivityService
{
    return new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class));
}

function userActivitySubControllerTestLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

function userActivitySubControllerTestMailService(): MailService
{
    return new MailService(
        userActivitySubControllerTestLang(),
        new CurrentConfig(),
        new DeploymentPolicy(),
        new PageState(),
        Paths::fromRoot(sys_get_temp_dir()),
        new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), new CurrentConfig()),
        new Translator(new CurrentConfig()),
        new EventDispatcher(),
        new CurrentUser(new CurrentConfig()),
        UrlServiceTestFactory::build(),
    );
}

function userActivitySubControllerTestUserService(ActivityService $activityService): UserService
{
    $conn = DbConnection::build();

    return new UserService(
        userActivitySubControllerTestLang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), new CurrentConfig()),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        userActivitySubControllerTestMailService(),
        $activityService,
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

function userActivitySubControllerTestImageService(): ImageService
{
    $conn = DbConnection::build();

    return new ImageService(
        userActivitySubControllerTestLang(),
        EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
        userActivitySubControllerTestActivityService(),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
        new EventDispatcher(),
        new CurrentConfig(),
        new Translator(new CurrentConfig()),
        Paths::fromRoot(sys_get_temp_dir()),
    );
}

function userActivitySubControllerTestCategoryService(): CategoryService
{
    $conn = DbConnection::build();
    $currentUser = new CurrentUser(new CurrentConfig());

    return new CategoryService(
        userActivitySubControllerTestLang(),
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

function userActivitySubControllerTestGroupService(ActivityService $activityService): GroupService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();

    return new GroupService(
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        $activityService,
        new AuditService(EntityManagerFactory::build($conn)->getRepository(AuditLogEntity::class)),
        new ConfigService(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), new EventDispatcher(), $currentConfig),
        new EventDispatcher(),
        new CurrentUser($currentConfig),
        $currentConfig,
    );
}

test('handle() delegates to UserActivityPageRenderer::render() with real activity aggregated by user', function (): void {
    $root = userActivitySubControllerTestRoot();
    unset($_GET['photo'], $_GET['album'], $_GET['group'], $_GET['type']);
    // Same "own the table for this test's duration" technique
    // UserActivityPageRendererTest.php already established.
    $conn = DbConnection::build();
    $originalActivityRows = $conn->fetchAllAssociative('SELECT * FROM ' . 'activity');
    $conn->executeStatement('DELETE FROM ' . 'activity');
    $conn->executeStatement(
        "INSERT INTO " . 'activity' . " VALUES "
        . "(1,'system',3,'activate',NULL,'none','::1','2026-08-01 03:00:00','{\"script\": \"install\", \"theme_id\": \"default\"}',NULL),"
        . "(2,'system',1,'install',NULL,'none','::1','2026-08-01 03:00:00','{\"script\": \"install\", \"version\": \"16.3.0\"}',NULL),"
        . "(3,'user',1,'login',1,'8681675b2a4136fb177e08193dcc5043','::1','2026-08-01 03:00:00','{\"script\": \"install\"}','PiwigoFixtureRegen/1.0'),"
        . "(4,'user',1,'login',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.session.login\"}','PiwigoFixtureRegen/1.0'),"
        . "(5,'album',1,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.categories.add\"}',NULL),"
        . "(6,'album',2,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.categories.add\"}',NULL),"
        . "(7,'photo',1,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),"
        . "(8,'photo',2,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),"
        . "(9,'photo',3,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),"
        . "(10,'photo',4,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),"
        . "(11,'photo',5,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.images.addSimple\", \"added_with\": \"app\"}',NULL),"
        . "(12,'tag',1,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.tags.add\"}',NULL),"
        . "(13,'tag',2,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.tags.add\"}',NULL),"
        . "(14,'tag',3,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.tags.add\"}',NULL),"
        . "(15,'user',3,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.users.add\"}',NULL),"
        . "(16,'user',4,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.users.add\"}',NULL),"
        . "(17,'group',1,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.groups.add\"}',NULL),"
        . "(18,'group',2,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.groups.add\"}',NULL),"
        . "(19,'group',3,'add',1,'585b19819a0ff68b93e80b96c088e442','::1','2026-08-01 03:00:00','{\"method\": \"pwg.groups.add\"}',NULL)"
    );

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'user_activity.tpl', 'users={$nb_users}');
        file_put_contents($tplDir . 'tabsheet.tpl', 'tabsheet');
        $template->set_template_dir($tplDir);

        $activityService = userActivitySubControllerTestActivityService();
        $coreTabs = new CoreTabs(userActivitySubControllerTestLang(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = new UserActivitySubController(
            userActivitySubControllerTestLang(),
            userActivitySubControllerTestAccessControl(),
            UrlServiceTestFactory::build(),
            $coreTabs,
            CurrentTemplate::current(),
            $activityService,
            userActivitySubControllerTestUserService($activityService),
            userActivitySubControllerTestImageService(),
            userActivitySubControllerTestCategoryService(),
            userActivitySubControllerTestGroupService($activityService),
            HtmlServiceTestFactory::build(),
            CurrentConfigTestFactory::get(),
            new InputValidator(),
            $eventDispatcher,
        );

        $subController->handle(new ServerRequest('GET', '/admin.php'));

        expect($template->get_template_vars('nb_users'))->toBe(4)
            ->and($template->get_template_vars('ulist'))->toBe([
                ['id' => 1, 'username' => 'fixture_admin', 'nb_lines' => 17],
            ])
            ->and($template->get_template_vars('ADMIN_CONTENT'))->toBe('users=4');
    } finally {
        $conn->executeStatement('DELETE FROM ' . 'activity');
        foreach ($originalActivityRows as $row) {
            $conn->createQueryBuilder()
                ->insert('activity')
                ->values(array_combine(array_keys($row), array_map(static fn (string $col): string => ':' . $col, array_keys($row))))
                ->setParameters($row)
                ->executeStatement();
        }
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        userActivitySubControllerTestRrmdir($root);
    }
});
