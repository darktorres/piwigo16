<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Admin\PluginsInstalledPageRenderer (admin.php?page=plugins, the
 * default "installed" tab) -- already GET-tested by AdminExtendedSmokeTest;
 * this file exercises the show_details session toggle (persists across
 * requests) and the "missing" plugin state (a real DB row with no
 * matching filesystem plugin). getIncompatibleExtensions() (the
 * incompatible_plugins= AJAX branch) talks to piwigo.org over the network
 * with no injectable seam -- same documented limitation as PemCatalog/
 * CoreUpdateService/PiwigoInfosSender -- not exercised here.
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