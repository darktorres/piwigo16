<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;

/**
 * Only the 2 branches every other real page-render Integration/Browser
 * test happens to leave untouched: header_notes assignment (only reached
 * when PageState::current()->headerNotes is non-empty -- FilterService's
 * own "Photos posted within the last N days" note is the one real
 * production populator, per its own docblock) and the metaRef()-disabled
 * noindex/nofollow branch (metaRef defaults to true everywhere else).
 * Every other line render() touches is already covered indirectly by
 * every real page-load test that reaches this class (Bootstrap\
 * RedirectService::redirectHtml()'s own early-crash fallback, every
 * Browser Controller test, ...).
 */
final class PageHeaderRendererTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private PageHeaderRenderer $renderer;

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
        CurrentConfigService::set(new ConfigService($this->buildConfigRepository()));
        // header.tpl's own {get_combined_css}/{get_combined_scripts
        // load='header'} tags reach ScriptLoader::urlService() -- unset by
        // default, real RequestBootstrap-only wiring this test never boots.
        ScriptLoader::setUrlService(new UrlService(new HtmlService()));
        // header.tpl's own {$lang_info.code}/{$lang_info.direction} reach
        // Lang::langInfo() -- unset by default, real RequestBootstrap-only
        // wiring this test never boots (same reasoning as SectionInitializerTest/
        // RedirectServiceTest/SectionPopulatorTest's own identical setUp).
        Lang::setLangInfo(['code' => 'en_UK', 'direction' => 'ltr']);
        CurrentTemplate::set(new Template(CurrentPaths::get()->root . 'themes', 'default'));

        $this->renderer = new PageHeaderRenderer();
    }

    #[\Override]
    protected function tearDown(): void
    {
        CurrentTemplate::reset();
        PageState::reset();
        parent::tearDown();
    }

    public function test_render_includes_the_header_notes_when_page_state_has_any(): void
    {
        PageState::current()->addHeaderNote('Photos posted within the last 3 days.');

        $this->renderer->render('Header Notes Test');

        $output = CurrentTemplate::get()->fetchOutput();
        self::assertStringContainsString('Photos posted within the last 3 days.', $output);
    }

    public function test_render_omits_the_header_notes_container_when_page_state_has_none(): void
    {
        $this->renderer->render('No Header Notes Test');

        $output = CurrentTemplate::get()->fetchOutput();
        self::assertStringNotContainsString('Photos posted within the last', $output);
    }

    public function test_render_sets_noindex_and_nofollow_meta_robots_when_meta_ref_is_disabled(): void
    {
        CurrentConfig::setMetaRef(false);

        $this->renderer->render('Meta Robots Test');

        self::assertSame(['noindex' => 1, 'nofollow' => 1], PageState::current()->metaRobots);

        $output = CurrentTemplate::get()->fetchOutput();
        self::assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $output);
    }

    public function test_render_does_not_set_meta_robots_when_meta_ref_is_enabled(): void
    {
        CurrentConfig::setMetaRef(true);

        $this->renderer->render('Meta Ref Enabled Test');

        self::assertSame([], PageState::current()->metaRobots);
    }
}
