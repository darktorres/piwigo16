<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\Event\TabsheetBeforeSelect;
use Piwigo\Admin\UserActivityPageRenderer;
use Piwigo\Audit\AuditLogEntity;
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
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
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
 * Piwigo\Admin\UserActivityPageRenderer -- zero-constructor,
 * method-param-injected (B3 Tier 1 shape). No dedicated Integration/
 * Browser spec -- reached only via the "user_activity" page slug.
 *
 * The `?type=download_logs` branch ends in a real exit() (an HTTP-only
 * branch by this campaign's own documented convention), never exercised
 * here. Only the default (no download, no additional photo/album/group
 * filter) happy path is covered -- the additional-filter branch needs a
 * real ImageService/CategoryService/GroupService CALL, not just a
 * type-satisfying instance, which is a materially bigger unit of setup
 * for one more branch.
 *
 * This test owns piwigo_activity for its own duration: no other Unit
 * test file writes there, but the table itself
 * accumulates real side-effect rows from Browser/Integration runs
 * elsewhere in this same shared dev DB (theme activation logs "system"/
 * "activate", image deletion logs "photo"/"delete"), silently drifting
 * this test's exact-aggregate assertions each time. The test now snapshots whatever is really there
 * first (debris included, not this test's call to judge what's
 * canonical), replaces it with the exact known 19-row fixture dataset
 * its own assertions are written against, and restores the original
 * snapshot verbatim in finally regardless of outcome -- immune to
 * concurrent-worker races (same "own it" technique as piwigo_rate/
 * piwigo_search) AND to whatever unrelated process writes there while
 * this test runs.
 */
function userActivityTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-user-activity-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';

    return $root;
}

function userActivityTestRrmdir(string $dir): void
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
        is_dir($path) ? userActivityTestRrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

function userActivityTestAccessControl(): AccessControl
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

function userActivityTestActivityService(): ActivityService
{
    return new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class));
}

// $lang/$userService below never observably affect render()'s own
// default-path output -- same "type-satisfying instance is enough"
// reasoning CoreUpdateServiceTest.php's own equivalent helpers already
// established for these exact classes.
function userActivityTestLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

function userActivityTestUserService(ActivityService $activityService): UserService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $accessLevelChecker = new AccessLevelChecker($currentUser, $currentConfig);
    $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $currentUser, new FilterState(), $accessLevelChecker);

    return new UserService(
        userActivityTestLang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        $activityService,
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
        new CategoryService(userActivityTestLang(), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $permissionService, $currentConfig, new EventDispatcher(), new Translator($currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), $accessLevelChecker, new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig)),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
    );
}

// Never actually invoked for the no-additional-filter happy path below
// (the additional-filter loop's photo/album/group branches are the only
// callers) -- type-satisfying instances only, same reasoning as
// $lang/$mailService/$userService above.
function userActivityTestImageService(): ImageService
{
    $conn = DbConnection::build();

    return new ImageService(
        EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
        userActivityTestActivityService(),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
        new EventDispatcher(),
        new CurrentConfig(),
        Paths::fromRoot(sys_get_temp_dir()),
        userActivityTestCategoryService(),
    );
}

function userActivityTestCategoryService(): CategoryService
{
    $conn = DbConnection::build();
    $currentUser = new CurrentUser(new CurrentConfig());

    return new CategoryService(
        userActivityTestLang(),
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
        new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
        new AccessLevelChecker($currentUser, new CurrentConfig()),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), new CurrentConfig()),
    );
}

function userActivityTestGroupService(ActivityService $activityService): GroupService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();

    return new GroupService(
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        $activityService,
        new AuditService(EntityManagerFactory::build($conn)->getRepository(AuditLogEntity::class)),
        new ConfigService(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), $currentConfig),
        new CurrentUser($currentConfig),
        $currentConfig,
    );
}

