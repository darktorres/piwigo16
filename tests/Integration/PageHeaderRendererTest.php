<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use LogicException;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Template\CurrentTemplate;

/**
 * Only the 2 branches every other real page-render Integration/Browser
 * test happens to leave untouched: header_notes assignment (only reached
 * when PageStateTestFactory::get()->headerNotes is non-empty -- FilterService's
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
        // header.tpl's own {get_combined_css}/{get_combined_scripts
        // load='header'} tags reach ScriptLoader::urlService() -- unset by
        // default, real RequestBootstrap-only wiring this test never boots.
        // header.tpl's own {$lang_info.code}/{$lang_info.direction} reach
        // Lang::langInfo() -- unset by default, real RequestBootstrap-only
        // wiring this test never boots (same reasoning as SectionInitializerTest/
        // RedirectServiceTest/SectionPopulatorTest's own identical setUp).
        LangTestFactory::get()->setLangInfo(['code' => 'en_UK', 'direction' => 'ltr']);
        CurrentTemplate::current()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));

        $this->renderer = new PageHeaderRenderer();
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentTemplate::current()->reset();
        PageStateTestFactory::get()->reset();
        parent::tearDown();
    }

    public function test_render_includes_the_header_notes_when_page_state_has_any(): void
    {
        PageStateTestFactory::get()->addHeaderNote('Photos posted within the last 3 days.');

        $this->renderer->render('Header Notes Test', new EventDispatcher(), PageStateTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get());

        $output = CurrentTemplate::current()->get()->fetchOutput();
        self::assertStringContainsString('Photos posted within the last 3 days.', $output);
    }

    public function test_render_omits_the_header_notes_container_when_page_state_has_none(): void
    {
        $this->renderer->render('No Header Notes Test', new EventDispatcher(), PageStateTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get());

        $output = CurrentTemplate::current()->get()->fetchOutput();
        self::assertStringNotContainsString('Photos posted within the last', $output);
    }

    public function test_render_sets_noindex_and_nofollow_meta_robots_when_meta_ref_is_disabled(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setMetaRef(false);

        $this->renderer->render('Meta Robots Test', new EventDispatcher(), PageStateTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get());

        self::assertSame(['noindex' => 1, 'nofollow' => 1], PageStateTestFactory::get()->metaRobots);

        $output = CurrentTemplate::current()->get()->fetchOutput();
        self::assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $output);
    }

    public function test_render_does_not_set_meta_robots_when_meta_ref_is_enabled(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->setMetaRef(true);

        $this->renderer->render('Meta Ref Enabled Test', new EventDispatcher(), PageStateTestFactory::get(), CurrentTemplate::current(), CurrentConfigTestFactory::get());

        self::assertSame([], PageStateTestFactory::get()->metaRobots);
    }
}
