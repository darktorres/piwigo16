<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserActivityPageRenderer;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\Html\HtmlService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

/**
 * Piwigo\Admin\UserActivityPageRenderer::render() -- see
 * tests/Browser/UserActivityPageRendererTest.php's own docblock for the
 * HTTP-reachable coverage (the "additional filter" branches). This file
 * closes the one branch that's genuinely unreachable from there:
 * `$username_of = [];` (no activity lines at all) needs
 * `ActivityService::getCountByUser()` (which already excludes `object =
 * 'system'` rows, see ActivityRepository::countByUser()) to return an
 * empty array -- but every Browser test authenticates via
 * H::loginAsAdmin(), and a real login itself writes a non-'system'
 * ('user'/'login') activity row before the page is ever requested. That
 * makes this a real, if narrow, production state (freshly installed, or
 * any deployment where every genuine activity row has been purged) that
 * simply cannot be reached through a real HTTP request in this suite --
 * same class of constraint as
 * tests/Integration/Admin/IntroSubControllerGetLatestNewsTest.php's own
 * "no injection point over HTTP" cases.
 *
 * render() is called directly here instead, with CurrentUser faked
 * straight to an admin User (CurrentUser::current()->set(), not a real
 * AuthService login) -- no activity row is written by that, unlike a
 * genuine loginAsAdmin() POST. The real fixture's own 17 non-'system' rows
 * (piwigo_activity ids 3-19) are snapshotted and deleted for the
 * duration of the test, then restored verbatim in tearDown() --
 * same "mutate shared fixture rows, restore afterward" shape
 * tests/Integration/CategoryAdminServiceTest.php's own tearDown() already
 * uses for group_access/user_access/categories.
 */
final class UserActivityPageRendererTest extends IntegrationTestCase
{
    private Connection $conn;

    private UrlService $urlService;

    private CoreTabs $coreTabs;

    /** @var list<array<string, mixed>> */
    private array $deletedActivityRows = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        Kernel::boot();

        $this->conn = DbConnection::build();
        $this->urlService = new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride());

        // Real admin identity without a real login -- see this file's own
        // docblock for why a genuine loginAsAdmin() can't be used here.
        CurrentUser::current()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'admin',
            'username' => 'fixture_admin',
        ]));

        // Same wiring AdminShell::runDispatch() does for every real
        // admin.php request (Piwigo\Admin\AdminShell::runDispatch()) --
        // without it, Tabsheet::select('user_activity') finds an empty
        // $sheets array (CoreTabs::addCoreTabs() never registered) and
        // crashes on `$keys[0]` of an empty array.
        EventDispatcher::get()->reset();
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $coreTabs instanceof CoreTabs) {
            throw new \LogicException('Container returned an unexpected type for ' . CoreTabs::class);
        }
        $this->coreTabs = $coreTabs;
        EventDispatcher::get()->addTypedHandler(TabsheetBeforeSelect::class, $this->coreTabs->addCoreTabs(...));

        Lang::current()->load('admin.lang');
        // Template::__construct()'s own data_dir_checked first-time-setup
        // flow reaches CurrentConfigService::current()->get() -- same wiring every
        // other Integration test constructing a real Template directly
        // does (e.g. ThemesStandardPagesPageRendererTest's own setUp()).
        CurrentConfigService::current()->set(new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Config\CurrentConfig::current()));
        CurrentTemplate::current()->set(new Template(CurrentPaths::get()->root . 'themes/admin', 'default'));

        $_GET = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->deletedActivityRows !== []) {
            foreach ($this->deletedActivityRows as $row) {
                $this->conn->insert(Tables::activity(), $row);
            }
            $this->deletedActivityRows = [];
        }
        $_GET = [];
        CurrentTemplate::current()->reset();
        // CoreTabs itself has no reset() (its $context/$urlService statics
        // are overwritten by whichever real request runs next -- same
        // "no reset needed" shape as every other CoreTabs-touching
        // Integration test in this suite).
        EventDispatcher::get()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    public function test_no_activity_at_all_leaves_ulist_empty_instead_of_erroring(): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->conn->fetchAllAssociative('SELECT * FROM ' . Tables::activity() . " WHERE object != 'system'");
        $this->deletedActivityRows = $rows;
        self::assertNotSame([], $rows, 'Fixture is expected to seed real non-system activity rows to delete/restore.');

        $this->conn->executeStatement('DELETE FROM ' . Tables::activity() . " WHERE object != 'system'");

        $activityService = Kernel::container()->get(\Piwigo\Activity\ActivityService::class);
        if (! $activityService instanceof \Piwigo\Activity\ActivityService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Activity\ActivityService::class);
        }

        $userService = Kernel::container()->get(\Piwigo\Users\UserService::class);
        if (! $userService instanceof \Piwigo\Users\UserService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Users\UserService::class);
        }

        $imageService = Kernel::container()->get(\Piwigo\Image\ImageService::class);
        if (! $imageService instanceof \Piwigo\Image\ImageService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Image\ImageService::class);
        }

        $categoryService = Kernel::container()->get(\Piwigo\Category\CategoryService::class);
        if (! $categoryService instanceof \Piwigo\Category\CategoryService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Category\CategoryService::class);
        }

        $groupService = Kernel::container()->get(\Piwigo\Group\GroupService::class);
        if (! $groupService instanceof \Piwigo\Group\GroupService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Group\GroupService::class);
        }

        $htmlService = Kernel::container()->get(\Piwigo\Html\HtmlService::class);
        if (! $htmlService instanceof \Piwigo\Html\HtmlService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Html\HtmlService::class);
        }

        $currentConfig = Kernel::container()->get(\Piwigo\Config\CurrentConfig::class);
        if (! $currentConfig instanceof \Piwigo\Config\CurrentConfig) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Config\CurrentConfig::class);
        }

        new UserActivityPageRenderer()->render(Lang::current(), \Piwigo\Auth\AccessControl::current(), $this->urlService, $this->coreTabs, CurrentTemplate::current(), $currentConfig, $activityService, $userService, $imageService, $categoryService, $groupService, $htmlService);

        $template = CurrentTemplate::current()->get();
        self::assertSame([], $template->get_template_vars('ulist'));
    }
}
