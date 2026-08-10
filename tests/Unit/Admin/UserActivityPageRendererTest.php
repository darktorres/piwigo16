<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserActivityPageRenderer;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
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
 * The real fixture's own piwigo_activity data (19 rows, all sharing one
 * literal occured_on) is used as-is, not fabricated -- no other Unit
 * test file writes to that table (confirmed via grep), so exact
 * aggregate assertions are safe against concurrent-worker races, unlike
 * piwigo_rate/piwigo_search. It is NOT immune to slow one-way
 * accumulation from real production code paths that log activity as a
 * side effect (e.g. a real theme activation/photo deletion exercised by
 * some other, unrelated test) -- confirmed live: this table had 28 stray
 * rows (activity_id > 19) left over from this exact session's own many
 * hours of Extension/theme-activation testing, restored to the
 * canonical 19 via `DELETE FROM piwigo_activity WHERE activity_id > 19`
 * before this test could pass. If this file ever needs to run
 * unattended in a long-lived shared dev DB again, own the table for the
 * test's own duration (delete all, insert a known snapshot, restore in
 * finally) instead of trusting the ambient fixture state.
 */
function userActivityTestRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-user-activity-test-' . bin2hex(random_bytes(8)) . '/';
    mkdir($root, 0o777, true);
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setDataLocation('data/');
    CurrentConfigTestFactory::get()->setDataDirChecked('1');

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

function userActivityTestActivityService(): ActivityService
{
    return new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class));
}

// $lang/$mailService/$userService below never observably affect
// render()'s own default-path output -- same "type-satisfying instance
// is enough" reasoning CoreUpdateServiceTest.php's own equivalent
// helpers already established for these exact classes.
function userActivityTestLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

function userActivityTestMailService(): MailService
{
    return new MailService(
        userActivityTestLang(),
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

function userActivityTestUserService(ActivityService $activityService): UserService
{
    $conn = DbConnection::build();

    return new UserService(
        userActivityTestLang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), new CurrentConfig()),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        userActivityTestMailService(),
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

// Never actually invoked for the no-additional-filter happy path below
// (the additional-filter loop's photo/album/group branches are the only
// callers) -- type-satisfying instances only, same reasoning as
// $lang/$mailService/$userService above.
function userActivityTestImageService(): ImageService
{
    $conn = DbConnection::build();

    return new ImageService(
        userActivityTestLang(),
        EntityManagerFactory::build($conn)->getRepository(ImageEntity::class),
        userActivityTestActivityService(),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
        new EventDispatcher(),
        new CurrentConfig(),
        new Translator(new CurrentConfig()),
        Paths::fromRoot(sys_get_temp_dir()),
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
        new Translator(new CurrentConfig()),
        new AccessLevelChecker($currentUser, new CurrentConfig()),
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
        new ConfigService(EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class), new EventDispatcher(), $currentConfig),
        new EventDispatcher(),
        new CurrentUser($currentConfig),
        $currentConfig,
    );
}

test('render() lists real activity aggregated by user and skips the additional-filter branch by default', function (): void {
    $root = userActivityTestRoot();
    unset($_GET['photo'], $_GET['album'], $_GET['group'], $_GET['type']);

    try {
        $template = TemplateTestFactory::build();
        CurrentTemplate::current()->set($template);
        $tplDir = $root . 'tpl/';
        mkdir($tplDir, 0o777, true);
        file_put_contents($tplDir . 'user_activity.tpl', 'users={$nb_users}');
        file_put_contents($tplDir . 'tabsheet.tpl', 'tabsheet');
        $template->set_template_dir($tplDir);

        $activityService = userActivityTestActivityService();
        $coreTabs = new CoreTabs(userActivityTestLang(), UrlServiceTestFactory::build(), new CurrentConfig());
        // Tabsheet::select() (called internally by render()) only ever
        // sees pre-registered sheets -- in production, AdminShell::
        // runDispatch() registers CoreTabs::addCoreTabs() as a
        // TabsheetBeforeSelect handler once per request, BEFORE any page
        // renderer runs, populating uniqid='users' with 'user_list'/
        // 'user_activity' entries. Without this, $tabsheet->selected stays
        // null (a real TypeError, confirmed live) -- same real dependency
        // every other Tabsheet::select()-calling renderer has.
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addTypedHandler(TabsheetBeforeSelect::class, $coreTabs->addCoreTabs(...));

        new UserActivityPageRenderer()->render(
            userActivityTestLang(),
            userActivityTestAccessControl(),
            UrlServiceTestFactory::build(),
            $coreTabs,
            CurrentTemplate::current(),
            CurrentConfigTestFactory::get(),
            $activityService,
            userActivityTestUserService($activityService),
            userActivityTestImageService(),
            userActivityTestCategoryService(),
            userActivityTestGroupService($activityService),
            HtmlServiceTestFactory::build(),
            new InputValidator(),
            $eventDispatcher,
        );

        // The real fixture's own piwigo_activity data: 17 non-system rows,
        // all performed_by user 1 (fixture_admin), all sharing the exact
        // same occured_on '2026-08-01 03:00:00'.
        expect($template->get_template_vars('nb_users'))->toBe(4)
            ->and($template->get_template_vars('ulist'))->toBe([
                ['id' => 1, 'username' => 'fixture_admin', 'nb_lines' => 17],
            ])
            ->and($template->get_template_vars('ACTIVITY_DATES'))->toBe(['min' => '2026-08-01', 'max' => '2026-08-01'])
            ->and($template->get_template_vars('ADDITIONAL_FILT'))->toBe(['type' => false, 'name' => null, 'value' => null])
            ->and($template->get_template_vars('ADMIN_CONTENT'))->toBe('users=4');

        $actions = $template->get_template_vars('ACTIONS');
        if (! is_array($actions)) {
            throw new LogicException('expected an array of actions');
        }
        $totalActionCount = array_sum(array_map(
            static fn (mixed $row): int => is_array($row) && isset($row['counter']) && is_numeric($row['counter']) ? (int) $row['counter'] : 0,
            $actions
        ));
        expect($totalActionCount)->toBe(17);
    } finally {
        CurrentTemplate::current()->reset();
        CurrentConfigTestFactory::get()->reset();
        Kernel::reset();
        userActivityTestRrmdir($root);
    }
});
