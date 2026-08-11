<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Admin;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\UserActivityPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Event\Admin\TabsheetBeforeSelect;
use Piwigo\Group\GroupService;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Url\UrlService;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

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
 * straight to an admin User (CurrentUserTestFactory::get()->set(), not a real
 * AuthService login) -- no activity row is written by that, unlike a
 * genuine loginAsAdmin() POST. The real fixture's own 17 non-'system' rows
 * (activity ids 3-19) are snapshotted and deleted for the
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

    /**
     * @var list<array<string, mixed>>
     */
    private array $deletedActivityRows = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        Kernel::boot();

        $this->conn = DbConnection::build();
        $this->urlService = UrlServiceTestFactory::build();

        // Real admin identity without a real login -- see this file's own
        // docblock for why a genuine loginAsAdmin() can't be used here.
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'admin',
            'username' => 'fixture_admin',
        ]));

        // Same wiring AdminShell::runDispatch() does for every real
        // admin.php request (Piwigo\Admin\AdminShell::runDispatch()) --
        // without it, Tabsheet::select('user_activity') finds an empty
        // $sheets array (CoreTabs::addCoreTabs() never registered) and
        // crashes on `$keys[0]` of an empty array.
        EventDispatcherTestFactory::get()->reset();
        $coreTabs = Kernel::container()->get(CoreTabs::class);
        if (! $coreTabs instanceof CoreTabs) {
            throw new LogicException('Container returned an unexpected type for ' . CoreTabs::class);
        }
        $this->coreTabs = $coreTabs;
        EventDispatcherTestFactory::get()->addTypedHandler(TabsheetBeforeSelect::class, $this->coreTabs->addCoreTabs(...));

        LangTestFactory::get()->load('admin.lang');
        // Template::__construct()'s own data_dir_checked first-time-setup
        // flow reaches CurrentConfigServiceTestFactory::get()->get() -- same wiring every
        // other Integration test constructing a real Template directly
        // does (e.g. ThemesStandardPagesPageRendererTest's own setUp()).
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default'));

        $_GET = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->deletedActivityRows !== []) {
            foreach ($this->deletedActivityRows as $row) {
                $this->conn->insert('activity', $row);
            }
            $this->deletedActivityRows = [];
        }
        $_GET = [];
        CurrentTemplate::current()->reset();
        // CoreTabs itself has no reset() (its $context/$urlService statics
        // are overwritten by whichever real request runs next -- same
        // "no reset needed" shape as every other CoreTabs-touching
        // Integration test in this suite).
        EventDispatcherTestFactory::get()->reset();
        Kernel::reset();
        parent::tearDown();
    }

    public function testNoActivityAtAllLeavesUlistEmptyInsteadOfErroring(): void
    {
        $rows = $this->conn->fetchAllAssociative('SELECT * FROM activity' . " WHERE object != 'system'");
        $this->deletedActivityRows = $rows;
        self::assertNotSame([], $rows, 'Fixture is expected to seed real non-system activity rows to delete/restore.');

        $this->conn->executeStatement("DELETE FROM activity WHERE object != 'system'");

        $activityService = Kernel::container()->get(ActivityService::class);
        if (! $activityService instanceof ActivityService) {
            throw new LogicException('Container returned an unexpected type for ' . ActivityService::class);
        }

        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }

        $imageService = Kernel::container()->get(ImageService::class);
        if (! $imageService instanceof ImageService) {
            throw new LogicException('Container returned an unexpected type for ' . ImageService::class);
        }

        $categoryService = Kernel::container()->get(CategoryService::class);
        if (! $categoryService instanceof CategoryService) {
            throw new LogicException('Container returned an unexpected type for ' . CategoryService::class);
        }

        $groupService = Kernel::container()->get(GroupService::class);
        if (! $groupService instanceof GroupService) {
            throw new LogicException('Container returned an unexpected type for ' . GroupService::class);
        }

        $htmlService = Kernel::container()->get(HtmlService::class);
        if (! $htmlService instanceof HtmlService) {
            throw new LogicException('Container returned an unexpected type for ' . HtmlService::class);
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }

        $accessControl = Kernel::container()->get(AccessControl::class);
        if (! $accessControl instanceof AccessControl) {
            throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }

        new UserActivityPageRenderer()
            ->render(LangTestFactory::get(), $accessControl, $this->urlService, $this->coreTabs, CurrentTemplate::current(), $currentConfig, $activityService, $userService, $imageService, $categoryService, $groupService, $htmlService, new InputValidator(), EventDispatcherTestFactory::get());

        $template = CurrentTemplate::current()->get();
        self::assertSame([], $template->getTemplateVars('ulist'));
    }
}
