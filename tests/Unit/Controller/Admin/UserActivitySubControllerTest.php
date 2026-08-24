<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\Event\TabsheetBeforeSelect;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\AuditService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Controller\Admin\UserActivitySubController;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
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
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

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
        theme: ThemeId::from('default'),
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
    return new ActivityService(TypedRepository::narrow(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class), ActivityRepository::class));
}

function userActivitySubControllerTestLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

function userActivitySubControllerTestUserService(ActivityService $activityService): UserService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $accessLevelChecker = new AccessLevelChecker($currentUser, $currentConfig);
    $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $currentUser, new FilterState(), $accessLevelChecker);

    return new UserService(
        userActivitySubControllerTestLang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig),
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class),
        $activityService,
        HtmlServiceTestFactory::build(),
        new SessionService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), SessionRepository::class), $currentConfig),
        new EventDispatcher(),
        new DeploymentPolicy(),
        $currentUser,
        $currentConfig,
        new InstallationFlag(),
        new ProcessCache(),
        Paths::fromRoot(sys_get_temp_dir()),
        EntityManagerFactory::build($conn),
        $permissionService,
        new CategoryService(userActivitySubControllerTestLang(), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $permissionService, $currentConfig, new EventDispatcher(), new Translator($currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), $accessLevelChecker),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
    );
}

function userActivitySubControllerTestImageService(): ImageService
{
    $conn = DbConnection::build();

    return new ImageService(
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ImageEntity::class), ImageRepository::class),
        userActivitySubControllerTestActivityService(),
        new EventDispatcher(),
        new CurrentConfig(),
        Paths::fromRoot(sys_get_temp_dir()),
        userActivitySubControllerTestCategoryService(),
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
            TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class),
            new CategoryRepository(EntityManagerFactory::build($conn), new CurrentConfig()),
            $currentUser,
            new FilterState(),
            new AccessLevelChecker($currentUser, new CurrentConfig()),
        ),
        new CurrentConfig(),
        new EventDispatcher(),
        new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
        new AccessLevelChecker($currentUser, new CurrentConfig()),
    );
}

function userActivitySubControllerTestGroupService(ActivityService $activityService): GroupService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();

    return new GroupService(
        TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), GroupRepository::class),
        $activityService,
        new AuditService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(AuditLogEntity::class), AuditRepository::class)),
        new ConfigService(TypedRepository::narrow(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), ConfigRepository::class), $currentConfig),
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
    $originalActivityRows = $conn->fetchAllAssociative('SELECT * FROM activity');
    $conn->executeStatement('DELETE FROM activity');
    $conn->executeStatement(
        // Explicit column list: activity gained typed reference columns
        // (user_id, category_id, ...) and a column-less INSERT breaks the
        // moment the table grows. These rows exercise object/object_id,
        // which is what the renderer reads.
        'INSERT INTO activity (activity_id, object, object_id, action, performed_by, session_idx, ip_address, occured_on, details, user_agent) VALUES '
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
        CurrentTemplateTestFactory::get()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'user_activity.latte', 'users={$nbUsers}|ulist={$ulist|json_encode|noescape}');
        file_put_contents($tplDir . 'tabsheet.latte', 'tabsheet');
        $template->setTemplateDir($tplDir);

        $activityService = userActivitySubControllerTestActivityService();
        $coreTabs = new CoreTabs(userActivitySubControllerTestLang(), UrlServiceTestFactory::build(), new CurrentConfig());
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $subController = new UserActivitySubController(
            userActivitySubControllerTestLang(),
            userActivitySubControllerTestAccessControl(),
            UrlServiceTestFactory::build(),
            $coreTabs,
            CurrentTemplateTestFactory::get(),
            $activityService,
            userActivitySubControllerTestUserService($activityService),
            userActivitySubControllerTestImageService(),
            userActivitySubControllerTestCategoryService(),
            userActivitySubControllerTestGroupService($activityService),
            HtmlServiceTestFactory::build(),
            new InputValidator(),
            $eventDispatcher,
            new Renderer(CurrentTemplateTestFactory::get()),
        );

        $result = $subController->handle(new ServerRequest('GET', '/admin.php'));

        $adminContent = $result->content;

        expect($result->pageTitle)
            ->toBe('Users');

        $adminContentParts = explode('|', (string) $adminContent, 2);
        assert(count($adminContentParts) === 2);
        [$usersPart, $ulistPart] = $adminContentParts;
        expect($usersPart)
            ->toBe('users=4')
            ->and(json_decode(substr($ulistPart, strlen('ulist=')), true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                [
                    'id' => 1,
                    'username' => 'fixture_admin',
                    'nb_lines' => 17,
                ],
            ]);
    } finally {
        $conn->executeStatement('DELETE FROM activity');
        foreach ($originalActivityRows as $row) {
            $conn->createQueryBuilder()
                ->insert('activity')
                ->values(array_combine(array_keys($row), array_map(static fn (string $col): string => ':' . $col, array_keys($row))))
                ->setParameters($row)
                ->executeStatement();
        }
        CurrentTemplateTestFactory::get()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        userActivitySubControllerTestRrmdir($root);
    }
});
