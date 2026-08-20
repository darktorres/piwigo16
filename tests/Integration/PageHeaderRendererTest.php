<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use LogicException;
use Override;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\LayoutStateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Only the 2 branches every other real page-render Integration/Browser
 * test happens to leave untouched: header_notes assignment (only reached
 * when LayoutStateTestFactory::get()->headerNotes is non-empty -- FilterService's
 * own "Photos posted within the last N days" note is the one real
 * production populator, per its own docblock) and the metaRef()-disabled
 * noindex/nofollow branch (metaRef defaults to true everywhere else).
 * Every other line prepareContext() touches is already covered
 * indirectly by every real page-load test that reaches this class
 * (Bootstrap\RedirectService::redirectHtml()'s own early-crash
 * fallback, every Browser Controller test, ...).
 *
 * renderHeader() below recreates the deleted render()'s own
 * prepareContext()+parse('header.latte')+finalizeHtml() sequence
 * (P41-E, docs/PLAN.md) -- a real header.latte render, not just the
 * ambient context prepareContext() assigns.
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
        CurrentConfigServiceTestFactory::get()->set(new ConfigService($this->buildConfigRepository(), CurrentConfigTestFactory::get()));
        // header.latte's own {get_combined_css}/{get_combined_scripts
        // load='header'} tags reach ScriptLoader::urlService() -- unset by
        // default, real RequestBootstrap-only wiring this test never boots.
        // header.latte's own {$lang_info['code']}/{$lang_info['direction']} reach
        // Lang::langInfo() -- unset by default, real RequestBootstrap-only
        // wiring this test never boots (same reasoning as SectionInitializerTest/
        // RedirectServiceTest/SectionPopulatorTest's own identical setUp).
        LangTestFactory::get()->setLangInfo([
            'code' => 'en_UK',
            'direction' => 'ltr',
        ]);
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes', 'default'));

        $this->renderer = new PageHeaderRenderer();
    }

    #[Override]
    protected function tearDown(): void
    {
        CurrentTemplateTestFactory::get()->reset();
        LayoutStateTestFactory::get()->reset();
        parent::tearDown();
    }

    /**
     * Recreates the deleted render()'s own
     * prepareContext()+parse('header.latte')+finalizeHtml()
     * sequence (P41-E, docs/PLAN.md) -- a real header.latte render
     * against the ambient context prepareContext() assigns.
     */
    private function renderHeader(string $title): string
    {
        $this->renderer->prepareContext($title, new EventDispatcher(), LayoutStateTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());

        $template = CurrentTemplateTestFactory::get()->get();

        return $template->finalizeHtml($template->parse('header.latte'));
    }

    public function testRenderIncludesTheHeaderNotesWhenLayoutStateHasAny(): void
    {
        LayoutStateTestFactory::get()->addHeaderNote('Photos posted within the last 3 days.');

        $output = $this->renderHeader('Header Notes Test');

        self::assertStringContainsString('Photos posted within the last 3 days.', $output);
    }

    public function testRenderOmitsTheHeaderNotesContainerWhenLayoutStateHasNone(): void
    {
        $output = $this->renderHeader('No Header Notes Test');

        self::assertStringNotContainsString('Photos posted within the last', $output);
    }

    public function testRenderSetsNoindexAndNofollowMetaRobotsWhenMetaRefIsDisabled(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->metaRef = false;

        $output = $this->renderHeader('Meta Robots Test');

        self::assertSame([
            'noindex' => 1,
            'nofollow' => 1,
        ], LayoutStateTestFactory::get()->metaRobots);

        self::assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $output);
    }

    public function testRenderDoesNotSetMetaRobotsWhenMetaRefIsEnabled(): void
    {
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->metaRef = true;

        $this->renderHeader('Meta Ref Enabled Test');

        self::assertSame([], LayoutStateTestFactory::get()->metaRobots);
    }
}
