<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Content-asserting smoke tests for a batch of admin pages/controllers that
 * had zero test coverage across every suite: HelpPageRenderer/
 * HelpSubController (?page=help), LanguagesNewPageRenderer (?page=languages
 * &tab=new), PluginsNewPageRenderer (?page=plugins&tab=new),
 * ThemesNewPageRenderer (?page=themes&tab=new, dispatched by
 * ThemesSubController -- NOT the singular ThemeSubController below, a
 * completely different controller for a different page slug),
 * UpdatesExtPageRenderer (?page=updates&tab=ext, and its
 * ?page=plugins&tab=update sibling caller), MaintenanceEnvPageRenderer/
 * MaintenanceSysPageRenderer (?page=maintenance&tab=env/sys),
 * BatchManagerUnitPageRenderer (?page=batch_manager&mode=unit),
 * AdminPopuphelpController (admin/popuphelp.php), RatingUserSubController
 * (?page=rating_user), PluginSubController (?page=plugin), and
 * ThemeSubController (?page=theme&theme=..., the per-theme
 * SettingsPageInterface dispatch -- singular "theme", distinct
 * from the "themes" tab-dispatch shell above; no real caller of the
 * former appeared anywhere in this project's test suite before this
 * file).
 *
 * Unlike AdminExtendedSmokeTest.php's "clean (no errors)" checks, every
 * test here also asserts on real, specific rendered content. The
 * languages/plugins/themes add-new + updates-ext/plugins-update tests
 * below depend on this test environment's real, working local PEM mirrors
 * (PIWIGO_ALT_PLUGINS_PEM_URL/PIWIGO_ALT_THEMES_PEM_URL/
 * PIWIGO_ALT_LANGUAGES_PEM_URL, see RequestBootstrap::pemUrl()'s own
 * docblock) -- all 3 extension types resolve to real, reachable sibling
 * repos here, not AppInfo::DOMAIN's deliberately-unreachable 'ext'
 * fallback. The deterministic "PEM server unreachable" error-handling
 * path itself is covered separately, decoupled from whichever mirror
 * happens to be configured in a given environment -- see
 * tests/Unit/Admin/Extensions/PemCatalogTest.php's own
 * "getServerExtensions returns null when the manifest.json fetch fails"
 * test.
 */
it('help page shows the default Add Photos section and its real translated content', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help');

    $page->assertSee('Add Photos');
    $page->assertSee('Go to Direct Upload');
});

it('help page renders a non-default section when one is requested', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=help&section=permissions');

    $page->assertSee('Permissions on albums');
});

it('languages add-new tab connects to the real mirror and lists a real 17.0.0-compatible language', function (): void {
    // piwigo16-languages' manifest.json was bulk-ported to <code>_17.0.0/
    // entries for all 62 locales on 2026-08-14 (see that repo's own
    // CLAUDE.md, "The one real migration that did need to happen"),
    // matching the plugins/themes mirrors' own already-ported convention
    // -- "There is no other language available." was the real, correct
    // message only before that port existed. af_ZA ("Afrikaans [ZA]") is
    // a stable, always-present entry to assert against, the same way the
    // plugins/themes add-new tests below assert on their own one
    // deterministic real entry ("Language Switch"/"Clear").
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=languages&tab=new');

    $page->assertSee('Add New Language');
    $page->assertSee('Afrikaans');
    $page->assertDontSee('There is no other language available.');
    $page->assertDontSee('Connection to server unavailable.');
});

