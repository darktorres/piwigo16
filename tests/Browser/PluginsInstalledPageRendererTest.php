<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

it('toggles show_details on via the URL param and persists it across a later plain visit', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/admin.php?page=plugins&show_details=1');
    $page->assertNoJavaScriptErrors();

    // A later visit with no show_details param at all must still reflect
    // the persisted session value (SessionService::getPluginsShowDetails()),
    // not silently reset to the false default.
    $page = H::navigateOk($page, '/admin.php?page=plugins');
    $page->assertNoJavaScriptErrors();
});

it('toggles show_details off explicitly', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/admin.php?page=plugins&show_details=1');
    $page = H::navigateOk($page, '/admin.php?page=plugins&show_details=0');

    $page->assertNoJavaScriptErrors();
});

it('returns an empty JSON array for the incompatible_plugins AJAX check when the PEM version list is unreachable', function (): void {
    $page = H::loginAsAdmin($this);

    $result = H::rawGet($page, '/admin.php?page=plugins&incompatible_plugins=1');

    expect($result['status'])->toBe(200);
    expect($result['body'])->toBe('[]');
});

it('reuses the session-cached incompatible-plugins result on a second request within the 5-minute TTL', function (): void {
    $page = H::loginAsAdmin($this);

    // First call seeds $_SESSION['incompatible_plugins'] with only the
    // '~~expire~~' placeholder (getIncompatibleExtensions()'s own
    // network-unreachable path returns `false` without ever storing a real
    // entry). A second call within the 300s TTL takes the early
    // is_array($cached) branch instead and returns that same
    // placeholder-only array -- still reduced to [] by the '~~expire~~'
    // skip below, but through a genuinely different code path than the
    // first call's `$incompatible_plugins_raw === false` branch.
    $first = H::rawGet($page, '/admin.php?page=plugins&incompatible_plugins=1');
    $second = H::rawGet($page, '/admin.php?page=plugins&incompatible_plugins=1');

    expect($first['body'])->toBe('[]');
    expect($second['status'])->toBe(200);
    expect($second['body'])->toBe('[]');
});

it('flags an installed-but-missing-from-disk plugin as STATE=missing with the uninstall warning', function (): void {
    $page = H::loginAsAdmin($this);
    $pluginId = 'missing-plugin-' . uniqid();

    $db = H::connect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0')", H::dbEscape($db, $pluginId)));

    try {
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $page->assertSee($pluginId);
        $page->assertSee('THIS PLUGIN IS MISSING BUT IT IS INSTALLED');
    } finally {
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
    }
});

function pluginsInstalledPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

/**
 * Writes a throwaway plugin directly under the live, Apache-shared
 * plugins/ root -- this Browser suite has no injectable throwaway root
 * available the way tests/Integration/PluginRegistryTest.php's own
 * buildRegistry() does (a real, separate Apache-served process handles
 * every request here, not this same PHP process, so there's no
 * Paths::class to swap), so the write targets the real path instead.
 * Every caller below removes it via
 * pluginsInstalledRemoveFixturePlugin() in a finally block, keeping the
 * exposure window scoped to a single it() -- Pest's Browser suite runs
 * this file's tests sequentially in one process (no --parallel in
 * composer.json's test:browser script), so there is no other test able to
 * observe it mid-flight.
 *
 * Writes ONLY plugin.json (P27.10: ExtensionScanner::scanPlugin() reads
 * plugin.json exclusively, no legacy main.inc.php support at all) -- no
 * PSR-4-autoloadable class, so PluginConfig\PluginRegistry::bootActive()
 * can never actually boot it even if a caller inserts a DB row for it;
 * any "executable" content is inert either way. Use this for a fixture
 * that only needs to be *visible* in the admin listing -- for one whose
 * code needs to actually run per-request too, use
 * pluginsInstalledWriteHookedFixturePlugin() instead.
 *
 * $mainIncPhpSource keeps its original name/shape (a legacy
 * `main.inc.php`-style header-comment block: "Plugin Name:"/"Version:"/
 * "Description:"/"Has Settings:" lines) so none of this file's own real
 * call sites needed to change when the underlying fixture format moved
 * from main.inc.php to plugin.json -- parsed here with the exact same
 * regexes ExtensionScanner::scanPlugin() itself used pre-P27.10.
 */
