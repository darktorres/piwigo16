<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Asset\ViteManifest;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Page\PageTailRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\RequestMetricsTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\User;

/**
 * Only the debug-info (showQueries/showGt) and mobile-theme-toggle
 * branches of prepareTail() below -- everything else (VERSION/APP_URL/
 * VITALS_SCRIPT_URL assignment, the webmaster-contact branch,
 * telemetrySender->send()) is already exercised indirectly by every
 * Browser suite page-load test (every real Controller reaches
 * Bootstrap\PageTail::renderToString()/prepareContext(), which
 * constructs this class), same reasoning as PageTailTest's own docblock
 * for its sibling
 * Bootstrap\PageTail. CurrentUser is left at the default guest status
 * here specifically to skip the webmaster-contact DB lookup branch,
 * already covered that way elsewhere.
 *
 * renderFooter() below recreates the deleted renderToString()'s own
 * prepareContext()+parse('footer.latte')+finalizeHtml() sequence
 * (P41-E, docs/PLAN.md) -- a real footer.latte render, not just the
 * ambient context prepareContext() assigns.
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
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
        // footer.latte's own {get_combined_scripts load='footer'} tag reaches
        // ScriptLoader::urlService() -- unset by default, real
        // RequestBootstrap-only wiring this test never boots.
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));

        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 2,
            'status' => 'guest',
            'username' => 'fixture_guest',
        ]));

        $_SESSION = [];

        $this->renderer = new PageTailRenderer(
            new AccessLevelChecker(CurrentUserTestFactory::get(), CurrentConfigTestFactory::get()),
            new class() implements TelemetrySenderInterface {
                public function send(): void
                {
                    // No-op: telemetry sending is out of scope for this
                    // class's own tests (see docblock) -- this renderer
                    // only needs *a* TelemetrySenderInterface to construct.
                }
            },
            UrlServiceTestFactory::build(),
            new EventDispatcher(),
            RequestMetricsTestFactory::get(),
            CurrentTemplateTestFactory::get(),
            CurrentConfigTestFactory::get(),
            new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), CurrentConfigTestFactory::get()),
            EntityManagerFactory::build(DbConnection::build()),
            new ViteManifest(CurrentPathsTestFactory::get()),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentTemplateTestFactory::get()->reset();
        RequestMetricsTestFactory::get()->reset();
        CurrentUserTestFactory::get()->reset();
        $_SESSION = [];
        parent::tearDown();
    }

    private function renderFooter(float $startTime): string
    {
        $this->renderer->prepareContext($startTime);

        $template = CurrentTemplateTestFactory::get()->get();

        return $template->finalizeHtml($template->parse('footer.latte'));
    }

    public function testRenderToStringIncludesTheQueryDebugListWhenShowQueriesIsEnabled(): void
    {
        CurrentConfigTestFactory::get()->showQueries = true;
        CurrentConfigTestFactory::get()->showGt = false;
        RequestMetricsTestFactory::get()->debugOutput = '<li>SELECT 1 -- pagetailrenderer test query</li>';

        $output = $this->renderFooter(microtime(true));

        self::assertStringContainsString('<div id="debug">', $output);
        self::assertStringContainsString('SELECT 1 -- pagetailrenderer test query', $output);
    }

    public function testRenderToStringOmitsTheDebugListWhenShowQueriesIsDisabled(): void
    {
        CurrentConfigTestFactory::get()->showQueries = false;
        CurrentConfigTestFactory::get()->showGt = false;
        RequestMetricsTestFactory::get()->debugOutput = '<li>should-not-appear</li>';

        $output = $this->renderFooter(microtime(true));

        self::assertStringNotContainsString('id="debug"', $output);
        self::assertStringNotContainsString('should-not-appear', $output);
    }

    public function testRenderToStringIncludesGenerationTimeAndQueryCountWhenShowGtIsEnabled(): void
    {
        CurrentConfigTestFactory::get()->showQueries = false;
        CurrentConfigTestFactory::get()->showGt = true;
        RequestMetricsTestFactory::get()->countQueries = 7;
        RequestMetricsTestFactory::get()->queriesTime = 1.234567;

        $output = $this->renderFooter(microtime(true) - 2.0);

        self::assertStringContainsString('Page generated in', $output);
        // "(7 SQL queries in 1.235 s)" -- footer.latte's own literal template
        // text bracketing NB_QUERIES/SQL_TIME (number_format(1.234567, 3,
        // '.', ' ') . ' s'), asserted as one combined substring so a field
        // swap between the 2 values would fail this assertion.
        self::assertStringContainsString('(7 SQL queries in 1.235 s)', $output);
    }

    public function testRenderToStringAddsTheMobileThemeToggleLinkWhenMobileThemeIsActive(): void
    {
        CurrentConfigTestFactory::get()->mobileTheme = 'mobile';
        $_SESSION['pwg_mobile_theme'] = true;
        $_SERVER['REQUEST_URI'] = '/test/mobile-toggle-path';

        try {
            $output = $this->renderFooter(microtime(true));

            // DeviceHelper::mobileTheme() is true here, so the toggle link
            // flips it to 'false' -- addUrlParams() appends it as the
            // request URI's first (and only) query param.
            self::assertStringContainsString('href="/test/mobile-toggle-path?mobile=false"', $output);
        } finally {
            unset($_SERVER['REQUEST_URI']);
        }
    }

    public function testRenderToStringOmitsTheMobileThemeToggleLinkWhenMobileThemeIsDisabled(): void
    {
        CurrentConfigTestFactory::get()->mobileTheme = '';

        $output = $this->renderFooter(microtime(true));

        self::assertStringNotContainsString('mobile=', $output);
    }
}