it('plugins add-new tab connects to the real mirror but currently lists no 17.0.0-compatible plugin', function (): void {
    // piwigo16-plugins' own most recent commit ("revert: remove all 27
    // ported *_17.0.0 plugins, re-port fresh") deleted every entry this
    // test used to assert on (language_switch_17.0.0, the first plugin
    // ever ported to this fork's PluginConfig\ExtensionInterface
    // contract) -- a real, deliberate, in-flight reset of a separate
    // re-porting effort in that repo, not a P48 regression here.
    // PemCatalog::isCompatible() filters every mirror entry by its own
    // declared `piwigo_compat` (confirmed directly:
    // language_switch_16.3.0, the only remaining same-named entry,
    // declares `"piwigo_compat": ["16"]`, not 17), so with zero
    // 17.0.0-declared entries left in the mirror, the tab now
    // genuinely has nothing compatible to list -- this asserts that
    // real, current state (the mirror itself is reachable, matching
    // the languages test's own "There is no other language available."
    // precedent from before *that* mirror was ported) rather than a
    // specific entry name. Revisit once the separate re-porting effort
    // lands a new real 17.0.0-compatible plugin.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=new');

    $page->assertSee('There is no other plugin available.');
    $page->assertDontSee('Connection to server unavailable.');
});

it('themes add-new tab connects to the real mirror but currently lists no 17.0.0-compatible theme', function (): void {
    // Same real, in-flight mirror reset as the plugins test above --
    // piwigo16-themes' own most recent commit removed every ported
    // *_17.0.0 theme (clear_17.0.0 included), and PemCatalog::
    // isCompatible() filters out every remaining (pre-fork-version)
    // entry by its own declared `piwigo_compat`. Revisit once the
    // separate re-porting effort lands a new real 17.0.0-compatible
    // theme.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=themes&tab=new');

    $page->assertSee('Add a new theme');
    $page->assertSee('There is no other theme available.');
    $page->assertDontSee('Connection to server unavailable.');
});

it('theme page rejects a theme id that ExtensionScanner never found on disk', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=theme&theme=zzz_nonexistent_theme');

    expect($page->content())
        ->toContain('Invalid theme');
});

it('theme page reports "no settings page" for a real, scanned theme with none', function (): void {
    // 'default' is a genuine ExtensionScanner-recognized theme in this
    // fixture (themes/default/theme.json exists), so it clears the
    // "theme not found" gate above and reaches ThemeSubController's 2nd
    // real branch: neither bundled theme (default/standard_pages)
    // implements PluginConfig\SettingsPageInterface, so this reaches a
    // real fatalError() call.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=theme&theme=default');

    expect($page->content())
        ->toContain('Theme default has no settings page');
});

/**
 * Covers ThemeSubController's happy-path branch: a real theme whose
 * main class implements both ExtensionInterface and
 * SettingsPageInterface -- neither bundled theme does, and
 * this fork's own Unit-suite ThemeSubControllerTest.php explicitly
 * leaves this branch uncovered ("needs a real theme directory with a
 * marker theme.json on disk, not attempted here"). Uses a
 * throwaway-fixture-under-the-live-root technique -- ThemeRegistry::
 * bootForSettingsPage() never checks "installed" state (see its own
 * docblock), so this never touches the `theme` config row or any DB
 * table, unlike ThemesInstalledPageRendererTest.php's own documented
 * reason for avoiding a live theme directory.
 */
function themeSubThemesPath(): string
{
    return dirname(__DIR__, 2) . '/themes/';
}