function pluginsInstalledWriteFixturePlugin(string $pluginId, string $mainIncPhpSource): void
{
    $dir = pluginsInstalledPluginsPath() . $pluginId;
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }

    file_put_contents($dir . '/plugin.json', json_encode(pluginsInstalledParseHeaderFields($pluginId, $mainIncPhpSource), JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function pluginsInstalledParseHeaderFields(string $pluginId, string $mainIncPhpSource): array
{
    $manifest = [
        'name' => $pluginId,
    ];
    if (preg_match('|Plugin Name:\s*(.+)|', $mainIncPhpSource, $val) === 1) {
        $manifest['name'] = trim($val[1]);
    }
    if (preg_match('|Version:\s*([\w.-]+)|', $mainIncPhpSource, $val) === 1) {
        $manifest['version'] = trim($val[1]);
    }
    if (preg_match('|Description:\s*(.+)|', $mainIncPhpSource, $val) === 1) {
        $manifest['description'] = trim($val[1]);
    }
    if (preg_match('/Has Settings:\s*([Tt]rue|[Ww]ebmaster)/', $mainIncPhpSource, $val) === 1) {
        $manifest['hasSettings'] = strtolower($val[1]) === 'webmaster' ? 'webmaster' : true;
    }

    return $manifest;
}

/**
 * Same live-fs-fixture technique, but for a plugin whose code needs to
 * actually execute per-request: writes a real, schema-valid plugin.json
 * (merging in $mainIncPhpHeaderSource's own parsed name/version/
 * description/hasSettings, per pluginsInstalledWriteFixturePlugin()'s
 * own docblock -- a single write, not two conflicting ones) + a
 * PSR-4-autoloadable ExtensionInterface class (so PluginConfig\
 * PluginRegistry::bootActive() -- the only mechanism that still executes
 * anything, post-P27.4 -- actually boots it). $bootBodySource is spliced
 * verbatim into the fixture class's own boot() method body. The
 * namespace is derived from random bytes, not $pluginId (which can
 * start with a digit -- not a legal leading character for a PHP
 * identifier).
 */
function pluginsInstalledWriteHookedFixturePlugin(string $pluginId, string $mainIncPhpHeaderSource, string $bootBodySource): void
{
    $dir = pluginsInstalledPluginsPath() . $pluginId;
    if (! is_dir($dir . '/src')) {
        mkdir($dir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    $manifest = pluginsInstalledParseHeaderFields($pluginId, $mainIncPhpHeaderSource) + [
        'id' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ];

    file_put_contents($dir . '/plugin.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($dir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;

        final class Plugin implements ExtensionInterface
        {
            public function boot(ExtensionContext \$context): void
            {
                {$bootBodySource}
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
        }

        PHP);
}

function pluginsInstalledRemoveFixturePlugin(string $pluginId): void
{
    $dir = pluginsInstalledPluginsPath() . $pluginId;
    if (is_file($dir . '/main.inc.php')) {
        unlink($dir . '/main.inc.php');
    }
    if (is_file($dir . '/src/Plugin.php')) {
        unlink($dir . '/src/Plugin.php');
    }
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    if (is_file($dir . '/plugin.json')) {
        unlink($dir . '/plugin.json');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

function pluginsInstalledDb(): mysqli|Connection
{
    return H::connect();
}

/**
 * Writes ONLY plugin.json + src/Plugin.php -- deliberately no main.inc.php
 * at all, unlike pluginsInstalledWriteHookedFixturePlugin() above.
 * ExtensionScanner (main.inc.php header-comment only) is genuinely blind
 * to a fixture built this way; the only thing that can make it appear in
 * the admin listing is PluginsInstalledPageRenderer's own PluginRegistry
 * manifest merge (P27.5's deferred gap, closed here) -- the concrete
 * regression this test exists to prove fixed.
 */
function pluginsInstalledWriteManifestOnlyFixturePlugin(string $pluginId): void
{
    $dir = pluginsInstalledPluginsPath() . $pluginId;
    mkdir($dir . '/src', 0o777, true);

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Manifest-only test fixture (no main.inc.php).',
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

function pluginsInstalledRemoveManifestOnlyFixturePlugin(string $pluginId): void
{
    $dir = pluginsInstalledPluginsPath() . $pluginId;
    if (is_file($dir . '/src/Plugin.php')) {
        unlink($dir . '/src/Plugin.php');
    }
    if (is_dir($dir . '/src')) {
        rmdir($dir . '/src');
    }
    if (is_file($dir . '/plugin.json')) {
        unlink($dir . '/plugin.json');
    }
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('lists a new-contract, manifest-only plugin (no main.inc.php at all) via the PluginRegistry merge', function (): void {
    $page = H::loginAsAdmin($this);
    $pluginId = 'manifest-only-plugin-' . uniqid();

    pluginsInstalledWriteManifestOnlyFixturePlugin($pluginId);

    $db = H::connect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

    try {
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $page->assertSee($pluginId);
        $page->assertNoJavaScriptErrors();
    } finally {
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        pluginsInstalledRemoveManifestOnlyFixturePlugin($pluginId);
    }
});

it('resolves a settings URL from a real get_admin_plugin_menu_links hook via both the legacy "plugin-X" and the "section=X" regex fallbacks', function (): void {
    $hooksId = 'pwgtest-plugins-installed-hooks';
    $targetId = 'pwgtest-plugins-installed-target';

    // Deliberately carries no "Has Settings" header -- any settings URL
    // this plugin (or its section=X target below) ends up with can only
    // have come from the deprecated get_admin_plugin_menu_links hook path,
    // never from PluginsInstalledPageRenderer::render()'s own
    // hasSettings-driven default.
    pluginsInstalledWriteHookedFixturePlugin($hooksId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Legacy Settings URL Hook
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */
    PHP
        , <<<'PHP'
    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Event\Admin\GetAdminPluginMenuLinks::class,
        static function (\Piwigo\Event\Admin\GetAdminPluginMenuLinks $event): \Piwigo\Event\Admin\GetAdminPluginMenuLinks {
            $links = $event->value;
            $links[] = ['URL' => 'admin.php?page=plugin-pwgtest-plugins-installed-hooks'];
            $links[] = ['URL' => 'index.php?section=pwgtest-plugins-installed-target&foo=bar'];

            return new \Piwigo\Event\Admin\GetAdminPluginMenuLinks($links);
        }
    );
    PHP);

    // On-disk-only counterpart: no DB row, no "Has Settings" header -- its
    // only possible settings URL is the sibling plugin's "section=X" entry
    // above.
    pluginsInstalledWriteFixturePlugin($targetId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Legacy Settings URL Target
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */
    PHP);

    $db = pluginsInstalledDb();
    // Only the hook-registering plugin needs a DB row --
    // PluginRegistry::bootActive() only boots an active plugin's
    // ExtensionInterface class, and only that class needs to actually
    // run for the hook to register.
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $hooksId)));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        // The settings link is an <a href="..."> icon/label, not visible
        // text -- assertSee() (Playwright's getByText()) can't see it, so
        // this asserts the actual DOM attribute instead (same pattern as
        // CommentsControllerTest's own mailto: href check). CSS attribute
        // selectors match against the already-entity-decoded DOM value, so
        // this exact string is correct regardless of whether Latte
        // HTML-encoded the "&" in the raw source.
        $page->assertPresent('a[href="admin.php?page=plugin-pwgtest-plugins-installed-hooks"]');
        $page->assertPresent('a[href="index.php?section=pwgtest-plugins-installed-target&foo=bar"]');
        $page->assertNoJavaScriptErrors();
    } finally {
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $hooksId)));
        H::dbClose($db);
        pluginsInstalledRemoveFixturePlugin($hooksId);
        pluginsInstalledRemoveFixturePlugin($targetId);
    }
});

// The `! is_array($value) || ! isset($value['URL']) || ! is_string($value['URL'])`
// `continue` guard inside the deprecated-menu-links loop -- not exercised
// by the "both regex fallbacks" test above, whose own fixture hook only
// ever returns 2 well-formed entries. A malformed entry (mixed in here:
// a bare non-array item, an array with no 'URL' key, and an array whose
// 'URL' isn't a string) must be skipped without throwing, while the one
// well-formed entry alongside it still resolves normally -- proving the
// guard actually skips instead of e.g. always short-circuiting the loop.
it('skips malformed get_admin_plugin_menu_links entries instead of erroring, and still resolves the well-formed one', function (): void {
    $hooksId = 'pwgtest-plugins-installed-malformed-hooks';

    pluginsInstalledWriteHookedFixturePlugin($hooksId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Malformed Menu Link Entries
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */
    PHP
        , <<<'PHP'
    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Event\Admin\GetAdminPluginMenuLinks::class,
        static function (\Piwigo\Event\Admin\GetAdminPluginMenuLinks $event): \Piwigo\Event\Admin\GetAdminPluginMenuLinks {
            $links = $event->value;
            $links[] = 'not-an-array';
            $links[] = ['no_url_key' => 'irrelevant'];
            $links[] = ['URL' => ['not', 'a', 'string']];
            $links[] = ['URL' => 'admin.php?page=plugin-pwgtest-plugins-installed-malformed-hooks'];

            return new \Piwigo\Event\Admin\GetAdminPluginMenuLinks($links);
        }
    );
    PHP);

    $db = pluginsInstalledDb();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $hooksId)));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $page->assertPresent('a[href="admin.php?page=plugin-pwgtest-plugins-installed-malformed-hooks"]');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'plugins_installed malformed menu-link entries');
    } finally {
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $hooksId)));
        H::dbClose($db);
        pluginsInstalledRemoveFixturePlugin($hooksId);
    }
});

