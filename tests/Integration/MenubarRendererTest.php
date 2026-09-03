<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Latte\Runtime\Html;
use LogicException;
use Override;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Category\Event\GetCategoriesMenuRows;
use Piwigo\Common\Enum\Section;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Permission\PermissionService;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\Renderer;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CategoryInfoTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\DbTransactionTestOverride;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Url\UrlService;
use Piwigo\Users\User;

/**
 * MenubarRenderer::render() had zero dedicated coverage before this file --
 * every other reader of it is a Browser-suite full-page load (see
 * AboutControllerTest's own docblock), which doesn't feed PHPUnit code
 * coverage. Real Template compiling the actual themes/default/template/
 * menubar*.latte set, same shape as CategoryDefaultRendererTest/
 * CalendarRendererTest -- and the real blockmanager_register_blocks event
 * handler (HtmlService::registerDefaultMenubarBlocks()) wired by hand
 * since this test never boots RequestBootstrap.
 *
 * Only the 4 still-red branches get dedicated tests here (qsearch escaping,
 * the filter-icon stop/start links, and the related-categories exclude
 * loop) -- the rest of render() (categories/tags/specials/summary/
 * identification blocks) is exercised incidentally by every test here
 * since render() always builds all of them, but isn't the point of any
 * single assertion.
 */