function themeSubWriteFixtureTheme(string $themeId, string $namespaceSuffix): string
{
    $dir = themeSubThemesPath() . $themeId;
    mkdir($dir . '/src', 0o777, true);
    mkdir($dir . '/template', 0o777, true);

    $namespace = 'PiwigoTestFixture\\ThemeSettings' . $namespaceSuffix;
    $className = 'Theme' . $namespaceSuffix;
    $templatePath = $dir . '/template/admin.latte';
    file_put_contents($templatePath, 'CT_THEMESUB_INCLUDED');

    file_put_contents($dir . '/theme.json', json_encode([
        'id' => $themeId,
        'name' => $themeId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture theme (tests/Browser/AdminUncoveredPagesSmokeTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\' . $className,
        'hasSettings' => true,
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    // handleSettingsRequest() returns a typed View whose #[Template(...)]
    // attribute names this fixture's own real .latte file by its real,
    // already-known absolute path (P43-D, docs/PLAN.md) -- the same
    // technique Tests/Integration/PluginSettingsPageDispatchTest.php's
    // own writeFixtureSettingsPlugin() already exercises for plugins.
    // This embeds the marker inside the normal admin page chrome (a real
    // DOM descendant of <html>), so a normal page fetch finds it
    // directly.
    $viewClassName = 'ThemeView' . $namespaceSuffix;
    file_put_contents($dir . '/src/' . $viewClassName . '.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\Core\\View;
        use Piwigo\\Template\\Latte\\Attribute\\Template;

        #[Template('{$templatePath}')]
        final readonly class {$viewClassName} implements View
        {
        }

        PHP);

    file_put_contents($dir . '/src/' . $className . '.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use LogicException;
        use Piwigo\\Core\\View;
        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;
        use Piwigo\\PluginConfig\\SettingsPageInterface;
        use Psr\\Http\\Message\\ServerRequestInterface;

        final class {$className} implements ExtensionInterface, SettingsPageInterface
        {
            private ?ExtensionContext \$context = null;

            public function boot(ExtensionContext \$context): void
            {
                \$this->context = \$context;
            }

            public function install(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function uninstall(): void {}
            public function update(string \$oldVersion, string \$newVersion): void {}

            public function subscribedEvents(): array
            {
                return [];
            }

            public function handleSettingsRequest(ServerRequestInterface \$request): View
            {
                if (\$this->context === null) {
                    throw new LogicException('boot() was never called');
                }

                return new {$viewClassName}();
            }
        }

        PHP);

    return $namespace . '\\' . $className;
}

function themeSubRemoveFixtureTheme(string $themeId, string $namespaceSuffix): void
{
    $dir = themeSubThemesPath() . $themeId;
    @unlink($dir . '/src/Theme' . $namespaceSuffix . '.php');
    @unlink($dir . '/src/ThemeView' . $namespaceSuffix . '.php');
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    @unlink($dir . '/template/admin.latte');
    if (is_dir($dir . '/template')) {
        rmdir($dir . '/template');
    }
    @unlink($dir . '/theme.json');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('theme page dispatches to a real SettingsPageInterface theme and renders its content', function (): void {
    $themeId = 'ct-themesub-active-' . uniqid();
    $suffix = str_replace('-', '', $themeId);
    themeSubWriteFixtureTheme($themeId, $suffix);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=theme&theme=' . $themeId);

        expect($page->content())
            ->toContain('CT_THEMESUB_INCLUDED');
    } finally {
        themeSubRemoveFixtureTheme($themeId, $suffix);
    }
});

it('updates ext tab checks every extension type against the real mirrors and finds nothing outdated', function (): void {
    // The fixture has no real 3rd-party plugin/theme/language installed on
    // disk to compare against the (now genuinely reachable) catalogs, so
    // the real, correct outcome is "nothing to update", not a connection
    // failure.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=updates&tab=ext');

    $page->assertSee('Extensions');
    $page->assertSee('All extensions are up to date.');
    $page->assertDontSee('Connection to server unavailable.');
});

it('plugins update tab restricts the shared updates-ext renderer to the plugin type', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=plugins&tab=update');

    // Reached via a different real caller than the ?page=updates&tab=ext
    // test above -- $types_to_check narrows to ExtensionType::Plugin only,
    // and the page's own ADMIN_PAGE_TITLE override applies afterward
    // ("Plugins", not "Updates").
    $page->assertSee('Check for updates');
    $page->assertSee('All plugins are up to date.');
    $page->assertDontSee('Connection to server unavailable.');
    expect($page->content())
        ->toContain('<h1>Plugins');
});

it('maintenance env tab renders real server, database and version info', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=env');

    $page->assertSee('Operating system: Linux');
    $page->assertSee('MySQL:');
    $page->assertSee('PHP:');
});

it('maintenance sys tab server-renders the webmaster-only activity log table, no ajax round-trip', function (): void {
    // The fixture DB's install-time system log (Core install + 2
    // default-theme activations, see tests/Fixtures/piwigo-17.0.sql) is
    // server-rendered directly.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=maintenance&tab=sys');

    $page->assertSee('System Activities');
    $page->assertPresent('#activities-system');
    $page->assertSee('Core');
    $page->assertSee('Install');
    $page->assertNoJavaScriptErrors();
});

it('batch manager unit mode shows the caddie prefilter active by default with an empty caddie', function (): void {
    // Not assertSee('No filter, add one'): BatchManagerSubController::
    // resolveSessionFilter() defaults an unset/empty session filter to
    // `['prefilter' => 'caddie']` (the rendered page shows "Predefined
    // filter: Caddie" / "Empty caddie" / "List 0", not an unfiltered
    // state), so a fresh navigation here
    // ALWAYS has a real, active filter -- the .noFilter div's "No filter,
    // add one" text is genuinely CSS `display:none` by default
    // (themes/admin/default/theme.css) and is only ever toggled visible
    // by JS after the user removes every filter client-side, a state a
    // plain GET request can never reach. batch_manager_filter.inc.latte's
    // own "Empty caddie" link, by contrast, is server-rendered WITHOUT
    // any display:none precisely when $filter.prefilter === 'caddie' (the
    // real default here), so it's the correct, deterministic signal for
    // "the default caddie prefilter is active and empty".
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit');

    expect($page->content())
        ->toContain('<h1>Batch Manager');
    $page->assertSee('Empty caddie');
});

it('batch manager unit mode renders the real per-photo edit grid for a category filter', function (): void {
    // '?filter=cat-1' is BatchManagerSubController::resolveSessionFilter()'s
    // own real URL-filter token shape (parsed by the "url filter" branch,
    // one of the 2 distinct paths -- form POST vs URL token -- that both
    // populate $_SESSION['bulk_manager_filter']), set here to reach
    // BatchManagerUnitPageRenderer's `count($cat_elements_id) > 0` branch:
    // the ~300-line per-image loop (thumbnail/name/tags/linked-album
    // rendering), never exercised by the empty-filter test above.
    // Category 1 ("Sample Album") holds fixture images 1-3 (Photo
    // 1/2/3, files fixture-photo-1..3.jpg) per this fixture's own
    // image_category rows.
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=batch_manager&mode=unit&filter=cat-1');

    $content = $page->content();
    expect($content)
        ->toContain('fixture-photo-1.jpg')
        ->and($content)
        ->toContain('fixture-photo-2.jpg')
        ->and($content)
        ->toContain('fixture-photo-3.jpg')
        // Proves the related-categories JOIN (getCatDisplayNameCache())
        // ran for a real row, not just that 3 filenames happened to print.
        ->and($content)
        ->toContain('Sample Album');
});

it('admin popuphelp renders real help content inside the popup page chrome', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin/popuphelp.php?page=cat_options');

    $page->assertPresent('body#thePopuphelpPage');

    // Not assertSee(): themes/admin/default/theme.css's own global `h2 {
    // ... display: none; }` rule (the
    // heading takes up zero rendered height, "Options management for..."
    // starts right at the top of the viewport) makes this exact heading
    // genuinely CSS-invisible in the real rendered page, so
    // isVisible()-gated assertSee() fails deterministically (100%
    // reproducible in isolation, unrelated to system load) even though
    // the correct help content is really present in the DOM. A
    // content() check verifies the real, intended thing (the correct
    // help topic loaded) without depending on this unrelated theme quirk.
    expect($page->content())
        ->toContain('<h2>Album options</h2>');
});

it('admin popuphelp content_only output returns the bare help fragment with no page chrome', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin/popuphelp.php?page=cat_options&output=content_only');

    $content = $page->content();
    expect($content)
        ->toContain('<h2>Album options</h2>')
        // Not ->not->toContain('<body'): Playwright's content() serializes
        // the browser's PARSED DOM, not the raw HTTP response bytes --
        // Chromium always normalizes ANY top-level navigation (even to a
        // body-less fragment) into a full <html><head></head><body>...
        // document, so this page ALWAYS shows a <body> tag in content()
        // regardless of what AdminPopuphelpController actually returned
        // (the real raw response is the bare fragment with no
        // <html>/<body> at all). 'thePopuphelpPage' is the real,
        // source-level distinguishing signal instead: the controller only
        // sets that body id -- via $this->layoutState->setBodyId(...)
        // ahead of the full PageHeaderRenderer/PageTail chrome -- on the
        // non-content_only branch, so its absence here proves the "no
        // page chrome" claim this test is actually named for.
        ->and($content)
        ->not->toContain('thePopuphelpPage');
});

it('admin popuphelp rejects a page parameter with invalid characters', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin/popuphelp.php?page=INVALID');

    expect($page->content())
        ->toContain('Request rejected: invalid page parameter');
});

