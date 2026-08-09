<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Users\User;

/**
 * Only the debug-info (showQueries/showGt) and mobile-theme-toggle
 * branches of prepareTail() below -- everything else (VERSION/PHPWG_URL/
 * VITALS_SCRIPT_URL assignment, the webmaster-contact branch,
 * telemetrySender->send()) is already exercised indirectly by every
 * Browser suite page-load test (every real Controller reaches
 * Bootstrap\PageTail::render()/renderToString(), which constructs this
 * class), same reasoning as PageTailTest's own docblock for its sibling
 * Bootstrap\PageTail. CurrentUser is left at the default guest status
 * here specifically to skip the webmaster-contact DB lookup branch,
 * already covered that way elsewhere.
 */
final class PageTailRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PageTailRenderer $renderer;

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

        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get()));
        // footer.tpl's own {get_combined_scripts load='footer'} tag reaches
        // ScriptLoader::urlService() -- unset by default, real
        // RequestBootstrap-only wiring this test never boots.
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));

        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 2, 'status' => 'guest', 'username' => 'fixture_guest']));

        $_SESSION = [];

        $this->renderer = new PageTailRenderer(
            new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
            new class implements TelemetrySenderInterface {
                public function send(): void
                {
                    // No-op: telemetry sending is out of scope for this
                    // class's own tests (see docblock) -- this renderer
                    // only needs *a* TelemetrySenderInterface to construct.
                }
            },
            UrlServiceTestFactory::build(),
            new EventDispatcher(),
            PageStateTestFactory::get(),
            CurrentTemplate::current(),
            CurrentConfigTestFactory::get(),
            new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), CurrentConfigTestFactory::get())
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentTemplate::current()->reset();
        PageStateTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_render_to_string_includes_the_query_debug_list_when_show_queries_is_enabled(): void
    {
        CurrentConfigTestFactory::get()->showQueries = true;
        CurrentConfigTestFactory::get()->showGt = false;
        PageStateTestFactory::get()->debugOutput = '<li>SELECT 1 -- pagetailrenderer test query</li>';

        $output = $this->renderer->renderToString(microtime(true));

        self::assertStringContainsString('<div id="debug">', $output);
        self::assertStringContainsString('SELECT 1 -- pagetailrenderer test query', $output);
    }

    public function test_render_to_string_omits_the_debug_list_when_show_queries_is_disabled(): void
    {
        CurrentConfigTestFactory::get()->showQueries = false;
        CurrentConfigTestFactory::get()->showGt = false;
        PageStateTestFactory::get()->debugOutput = '<li>should-not-appear</li>';

        $output = $this->renderer->renderToString(microtime(true));

        self::assertStringNotContainsString('id="debug"', $output);
        self::assertStringNotContainsString('should-not-appear', $output);
    }

    public function test_render_to_string_includes_generation_time_and_query_count_when_show_gt_is_enabled(): void
    {
        CurrentConfigTestFactory::get()->showQueries = false;
        CurrentConfigTestFactory::get()->showGt = true;
        PageStateTestFactory::get()->countQueries = 7;
        PageStateTestFactory::get()->queriesTime = 1.234567;

        $output = $this->renderer->renderToString(microtime(true) - 2.0);

        self::assertStringContainsString('Page generated in', $output);
        // "(7 SQL queries in 1.235 s)" -- footer.tpl's own literal template
        // text bracketing NB_QUERIES/SQL_TIME (number_format(1.234567, 3,
        // '.', ' ') . ' s'), asserted as one combined substring so a field
        // swap between the 2 values would fail this assertion.
        self::assertStringContainsString('(7 SQL queries in 1.235 s)', $output);
    }

    public function test_render_to_string_adds_the_mobile_theme_toggle_link_when_mobile_theme_is_active(): void
    {
        CurrentConfigTestFactory::get()->mobilTheme = 'mobile';
        $_SESSION['pwg_mobile_theme'] = true;
        $_SERVER['REQUEST_URI'] = '/test/mobile-toggle-path';

        try {
            $output = $this->renderer->renderToString(microtime(true));

            // DeviceHelper::mobileTheme() is true here, so the toggle link
            // flips it to 'false' -- addUrlParams() appends it as the
            // request URI's first (and only) query param.
            self::assertStringContainsString('href="/test/mobile-toggle-path?mobile=false"', $output);
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    public function test_render_to_string_omits_the_mobile_theme_toggle_link_when_mobile_theme_is_disabled(): void
    {
        CurrentConfigTestFactory::get()->mobilTheme = '';

        $output = $this->renderer->renderToString(microtime(true));

        self::assertStringNotContainsString('mobile=', $output);
    }
}