final class MenubarRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private MenubarRenderer $renderer;

    private UrlService $urlService;

    private Template $template;

    private FilterState $filterState;

    private SectionContextRegistry $sectionContextRegistry;

    private SessionService $sessionService;

    private PermissionService $permissionService;

    private EntityManagerInterface $entityManager;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->reimportFixtureIfSharedStateUnknown(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();
        // Skips Template's own data_dir_checked write, which would
        // otherwise reach for a full RequestBootstrap dependency this
        // test never boots -- same as CategoryDefaultRendererTest/
        // PictureCommentRendererTest's identical comment.
        $currentConfig->dataDirChecked = '1';

        // Real menu-block registration (normally wired by
        // RequestBootstrap::finalize(), never booted here) -- without it
        // every $menu->getBlock(...) call in render() returns null and
        // the whole method is a no-op.
        EventDispatcherTestFactory::get()->reset();
        EventDispatcherTestFactory::get()->addTypedHandler(BlockManagerRegisterBlocks::class, (HtmlServiceTestFactory::build())->registerDefaultMenubarBlocks(...));

        $htmlService = HtmlServiceTestFactory::build();
        $this->urlService = UrlServiceTestFactory::build($htmlService);

        $this->template = TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default');
        CurrentTemplateTestFactory::get()->set($this->template);

        // UrlServiceTestFactory::build() resolves SectionContextRegistry
        // from the real container-shared instance once Kernel::boot() has
        // run -- booted here so that instance is the same one
        // $this->renderer is given directly below, not a disconnected one.
        Kernel::boot();
        $sectionContextRegistry = Kernel::container()->get(SectionContextRegistry::class);
        if (! $sectionContextRegistry instanceof SectionContextRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . SectionContextRegistry::class);
        }
        $this->sectionContextRegistry = $sectionContextRegistry;
        $sessionService = Kernel::container()->get(SessionService::class);
        if (! $sessionService instanceof SessionService) {
            throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
        }
        $this->sessionService = $sessionService;
        $permissionService = Kernel::container()->get(PermissionService::class);
        if (! $permissionService instanceof PermissionService) {
            throw new LogicException('Container returned an unexpected type for ' . PermissionService::class);
        }
        $this->permissionService = $permissionService;
        $entityManager = Kernel::container()->get(EntityManagerInterface::class);
        if (! $entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }
        $this->entityManager = $entityManager;
        // The mbCategories block (built unconditionally by every test in
        // this file) reaches CategoryService::getCategoriesMenu() ->
        // getComputedCategories()/filterMenuRows(), which read
        // 'level'/'forbidden_categories'/'expand' directly off
        // rawAttributes (no `??` fallback) -- matches
        // CategoryServiceTest's own realisticUserGlobal() shape, plus
        // 'expand' (that method's own extra requirement).
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'normal',
            'username' => 'fixture_admin',
            'forbidden_categories' => '0',
            'level' => '0',
            'image_access_type' => 'NOT IN',
            'image_access_list' => '',
            'expand' => false,
        ]));

        $this->renderer = new MenubarRenderer();
        $this->filterState = new FilterState();
        // A directly-constructed FilterState starts genuinely uninitialized
        // (no constructor default -- see its own assertInitialized() guard)
        // -- render()'s own getCategoriesMenu() call reads isEnabled()
        // unconditionally, so every test needs at least this disabled
        // baseline, matching IntegrationTestCase::setUp()'s own identical
        // default for the container-shared instance. Tests exercising the
        // filter-icon branches overwrite this with a real ->set() call.
        $this->filterState->set(false);
    }

    #[Override]
    protected function tearDown(): void
    {
        Kernel::reset();
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testRenderAssignsTheRawQuerySearchValueForASearchSection(): void
    {
        // Not pre-escaped (P59): both real consumers (index.latte's
        // QUERY_SEARCH, menubar_menu.latte's own value="") print this bare,
        // relying on Latte's own auto-escape once at print time -- escaping
        // it here too would double-escape. QUERY_SEARCH itself still carries
        // the raw value; the escaping assertion belongs to the template
        // render, not this unit of work.
        $this->sectionContextRegistry->set(new SectionContext(
            section: Section::Search,
            qsearchDetails: [
                'q' => '<script>alert(1)</script>',
            ],
        ));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        self::assertSame('<script>alert(1)</script>', $this->template->getTemplateVars('QUERY_SEARCH'));
    }

    public function testRenderDoesNotAssignQuerySearchOutsideASearchSection(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(section: Section::Categories));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        self::assertNull($this->template->getTemplateVars('QUERY_SEARCH'));
    }

    /**
     * These two assert the rendered `<a href>` rather than a
     * `U_STOP_FILTER`/`U_START_FILTER` template var: both values used to
     * be assigned ambiently by MenubarIdentificationPageContext and are
     * now passed straight to MenubarCategoriesView, which is their only
     * reader. Asserting the markup is the stronger check anyway -- it
     * fails if the value is computed correctly but never rendered, which
     * is the failure the old assertion could not see.
     */
    public function testRenderAssignsAStopFilterLinkWhenTheRecentFilterIsActive(): void
    {
        CurrentConfigTestFactory::get()->menubarFilterIcon = true;
        CurrentConfigTestFactory::get()->filterPages = [
            'default' => [
                'used' => true,
            ],
        ];
        $this->filterState->set(true, '', '', []);

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        $expected = $this->urlService->addUrlParams($this->urlService->makeIndexUrl([]), [
            'filter' => 'stop',
        ]);
        $menubar = $this->template->getTemplateVars('MENUBAR');
        self::assertInstanceOf(Html::class, $menubar);
        self::assertStringContainsString(sprintf('href="%s"', $expected), (string) $menubar);
        self::assertStringNotContainsString('filter=start-recent-', (string) $menubar);
    }

    public function testRenderAssignsAStartFilterLinkWithTheUsersRecentPeriodWhenTheFilterIsInactive(): void
    {
        CurrentConfigTestFactory::get()->menubarFilterIcon = true;
        CurrentConfigTestFactory::get()->filterPages = [
            'default' => [
                'used' => true,
            ],
        ];
        $this->filterState->set(false, '', '', []);
        CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withRawAttribute('recent_period', 7));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        $expected = $this->urlService->addUrlParams($this->urlService->makeIndexUrl([]), [
            'filter' => 'start-recent-7',
        ]);
        $menubar = $this->template->getTemplateVars('MENUBAR');
        self::assertInstanceOf(Html::class, $menubar);
        self::assertStringContainsString(sprintf('href="%s"', $expected), (string) $menubar);
        self::assertStringNotContainsString('filter=stop', (string) $menubar);
    }

    /**
     * Fixture image/category shape (tests/Fixtures/piwigo-17.0.sql):
     * images 1-3 are in category 1 ('Sample Album'), images 4-5 are in
     * category 2 ('Nested Sub Album'). Passing items from both categories
     * with the current page pinned to category 1 makes category 2 the
     * one and only related category -- the page category itself is
     * always excluded (that part of the method is already covered), but
     * with no combined_categories there's nothing else to exclude, so
     * category 2's name surfaces in the rendered related-albums markup.
     */
    public function testRenderRelatedCategoriesShowsACommonCategoryNotExcluded(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(
            section: Section::Categories,
            items: [1, 4],
            category: CategoryInfoTestFactory::build(id: 1, name: 'Sample Album'),
            combinedCategories: null,
        ));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        // BlockManager::apply()'s assignVarFromTemplate() wraps MENUBAR in
        // Latte\Runtime\Html, not a plain string.
        $menubar = $this->template->getTemplateVars('MENUBAR');
        self::assertInstanceOf(Html::class, $menubar);
        $menubarHtml = (string) $menubar;
        self::assertStringContainsString('Related albums', $menubarHtml);
        self::assertStringContainsString('Nested Sub Album', $menubarHtml);
    }

    /**
     * Same items/page-category as above, but this time category 2 is
     * also listed in combined_categories -- the still-red foreach loop
     * (MenubarRenderer::render()'s own lines building $exclude_cat_ids
     * from $combined_categories) folds its id into the exclusion set too,
     * leaving zero related categories and hiding the whole block (a
     * hidden DisplayBlock contributes nothing to the compiled menubar.latte
     * output at all, not just an empty list).
     */
    public function testRenderRelatedCategoriesExcludesIdsFromCombinedCategories(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(
            section: Section::Categories,
            items: [1, 4],
            category: CategoryInfoTestFactory::build(id: 1, name: 'Sample Album'),
            combinedCategories: [
                CategoryInfoTestFactory::build(id: 2, name: 'Nested Sub Album'),
            ],
        ));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        // BlockManager::apply()'s assignVarFromTemplate() wraps MENUBAR in
        // Latte\Runtime\Html, not a plain string.
        $menubar = $this->template->getTemplateVars('MENUBAR');
        self::assertInstanceOf(Html::class, $menubar);
        self::assertStringNotContainsString('Related albums', (string) $menubar);
    }

    /**
     * CategoryService::getCategoriesMenu() dispatches GetCategoriesMenuRows
     * right after filterMenuRows() -- captures the real dispatched event to
     * assert its context flags match this file's own setUp() defaults
     * (expand=false, no active filter), that real fixture rows flow
     * through it (not a synthetic/empty array), then mutates $rows to
     * empty and asserts the resulting MENUBAR render drops the real
     * fixture category name it would otherwise contain -- proving the
     * mutation actually reaches the final rendered output, not just that
     * dispatch fires.
     */
    public function testRenderDispatchesGetCategoriesMenuRowsAndAppliesItsMutation(): void
    {
        $originalRows = null;
        $userExpand = null;
        $filterEnabled = null;
        EventDispatcherTestFactory::get()->addTypedHandler(
            GetCategoriesMenuRows::class,
            static function (GetCategoriesMenuRows $event) use (&$originalRows, &$userExpand, &$filterEnabled): void {
                $originalRows = $event->rows;
                $userExpand = $event->userExpand;
                $filterEnabled = $event->filterEnabled;
                $event->rows = [];
            },
        );

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        self::assertFalse($userExpand);
        self::assertFalse($filterEnabled);
        self::assertNotSame([], $originalRows);

        $menubar = $this->template->getTemplateVars('MENUBAR');
        self::assertInstanceOf(Html::class, $menubar);
        self::assertStringNotContainsString('Sample Album', (string) $menubar);
    }

    /**
     * BlockManager::apply() hides a registered block that nothing filled
     * in, so the menubar never emits an empty `<dl>`. Asserted on the
     * rendered markup rather than left to the golden/VR snapshots: both
     * were generated while two such blocks *were* being emitted, and a
     * snapshot records what is there rather than reporting what should
     * not be. `mbLinks` is unfilled here because the fixture config
     * carries no `links`, and `mbRelatedCategories` because a Categories
     * section has no related albums.
     */
    public function testRenderEmitsNoEmptyBlockForARegisteredBlockNothingFilledIn(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(section: Section::Categories));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger(), $this->permissionService, $this->entityManager, new Renderer(CurrentTemplateTestFactory::get()));

        $menubar = $this->template->getTemplateVars('MENUBAR');
        self::assertInstanceOf(Html::class, $menubar);

        self::assertStringNotContainsString('mbLinks', (string) $menubar);
        self::assertStringNotContainsString('mbRelatedCategories', (string) $menubar);
        self::assertDoesNotMatchRegularExpression(
            '~<dl id="[^"]+">\s*</dl>~',
            (string) $menubar,
        );
    }
}