function pluginSubDb(): mysqli|Connection
{
    return H::connect();
}

function pluginSubPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

/**
 * `PluginSubController::handle()` checks `Admin\LoadedPlugins`,
 * populated from `PluginConfig\PluginRegistry::getActiveIds()`/
 * `getManifest()`, which skips any active DB row with no valid
 * `plugin.json` -- see `PluginRegistry`'s own
 * `if ($manifest === null) { continue; }` guards. A bare `main.inc.php`
 * placeholder does not make a plugin
 * "active" for its purposes -- a real manifest + PSR-4 class is required
 * even though neither call site below needs any actual boot() behavior.
 * Deliberately does NOT implement SettingsPageInterface -- reused as-is
 * by the "no settings page" test below; the happy-path test uses its own
 * separate writer (pluginSubWriteFixtureSettingsPlugin()) instead.
 */
function pluginSubWriteFixturePlugin(string $pluginId): void
{
    $dir = pluginSubPluginsPath() . $pluginId;
    if (! is_dir($dir . '/src')) {
        mkdir($dir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/AdminUncoveredPagesSmokeTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($dir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;

        final class Plugin implements ExtensionInterface
        {
            public function boot(ExtensionContext \$context): void {}

            public function install(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function uninstall(): void {}
            public function update(string \$oldVersion, string \$newVersion): void {}

            public function subscribedEvents(): array
            {
                return [];
            }
        }

        PHP);
}

function pluginSubRemoveFixturePlugin(string $pluginId): void
{
    $dir = pluginSubPluginsPath() . $pluginId;
    if (file_exists($dir . '/src/Plugin.php')) {
        unlink($dir . '/src/Plugin.php');
    }
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    if (file_exists($dir . '/plugin.json')) {
        unlink($dir . '/plugin.json');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

/**
 * Covers PluginSubController's happy-path branch: a real, active plugin
 * whose main class implements both ExtensionInterface and
 * SettingsPageInterface -- this fork's own Unit-suite
 * PluginSubControllerTest.php explicitly leaves this branch uncovered
 * ("needs a real, booted plugin instance, not attempted here"), and
 * Tests/Integration/PluginSettingsPageDispatchTest.php covers the
 * controller layer directly (bypassing a real HTTP request/admin.php
 * dispatch, which this Browser-level test adds). Same technique that
 * Integration test's own writeFixtureSettingsPlugin() uses:
 * handleSettingsRequest() returns a typed View whose #[Template(...)]
 * attribute names this fixture's own real .latte file by its real,
 * already-known absolute path (P43-D, docs/PLAN.md).
 */
function pluginSubWriteFixtureSettingsPlugin(string $pluginId, string $namespaceSuffix): void
{
    $dir = pluginSubPluginsPath() . $pluginId;
    mkdir($dir . '/src', 0o777, true);
    mkdir($dir . '/template', 0o777, true);

    $namespace = 'PiwigoTestFixture\\PluginSettings' . $namespaceSuffix;
    $className = 'Plugin' . $namespaceSuffix;
    $viewClassName = 'PluginView' . $namespaceSuffix;
    $templatePath = $dir . '/template/admin.latte';
    file_put_contents($templatePath, 'CT_PLUGINSUB_INCLUDED');

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture settings plugin (tests/Browser/AdminUncoveredPagesSmokeTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\' . $className,
        'hasSettings' => true,
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($dir . '/src/' . $viewClassName . '.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\Core\\View;
        use Piwigo\\Template\\Latte\\Attribute\\Template;

        #[Template('{$templatePath}')]
        final readonly class {$viewClassName} implements View
        {
        }

        PHP);

    file_put_contents($dir . '/src/' . $className . '.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use LogicException;
        use Piwigo\\Core\\View;
        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;
        use Piwigo\\PluginConfig\\SettingsPageInterface;
        use Psr\\Http\\Message\\ServerRequestInterface;

        final class {$className} implements ExtensionInterface, SettingsPageInterface
        {
            private ?ExtensionContext \$context = null;

            public function boot(ExtensionContext \$context): void
            {
                \$this->context = \$context;
            }

            public function install(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function uninstall(): void {}
            public function update(string \$oldVersion, string \$newVersion): void {}

            public function subscribedEvents(): array
            {
                return [];
            }

            public function handleSettingsRequest(ServerRequestInterface \$request): View
            {
                if (\$this->context === null) {
                    throw new LogicException('boot() was never called');
                }

                return new {$viewClassName}();
            }
        }

        PHP);
}

function pluginSubRemoveFixtureSettingsPlugin(string $pluginId, string $namespaceSuffix): void
{
    $dir = pluginSubPluginsPath() . $pluginId;
    @unlink($dir . '/src/Plugin' . $namespaceSuffix . '.php');
    @unlink($dir . '/src/PluginView' . $namespaceSuffix . '.php');
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    @unlink($dir . '/template/admin.latte');
    if (is_dir($dir . '/template')) {
        rmdir($dir . '/template');
    }
    @unlink($dir . '/plugin.json');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('plugin page dispatches to a real SettingsPageInterface plugin and renders its content', function (): void {
    // AdminShellRequest::fromArrays()'s own 'section' validation (top-level,
    // ahead of PluginSectionRequest's plugin-specific check) is a faithful
    // port of legacy Piwigo's own /^[a-z]+[a-z_\/-]*(\.php)?$/i -- no
    // digits anywhere in the value. A bare uniqid() plugin id would trip
    // it (real 401/hacking-attempt page), so the digits
    // get mapped to letters here instead of dropping uniqueness.
    $pluginId = 'ct-pluginsub-active-' . strtr(uniqid(), '0123456789', 'abcdefghij');
    $suffix = str_replace('-', '', $pluginId);
    pluginSubWriteFixtureSettingsPlugin($pluginId, $suffix);
    $db = pluginSubDb();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0')", H::dbEscape($db, $pluginId)));
    H::dbClose($db);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugin&section=' . $pluginId);

        expect($page->content())
            ->toContain('CT_PLUGINSUB_INCLUDED');
    } finally {
        $db = pluginSubDb();
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        pluginSubRemoveFixtureSettingsPlugin($pluginId, $suffix);
    }
});

it('plugin page reports "no settings page" for a real active plugin with none', function (): void {
    // Same reasoning as the plugin-page-dispatches test above -- 'section'
    // must stay digit-free.
    $pluginId = 'ct-pluginsub-missing-' . strtr(uniqid(), '0123456789', 'abcdefghij');
    pluginSubWriteFixturePlugin($pluginId);
    $db = pluginSubDb();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0')", H::dbEscape($db, $pluginId)));
    H::dbClose($db);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugin&section=' . $pluginId);

        expect($page->content())
            ->toContain('Plugin ' . $pluginId . ' has no settings page');
    } finally {
        $db = pluginSubDb();
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        pluginSubRemoveFixturePlugin($pluginId);
    }
});
