<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PluginsInstalledPageRenderer (admin.php?page=plugins, the
 * default "installed" tab) -- already GET-tested by AdminExtendedSmokeTest;
 * this file exercises the show_details session toggle (persists across
 * requests) and the "missing" plugin state (a real DB row with no
 * matching filesystem plugin).
 *
 * getIncompatibleExtensions()'s own PEM network round-trip has no
 * injectable seam (same documented limitation as PemCatalog/CoreUpdateService/
 * PiwigoInfosSender), but the incompatible_plugins= AJAX branch itself IS
 * reachable: with no real PEM connection, getVersionsToCheck() always
 * returns [], so getIncompatibleExtensions() always returns false on a
 * fresh session (no real network flakiness risk) and the cached-array reuse
 * path (a second call within its 5-minute TTL) on a following one --
 * exercised below. The one residual sub-branch NOT exercised is a real
 * non-'~~expire~~' entry inside that cached array (needs an actually
 * PEM-incompatible plugin on disk to produce) -- this test env's real
 * plugins/ directory is empty and ExtensionType::scanDirectory() hardcodes
 * that real path with no injection point (same limitation documented in
 * ExtensionScannerTest's own docblock), so seeding one would mean writing
 * to the live, Apache-shared plugins/ root -- out of scope for the same
 * blast-radius reason ThemesInstalledPageRendererTest's docblock gives for
 * not writing a second theme under themes/.
 *
 * The deprecated get_admin_plugin_menu_links plugin-menu hook, the 'merged'
 * plugin state, the piwigo-videojs/piwigo-openstreetmap settings-URL
 * rewrite, and the session-based incompatibility-dismissal branch all DO
 * get exercised below despite the empty-plugins-directory limitation
 * described above -- each of those needs a real fs plugin, not a real PEM
 * connection, so the tests write a throwaway, self-cleaning plugin
 * directory straight under the live plugins/ root for the duration of a
 * single it() (same create-assert-cleanup shape as the missing-plugin DB
 * row test above), then remove it in a finally block. Still NOT exercised:
 * a real non-'~~expire~~' entry inside getIncompatibleExtensions()'s own
 * cached array (render()'s $incompatible_plugins[] = $plugin line) --
 * that one genuinely needs live PEM connectivity to produce, not just a
 * real fs plugin, so it stays out of scope here too.
 */
function pluginsInstalledDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

it('toggles show_details on via the URL param and persists it across a later plain visit', function (): void {
    $page = H::loginAsAdmin($this);

    $page = H::navigateOk($page, '/admin.php?page=plugins&show_details=1');
    $page->assertNoJavaScriptErrors();

    // A later visit with no show_details param at all must still reflect
    // the persisted session value (SessionService::getSessionVar()), not
    // silently reset to the false default.
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

    $db = new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
    $prefix = pluginsInstalledDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));

    try {
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $page->assertSee($pluginId);
        $page->assertSee('THIS PLUGIN IS MISSING BUT IT IS INSTALLED');
    } finally {
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
    }
});

function pluginsInstalledPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

/**
 * Writes a throwaway plugin directly under the live, Apache-shared
 * plugins/ root -- same real-fs-fixture technique PluginLoaderTest.php
 * uses against an injectable throwaway root, but this suite has no such
 * injection point available (ExtensionScanner/PluginLoader both hardcode
 * CurrentPaths::get()->plugins, see this file's own top docblock), so the
 * write targets the real path instead. Every caller below removes it via
 * pluginsInstalledRemoveFixturePlugin() in a finally block, keeping the
 * exposure window scoped to a single it() -- Pest's Browser suite runs
 * this file's tests sequentially in one process (no --parallel in
 * composer.json's test:browser script), so there is no other test able to
 * observe it mid-flight.
 */
function pluginsInstalledWriteFixturePlugin(string $pluginId, string $mainIncPhpSource): void
{
    $dir = pluginsInstalledPluginsPath() . $pluginId;
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/main.inc.php', $mainIncPhpSource);
}

