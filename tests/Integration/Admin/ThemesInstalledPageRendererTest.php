<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Admin;

use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Tests\Integration\IntegrationTestCase;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

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
 * themes/admin/default/template/themes_installed.tpl.
 */
final class ThemesInstalledPageRendererTest extends IntegrationTestCase
{
    private ConfigService $configService;

    private ThemesInstalledPageRenderer $renderer;

    private string $fixtureRoot;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        // render() reaches CoreDomainAccessor::userService() (via
        // getDefaultTheme()), which needs the DI container -- boot() is
        // idempotent, safe regardless of what ran before it in this shared
        // process (same reasoning as CategoryAdminServiceTest's own setUp()).
        Kernel::boot();

        CurrentUser::current()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'webmaster',
            'username' => 'fixture_admin',
        ]));

        EventDispatcher::get()->reset();

        $this->configService = new ConfigService($this->buildConfigRepository(), new \Piwigo\PluginConfig\EventDispatcher());
        // Template::__construct()'s own data_dir_checked first-time-setup
        // flow reaches CurrentConfigService::current()->get() -- same wiring every
        // other Integration test constructing a real Template directly
        // does (e.g. ThemesStandardPagesPageRendererTest's own setUp()).
        CurrentConfigService::current()->set($this->configService);
        CurrentTemplate::current()->set(new Template(CurrentPaths::get()->root . 'themes/admin', 'default'));

        $urlService = new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride());
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        $currentLogger->set(new Logger(['severity' => Logger::OFF]));
        $activityService = Kernel::container()->get(\Piwigo\Activity\ActivityService::class);
        if (! $activityService instanceof \Piwigo\Activity\ActivityService) {
            throw new \LogicException('Container returned an unexpected type for ' . \Piwigo\Activity\ActivityService::class);
        }
        $this->renderer = new ThemesInstalledPageRenderer(new RedirectService(), $urlService, $this->configService, $currentLogger, new \Piwigo\PluginConfig\EventDispatcher(), \Piwigo\Core\PageState::current(), CurrentTemplate::current(), $activityService);

        $this->fixtureRoot = sys_get_temp_dir() . '/piwigo-themes-installed-integration-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->fixtureRoot . 'themes', 0o777, true);
        CurrentConfig::setThemesDir(rtrim($this->fixtureRoot, '/') . '/themes');

        $_GET = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        $_GET = [];
        CurrentConfig::reset();
        CurrentTemplate::current()->reset();
        EventDispatcher::get()->reset();
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

    public function test_render_skips_default_and_builds_a_real_row_for_a_genuine_extra_theme(): void
    {
        $this->writeFixtureTheme('default', 'Default');
        $this->writeFixtureTheme('pwgtest-extra-theme', 'PwgTest Extra Theme');

        $this->renderer->render('themes');

        $tplThemes = CurrentTemplate::current()->get()->get_template_vars('tpl_themes');
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