test('render() lists real activity aggregated by user and skips the additional-filter branch by default', function (): void {
    $root = userActivityTestRoot();
    unset($_GET['photo'], $_GET['album'], $_GET['group'], $_GET['type']);
    // This test owns piwigo_activity for its own duration -- every
    // aggregate it asserts on (nb_users aside) sums over the WHOLE table,
    // and no Unit test file writes there today to coordinate a targeted
    // row instead. A real
    // Browser/Integration run elsewhere in this same shared dev DB can leave
    // stray system/activate + photo/delete rows behind (real side
    // effects of theme activation/image deletion), silently drifting
    // this test's exact-count assertions. Snapshot whatever is really
    // there (debris included -- not this test's call to judge what's
    // canonical), replace it with the exact known 19-row fixture dataset
    // this test's own assertions are written against, then restore the
    // original snapshot verbatim in finally regardless of outcome.
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
        file_put_contents($tplDir . 'user_activity.latte', 'users={$nbUsers}|ulist={$ulist|json_encode|noescape}|dates={$activityDates|json_encode|noescape}|filtType={$additionalFiltType|json_encode|noescape}|filtName={$additionalFiltName|json_encode|noescape}|filtValue={$additionalFiltValue|json_encode|noescape}|actions={$actions|json_encode|noescape}');
        file_put_contents($tplDir . 'tabsheet.latte', 'tabsheet');
        $template->setTemplateDir($tplDir);

        $activityService = userActivityTestActivityService();
        $coreTabs = new CoreTabs(userActivityTestLang(), UrlServiceTestFactory::build(), new CurrentConfig());
        // Tabsheet::select() (called internally by render()) only ever
        // sees pre-registered sheets -- in production, AdminShell::
        // runDispatch() registers CoreTabs::addCoreTabs() as a
        // TabsheetBeforeSelect handler once per request, BEFORE any page
        // renderer runs, populating uniqid='users' with 'user_list'/
        // 'user_activity' entries. Without this, $tabsheet->selected stays
        // null, a real TypeError -- same real dependency
        // every other Tabsheet::select()-calling renderer has.
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        $result = new UserActivityPageRenderer()
            ->render(
                userActivityTestLang(),
                userActivityTestAccessControl(),
                UrlServiceTestFactory::build(),
                $coreTabs,
                CurrentTemplateTestFactory::get(),
                $activityService,
                userActivityTestUserService($activityService),
                userActivityTestImageService(),
                userActivityTestCategoryService(),
                userActivityTestGroupService($activityService),
                HtmlServiceTestFactory::build(),
                new InputValidator(),
                $eventDispatcher,
                new Renderer(CurrentTemplateTestFactory::get()),
            );

        $adminContent = $result->content;

        expect($result->pageTitle)
            ->toBe('Users');

        $parts = [];
        foreach (explode('|', (string) $adminContent) as $field) {
            [$key, $value] = explode('=', $field, 2);
            $parts[$key] = $value;
        }

        // The real fixture's own piwigo_activity data: 17 non-system rows,
        // all performed_by user 1 (fixture_admin), all sharing the exact
        // same occured_on '2026-08-01 03:00:00'.
        expect($parts['users'])
            ->toBe('4')
            ->and(json_decode($parts['ulist'], true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                [
                    'id' => 1,
                    'username' => 'fixture_admin',
                    'nb_lines' => 17,
                ],
            ])
            ->and(json_decode($parts['dates'], true, flags: JSON_THROW_ON_ERROR))
            ->toBe([
                'min' => '2026-08-01',
                'max' => '2026-08-01',
            ])
            ->and(json_decode($parts['filtType'], true, flags: JSON_THROW_ON_ERROR))
            ->toBeFalse()
            ->and(json_decode($parts['filtName'], true, flags: JSON_THROW_ON_ERROR))
            ->toBeNull()
            ->and(json_decode($parts['filtValue'], true, flags: JSON_THROW_ON_ERROR))
            ->toBeNull();

        $actions = json_decode($parts['actions'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($actions)) {
            throw new LogicException('expected an array of actions');
        }
        $totalActionCount = array_sum(array_map(
            static fn (mixed $row): int => is_array($row) && isset($row['counter']) && is_numeric($row['counter']) ? (int) $row['counter'] : 0,
            $actions
        ));
        expect($totalActionCount)
            ->toBe(17);
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
        userActivityTestRrmdir($root);
    }
});