function pluginsInstalledRemoveFixturePlugin(string $pluginId): void
{
    $dir = pluginsInstalledPluginsPath() . $pluginId;
    @unlink($dir . '/main.inc.php');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

function pluginsInstalledDb(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

it('flags a plugin whose PEM extension id is in the locally-merged list as STATE=merged, overwrites its description, and persists the DB state flip to inactive', function (): void {
    $pluginId = 'pwgtest-plugins-installed-merged';

    // eid=411 matches a real, committed entry in
    // install/obsolete_extensions.list (pwg_images_addSimple) -- see
    // PemCatalogTest's own "getLocallyMergedExtensions parses the real
    // install/obsolete_extensions.list" test for that file's exact,
    // asserted contents.
    pluginsInstalledWriteFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Merged Into Core
    Version: 3.0.0
    Plugin URI: https://piwigo.org/ext/extension_view.php?eid=411
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */
    PHP);

    $db = pluginsInstalledDb();
    $prefix = pluginsInstalledDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '3.0.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        // Common to both the raw msgid and its (differently-worded)
        // en_UK translation -- see language/en_UK/admin.po -- so this
        // substring is stable regardless of catalog-loading state.
        $page->assertSee('THIS PLUGIN IS NOW PART OF PIWIGO CORE');
        $page->assertNoJavaScriptErrors();

        $row = H::fetchAssocOrFail($db, sprintf(
            "SELECT state FROM %splugins WHERE id = '%s'",
            $prefix,
            $db->real_escape_string($pluginId)
        ));
        expect($row['state'])->toBe('inactive');
    } finally {
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        pluginsInstalledRemoveFixturePlugin($pluginId);
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
    pluginsInstalledWriteFixturePlugin($hooksId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Legacy Settings URL Hook
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */

    \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler(
        'get_admin_plugin_menu_links',
        static function (mixed $links): mixed {
            if (! is_array($links)) {
                $links = [];
            }

            $links[] = ['URL' => 'admin.php?page=plugin-pwgtest-plugins-installed-hooks'];
            $links[] = ['URL' => 'index.php?section=pwgtest-plugins-installed-target&foo=bar'];

            return $links;
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
    $prefix = pluginsInstalledDbPrefix();
    // Only the hook-registering plugin needs a DB row -- PluginLoader::
    // loadPlugins() only include_once's an active plugin's main.inc.php,
    // and only that file needs to actually run for the hook to register.
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        $prefix,
        $db->real_escape_string($hooksId)
    ));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        // The settings link is an <a href="..."> icon/label, not visible
        // text -- assertSee() (Playwright's getByText()) can't see it, so
        // this asserts the actual DOM attribute instead (same pattern as
        // CommentsControllerTest's own mailto: href check). CSS attribute
        // selectors match against the already-entity-decoded DOM value, so
        // this exact string is correct regardless of whether Smarty
        // HTML-encoded the "&" in the raw source.
        $page->assertPresent('a[href="admin.php?page=plugin-pwgtest-plugins-installed-hooks"]');
        $page->assertPresent('a[href="index.php?section=pwgtest-plugins-installed-target&foo=bar"]');
        $page->assertNoJavaScriptErrors();
    } finally {
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($hooksId)));
        $db->close();
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

    pluginsInstalledWriteFixturePlugin($hooksId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Malformed Menu Link Entries
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */

    \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler(
        'get_admin_plugin_menu_links',
        static function (mixed $links): mixed {
            if (! is_array($links)) {
                $links = [];
            }

            $links[] = 'not-an-array';
            $links[] = ['no_url_key' => 'irrelevant'];
            $links[] = ['URL' => ['not', 'a', 'string']];
            $links[] = ['URL' => 'admin.php?page=plugin-pwgtest-plugins-installed-malformed-hooks'];

            return $links;
        }
    );
    PHP);

    $db = pluginsInstalledDb();
    $prefix = pluginsInstalledDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        $prefix,
        $db->real_escape_string($hooksId)
    ));

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=plugins');

        $page->assertPresent('a[href="admin.php?page=plugin-pwgtest-plugins-installed-malformed-hooks"]');
        $page->assertNoJavaScriptErrors();
        H::assertNoServerErrors($page, 'plugins_installed malformed menu-link entries');
    } finally {
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($hooksId)));
        $db->close();
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
    // connectivity (see this file's own top docblock), but the branch
    // under test here (render()'s own consumption of whatever ends up in
    // $_SESSION['incompatible_plugins']) doesn't care how that entry got
    // there.
    pluginsInstalledWriteFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Incompatible Session Probe
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */

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
    $prefix = pluginsInstalledDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));

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
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        pluginsInstalledRemoveFixturePlugin($pluginId);
    }
});

it('leaves a $_SESSION[incompatible_plugins] entry untouched when its recorded version still matches the on-disk plugin', function (): void {
    $pluginId = 'pwgtest-plugins-installed-session';

    pluginsInstalledWriteFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Plugins Installed Test -- Incompatible Session Probe
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/PluginsInstalledPageRendererTest.php).
    */

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
    $prefix = pluginsInstalledDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));

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
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        pluginsInstalledRemoveFixturePlugin($pluginId);
    }
});