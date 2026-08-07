<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Core\CurrentLogger;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\FilterState;
use Piwigo\Core\Kernel;
use Piwigo\Menu\Event\BlockManagerRegisterBlocks;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Common\Enum\Section;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;

/**
 * MenubarRenderer::render() had zero dedicated coverage before this file --
 * every other reader of it is a Browser-suite full-page load (see
 * AboutControllerTest's own docblock), which doesn't feed PHPUnit code
 * coverage. Real Template compiling the actual themes/default/template/
 * menubar*.tpl set, same shape as CategoryDefaultRendererTest/
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

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

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
        $currentConfig->setDataDirChecked('1');

        // Real menu-block registration (normally wired by
        // RequestBootstrap::finalize(), never booted here) -- without it
        // every $menu->get_block(...) call in render() returns null and
        // the whole method is a no-op.
        EventDispatcherTestFactory::get()->reset();
        EventDispatcherTestFactory::get()->addTypedHandler(BlockManagerRegisterBlocks::class, (HtmlServiceTestFactory::build())->registerDefaultMenubarBlocks(...));

        $htmlService = HtmlServiceTestFactory::build();
        $this->urlService = UrlServiceTestFactory::build($htmlService);

        $this->template = TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default');
        CurrentTemplate::current()->set($this->template);

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
        parent::tearDown();
    }

    public function test_render_escapes_the_query_search_value_for_a_search_section(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(
            section: Section::Search,
            qsearchDetails: ['q' => '<script>alert(1)</script>'],
        ));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger());

        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $this->template->get_template_vars('QUERY_SEARCH'));
    }

    public function test_render_does_not_assign_query_search_outside_a_search_section(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(section: Section::Categories));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger());

        self::assertNull($this->template->get_template_vars('QUERY_SEARCH'));
    }

    public function test_render_assigns_a_stop_filter_link_when_the_recent_filter_is_active(): void
    {
        CurrentConfigTestFactory::get()->setMenubarFilterIcon(true);
        CurrentConfigTestFactory::get()->setFilterPages(['default' => ['used' => true]]);
        $this->filterState->set(true, '', '', []);

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger());

        $expected = $this->urlService->addUrlParams($this->urlService->makeIndexUrl([]), ['filter' => 'stop']);
        self::assertSame($expected, $this->template->get_template_vars('U_STOP_FILTER'));
        self::assertNull($this->template->get_template_vars('U_START_FILTER'));
    }

    public function test_render_assigns_a_start_filter_link_with_the_users_recent_period_when_the_filter_is_inactive(): void
    {
        CurrentConfigTestFactory::get()->setMenubarFilterIcon(true);
        CurrentConfigTestFactory::get()->setFilterPages(['default' => ['used' => true]]);
        $this->filterState->set(false, '', '', []);
        CurrentUserTestFactory::get()->set(CurrentUserTestFactory::get()->get()->withRawAttribute('recent_period', 7));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger());

        $expected = $this->urlService->addUrlParams($this->urlService->makeIndexUrl([]), ['filter' => 'start-recent-7']);
        self::assertSame($expected, $this->template->get_template_vars('U_START_FILTER'));
        self::assertNull($this->template->get_template_vars('U_STOP_FILTER'));
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
    public function test_render_related_categories_shows_a_common_category_not_excluded(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(
            section: Section::Categories,
            items: [1, 4],
            category: ['id' => 1, 'name' => 'Sample Album', 'permalink' => null],
            combinedCategories: null,
        ));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger());

        $menubar = $this->template->get_template_vars('MENUBAR');
        self::assertIsString($menubar);
        self::assertStringContainsString('Related albums', $menubar);
        self::assertStringContainsString('Nested Sub Album', $menubar);
    }

    /**
     * Same items/page-category as above, but this time category 2 is
     * also listed in combined_categories -- the still-red foreach loop
     * (MenubarRenderer::render()'s own lines building $exclude_cat_ids
     * from $combined_categories) folds its id into the exclusion set too,
     * leaving zero related categories and hiding the whole block (a
     * hidden DisplayBlock contributes nothing to the compiled menubar.tpl
     * output at all, not just an empty list).
     */
    public function test_render_related_categories_excludes_ids_from_combined_categories(): void
    {
        $this->sectionContextRegistry->set(new SectionContext(
            section: Section::Categories,
            items: [1, 4],
            category: ['id' => 1, 'name' => 'Sample Album', 'permalink' => null],
            combinedCategories: [['id' => 2, 'name' => 'Nested Sub Album', 'permalink' => null]],
        ));

        $this->renderer->render(LangTestFactory::get(), new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()), $this->urlService, $this->filterState, $this->sectionContextRegistry, $this->sessionService, new DeploymentPolicy(), CurrentUserTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get(), EventDispatcherTestFactory::get(), TranslatorTestFactory::get(), new CurrentLogger());

        $menubar = $this->template->get_template_vars('MENUBAR');
        self::assertIsString($menubar);
        self::assertStringNotContainsString('Related albums', $menubar);
    }
}
