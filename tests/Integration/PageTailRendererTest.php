<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\PageState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        Kernel::boot(Paths::fromRoot(dirname(__DIR__, 2)));
        CurrentConfigService::current()->set(new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher()));
        // footer.tpl's own {get_combined_scripts load='footer'} tag reaches
        // ScriptLoader::urlService() -- unset by default, real
        // RequestBootstrap-only wiring this test never boots.
        CurrentTemplate::current()->set(new Template(CurrentPaths::get()->root . 'themes', 'default'));

        CurrentUser::current()->set(User::fromUserArray(['id' => 2, 'status' => 'guest', 'username' => 'fixture_guest']));

        $_SESSION = [];

        $this->renderer = new PageTailRenderer(
            \Piwigo\Auth\AccessControl::current(),
            new class implements TelemetrySenderInterface {
                public function send(): void
                {
                    // No-op: telemetry sending is out of scope for this
                    // class's own tests (see docblock) -- this renderer
                    // only needs *a* TelemetrySenderInterface to construct.
                }
            },
            new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()),
            new \Piwigo\PluginConfig\EventDispatcher(),
            \Piwigo\Core\PageState::current(),
            CurrentTemplate::current()
        );
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentTemplate::current()->reset();
        PageState::current()->reset();
        CurrentUser::current()->reset();
        $_SESSION = [];
        parent::tearDown();
    }

    public function test_render_to_string_includes_the_query_debug_list_when_show_queries_is_enabled(): void
    {
        CurrentConfig::setShowQueries(true);
        CurrentConfig::setShowGt(false);
        PageState::current()->debugOutput = '<li>SELECT 1 -- pagetailrenderer test query</li>';

        $output = $this->renderer->renderToString(microtime(true));

        self::assertStringContainsString('<div id="debug">', $output);
        self::assertStringContainsString('SELECT 1 -- pagetailrenderer test query', $output);
    }

    public function test_render_to_string_omits_the_debug_list_when_show_queries_is_disabled(): void
    {
        CurrentConfig::setShowQueries(false);
        CurrentConfig::setShowGt(false);
        PageState::current()->debugOutput = '<li>should-not-appear</li>';

        $output = $this->renderer->renderToString(microtime(true));

        self::assertStringNotContainsString('id="debug"', $output);
        self::assertStringNotContainsString('should-not-appear', $output);
    }

    public function test_render_to_string_includes_generation_time_and_query_count_when_show_gt_is_enabled(): void
    {
        CurrentConfig::setShowQueries(false);
        CurrentConfig::setShowGt(true);
        PageState::current()->countQueries = 7;
        PageState::current()->queriesTime = 1.234567;

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
        CurrentConfig::setMobilTheme('mobile');
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
        CurrentConfig::setMobilTheme('');

        $output = $this->renderer->renderToString(microtime(true));

        self::assertStringNotContainsString('mobile=', $output);
    }
}
