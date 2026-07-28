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
 * Also not exercised: the deprecated get_admin_plugin_menu_links plugin-menu
 * hook (needs a real registered event-plugin handler, which this offline
 * test env has none of) and the 'merged' plugin state (needs a real fs
 * plugin whose PEM extension id appears in install/obsolete_extensions.list
 * -- same empty-plugins-directory limitation as above).
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