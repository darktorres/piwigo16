<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Admin;

use LogicException;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Tests\Support\CurrentConfigServiceTestFactory;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserService;

/**
 * Piwigo\Admin\ThemesInstalledPageRenderer::render() -- see
 * tests/Browser/ThemesInstalledPageRendererTest.php's own docblock for why
 * its own per-theme foreach body (the real `$tpl_themes[] =
 * $this->buildTplTheme(...)` call, ~L117) is unreachable there: this test
 * environment's real, Apache-shared themes/ tree has only 'default' on
 * disk, and render()'s own loop unconditionally `continue`s past
 * 'default'/'standard_pages' (~L114) before ever reaching that call --
 * writing a second real theme under the live themes/ root was ruled out of
 * scope there for its blast radius on concurrently running Browser tests.
 * tests/Unit/Admin/ThemesInstalledPageRendererTest.php closes the *body* of
 * buildTplTheme() itself via direct reflection, but that bypasses render()'s
 * own foreach entirely, so line 117 itself (the call site) stays uncovered
 * even after that file.
 *
 * This closes it the same way ThemesStandardPagesPageRendererTest.php's own
 * "test_standard_pages_used_by_accumulates_only_the_real_theme..." test
 * solves an identical constraint for a sibling renderer:
 * CurrentConfig::setThemesDir() redirected to a disposable
 * sys_get_temp_dir() root (not the live, git-tracked themes/ tree), with a
 * real 2nd theme written under it -- CurrentPaths itself stays pointed at
 * the real repo root throughout, so Template can still find the real
 * themes/admin/default/template/themes_installed.latte.
 */
final class ThemesInstalledPageRendererTest extends IntegrationTestCase
{
    private ConfigService $configService;

    private ThemesInstalledPageRenderer $renderer;

    private string $fixtureRoot;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        // render() reaches CoreDomainAccessor::userService() (via
        // getDefaultTheme()), which needs the DI container -- boot() is
        // idempotent, safe regardless of what ran before it in this shared
        // process (same reasoning as CategoryAdminServiceTest's own setUp()).
        Kernel::boot();

        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'webmaster',
            'username' => 'fixture_admin',
        ]));

        EventDispatcherTestFactory::get()->reset();

        $this->configService = new ConfigService($this->buildConfigRepository(), new EventDispatcher(), CurrentConfigTestFactory::get());
        // Template::__construct()'s own data_dir_checked first-time-setup
        // flow reaches CurrentConfigServiceTestFactory::get()->get() -- same wiring every
        // other Integration test constructing a real Template directly
        // does (e.g. ThemesStandardPagesPageRendererTest's own setUp()).
        CurrentConfigServiceTestFactory::get()->set($this->configService);
        CurrentTemplateTestFactory::get()->set(TemplateTestFactory::build(CurrentPathsTestFactory::get()->root . 'themes/admin', 'default'));

        $urlService = UrlServiceTestFactory::build();
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        $currentLogger->set(new Logger([
            'severity' => Logger::OFF,
        ]));
        $activityService = Kernel::container()->get(ActivityService::class);
        if (! $activityService instanceof ActivityService) {
            throw new LogicException('Container returned an unexpected type for ' . ActivityService::class);
        }
        $userService = Kernel::container()->get(UserService::class);
        if (! $userService instanceof UserService) {
            throw new LogicException('Container returned an unexpected type for ' . UserService::class);
        }
        $accessControl = Kernel::container()->get(AccessControl::class);
        if (! $accessControl instanceof AccessControl) {
            throw new LogicException('Container returned an unexpected type for ' . AccessControl::class);
        }
        $pluginRegistry = Kernel::container()->get(PluginRegistry::class);
        if (! $pluginRegistry instanceof PluginRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . PluginRegistry::class);
        }
        $themeRegistry = Kernel::container()->get(ThemeRegistry::class);
        if (! $themeRegistry instanceof ThemeRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . ThemeRegistry::class);
        }
        $this->renderer = new ThemesInstalledPageRenderer(LangTestFactory::get(), $accessControl, new RedirectService(LangTestFactory::get(), $userService, new EventDispatcher(), PageStateTestFactory::get()), $urlService, $this->configService, $currentLogger, new EventDispatcher(), PageStateTestFactory::get(), CurrentTemplateTestFactory::get(), $activityService, $userService, HtmlServiceTestFactory::build(), CurrentConfigTestFactory::get(), CurrentUserTestFactory::get(), CurrentPathsTestFactory::get(), $pluginRegistry, $themeRegistry);

        $this->fixtureRoot = sys_get_temp_dir() . '/piwigo-themes-installed-integration-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->fixtureRoot . 'themes', 0o777, true);
        $currentConfig->themesDir = rtrim($this->fixtureRoot, '/') . '/themes';

        $_GET = [];
    }

    #[Override]
    protected function tearDown(): void
    {
        $_GET = [];
        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        CurrentTemplateTestFactory::get()->reset();
        EventDispatcherTestFactory::get()->reset();
        Kernel::reset();
        if (is_dir($this->fixtureRoot)) {
            FilesystemHelper::deltree($this->fixtureRoot);
        }
        parent::tearDown();
    }

    private function writeFixtureTheme(string $id, string $name): void
    {
        $dir = $this->fixtureRoot . 'themes/' . $id;
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', "<?php\n/*\nTheme Name: {$name}\nVersion: 1.0\n*/\n");
        // Avoids ExtensionScanner::scanTheme()'s hidden PreferencesService
        // DB fallback branch for a missing screenshot.png -- same reasoning
        // as tests/Unit/Admin/ThemesInstalledPageRendererTest.php's own
        // writeThemesInstalledFixtureTheme().
        file_put_contents($dir . '/screenshot.png', 'x');
    }

    public function testRenderSkipsDefaultAndBuildsARealRowForAGenuineExtraTheme(): void
    {
        $this->writeFixtureTheme('default', 'Default');
        $this->writeFixtureTheme('pwgtest-extra-theme', 'PwgTest Extra Theme');

        $this->renderer->render('themes');

        $tplThemes = CurrentTemplateTestFactory::get()->get()->getTemplateVars('tpl_themes');
        self::assertIsArray($tplThemes);

        $ids = array_map(
            static fn (mixed $row): mixed => is_array($row) ? ($row['ID'] ?? null) : null,
            $tplThemes
        );
        // The real gap: a genuine non-default/standard_pages theme reaches
        // render()'s own buildTplTheme() call and appears in the output --
        // 'default' itself must NOT (the `continue` a few lines above it).
        self::assertContains('pwgtest-extra-theme', $ids);
        self::assertNotContains('default', $ids);

        $names = array_map(
            static fn (mixed $row): mixed => is_array($row) ? ($row['NAME'] ?? null) : null,
            $tplThemes
        );
        self::assertContains('PwgTest Extra Theme', $names);
    }
}