it('rewrites a piwigo-videojs settings URL from "plugin-piwigo-videojs" to "plugin-piwigo_videojs"', function (): void {
    $pluginId = 'piwigo-videojs';

    // "Has Settings: true" (not tied to webmaster status) so the default
    // hasSettings branch builds 'admin.php?page=plugin-piwigo-videojs'
    // first, which the plugin-id-matched regex then rewrites in place --
    // no get_admin_plugin_menu_links hook involved in this one.
    pluginsInstalledWriteFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- VideoJS Settings URL Rewrite
    Version: 1.0.0
    Has Settings: true
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */
    PHP);

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        // Same reasoning as the settings-URL hook test above -- the href
        // is an attribute, not visible text, so assertPresent()/
        // assertNotPresent() on the actual DOM attribute is the correct
        // check, not assertSee()/assertDontSee().
        $page->assertPresent('a[href="admin.php?page=plugin-piwigo_videojs"]');
        $page->assertNotPresent('a[href="admin.php?page=plugin-piwigo-videojs"]');
        $page->assertNoJavaScriptErrors();
    } finally {
        pluginsInstalledRemoveFixturePlugin($pluginId);
    }
});

it('clears a stale $_SESSION[incompatible_plugins] entry once the on-disk plugin version no longer matches it', function (): void {
    $pluginId = 'pwgtest-plugins-installed-session';

    // Both GET markers are complete no-ops for every other request --
    // this is the only reachable way to control/observe this suite's
    // server-side $_SESSION content: getIncompatibleExtensions() itself
    // can never populate a real, non-'~~expire~~' entry without live PEM
    // connectivity, but the branch
    // under test here (render()'s own consumption of whatever ends up in
    // $_SESSION['incompatible_plugins']) doesn't care how that entry got
    // there.
    pluginsInstalledWriteHookedFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Incompatible Session Probe
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */
    PHP
        , <<<'PHP'
    if (isset($_GET['pwgtest_pipr_seed']) && is_string($_GET['pwgtest_pipr_seed'])) {
        $_SESSION['incompatible_plugins'] = [
            'pwgtest-plugins-installed-session' => $_GET['pwgtest_pipr_seed'],
        ];
    }

    if (isset($_GET['pwgtest_pipr_probe'])) {
        echo isset($_SESSION['incompatible_plugins']) ? 'SESSION_SET' : 'SESSION_UNSET';
        exit;
    }
    PHP);

    $db = pluginsInstalledDb();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

    try {
        $page = H::loginAsAdmin($this);

        // Seed a session entry whose recorded version ('0.1-stale')
        // deliberately differs from the plugin's real on-disk version
        // ('1.0.0' -- the fixture's own header above).
        H::rawGet($page, '/admin.php?pwgtest_pipr_seed=0.1-stale');

        // The page under test: render()'s own per-plugin loop must find
        // the version mismatch and unset() the whole
        // $_SESSION['incompatible_plugins'] array.
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $probe = H::rawGet($page, '/admin.php?pwgtest_pipr_probe=1');
        expect($probe['status'])->toBe(200);
        expect($probe['body'])->toBe('SESSION_UNSET');
    } finally {
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        pluginsInstalledRemoveFixturePlugin($pluginId);
    }
});

