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
use Piwigo\Tests\Support\AdHocPageContext;
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

    private ?string $tplDir = null;

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
        // load='header'} tags reach Template::urlService() -- unset by
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
        if ($this->tplDir !== null && is_dir($this->tplDir)) {
            unlink($this->tplDir . 'page-header-test.latte');
            rmdir($this->tplDir);
        }

        parent::tearDown();
    }

    /**
     * Recreates the deleted render()'s own
     * prepareContext()+parse('header.latte')+finalizeHtml()
     * sequence (P41-E, docs/PLAN.md) -- a real header.latte render
     * against the ambient context prepareContext() assigns.
     *
     * `header.latte` itself was deleted in P41-G/H (its content merged
     * into `layout.latte`'s own `{layout}/{block}` shell, docs/PLAN.md's
     * P42) -- there is no longer a standalone file to `parse()` in
     * isolation. A tiny fixture file extending `layout.latte` with an
     * empty content block reaches the exact same `<head>`/header markup
     * this test's own assertions check for, via the current architecture
     * instead of the deleted one. `{layout $layoutPath}` (a dynamic
     * expression, not the usual bare string literal) is required here --
     * Latte's own `{layout}` tag resolves a literal path relative to the
     * *including file's own directory*, not through this app's
     * `TemplateLocator` search chain the way `{include 'bare.latte'}`
     * does, so a fixture file living outside `themes/default/template/`
     * needs the real absolute path handed in as a variable instead.
     */
    private function renderHeader(string $title): string
    {
        $this->renderer->prepareContext($title, new EventDispatcher(), LayoutStateTestFactory::get(), CurrentTemplateTestFactory::get(), CurrentConfigTestFactory::get());

        $template = CurrentTemplateTestFactory::get()->get();
        $this->tplDir = sys_get_temp_dir() . '/piwigo-page-header-renderer-test-' . bin2hex(random_bytes(8)) . '/';
        mkdir($this->tplDir, 0o777, true);
        file_put_contents($this->tplDir . 'page-header-test.latte', "{layout \$layoutPath}\n{block content}{/block}\n");
        $template->setTemplateDir($this->tplDir);
        $template->assignContext(new AdHocPageContext([
            'layoutPath' => dirname(__DIR__, 2) . '/themes/default/template/layout.latte',
            // layout.latte's own footer section (never this test's own
            // concern -- see PageTailRendererTest for that) still renders
            // as part of the full page, and needs these to avoid an
            // undefined-variable warning; real values are irrelevant.
            'APP_URL' => 'https://example.invalid',
            'VERSION' => '0',
        ]));

        return $template->finalizeHtml($template->parse('page-header-test.latte'));
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