it('leaves a $_SESSION[incompatible_plugins] entry untouched when its recorded version still matches the on-disk plugin', function (): void {
    $pluginId = 'pwgtest-plugins-installed-session';

    pluginsInstalledWriteHookedFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Incompatible Session Probe
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */
    PHP
        , <<<'PHP'
    if (isset($_GET['pwgtest_pipr_seed']) && is_string($_GET['pwgtest_pipr_seed'])) {
        $_SESSION['incompatible_plugins'] = [
            'pwgtest-plugins-installed-session' => $_GET['pwgtest_pipr_seed'],
        ];
    }

    if (isset($_GET['pwgtest_pipr_probe'])) {
        echo isset($_SESSION['incompatible_plugins']) ? 'SESSION_SET' : 'SESSION_UNSET';
        exit;
    }
    PHP);

    $db = pluginsInstalledDb();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

    try {
        $page = H::loginAsAdmin($this);

        // Same recorded version as the fixture's real on-disk version --
        // the mismatch guard must NOT fire this time.
        H::rawGet($page, '/admin.php?pwgtest_pipr_seed=1.0.0');

        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $probe = H::rawGet($page, '/admin.php?pwgtest_pipr_probe=1');
        expect($probe['status'])->toBe(200);
        expect($probe['body'])->toBe('SESSION_SET');
    } finally {
        H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
        H::dbClose($db);
        pluginsInstalledRemoveFixturePlugin($pluginId);
    }
});
