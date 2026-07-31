<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\AlbumSubController (admin.php?page=album) --
 * already smoke-tested (a valid cat_id, tab=notification) by
 * AdminExtendedSmokeTest.php. This file closes its 2 remaining branches
 * that route never reaches:
 * - the `$categoryRow === null` "unknown album" fatalError (a cat_id with
 *   no matching row at all);
 * - the `render_category_name` EventDispatcher hook's non-string-return
 *   fallback (`! is_string($category_name)`), which needs a REAL plugin
 *   registering that hook -- EventDispatcher::triggerChange()'s own
 *   pass-through default (no handler registered) always returns
 *   $category['name'] unchanged, already a string (categories.name is a
 *   NOT NULL varchar), so this branch is unreachable without one. Same
 *   throwaway-fixture-plugin-under-the-live-plugins-root technique
 *   PluginsInstalledPageRendererTest.php's own
 *   "get_admin_plugin_menu_links" hook test already establishes.
 */
function albumSubDbPrefix(): string
{
    $prefix = getenv('PIWIGO_DB_PREFIX');

    return $prefix !== false ? $prefix : 'piwigo_';
}

function albumSubDb(): mysqli
{
    return new mysqli(
        (string) getenv('PIWIGO_DB_HOST'),
        (string) getenv('PIWIGO_DB_USER'),
        (string) getenv('PIWIGO_DB_PASSWORD'),
        (string) getenv('PIWIGO_DB_BASE')
    );
}

function albumSubPluginsPath(): string
{
    return dirname(__DIR__, 2) . '/plugins/';
}

function albumSubWriteFixturePlugin(string $pluginId, string $mainIncPhpSource): void
{
    $dir = albumSubPluginsPath() . $pluginId;
    if (! is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }
    file_put_contents($dir . '/main.inc.php', $mainIncPhpSource);
}

function albumSubRemoveFixturePlugin(string $pluginId): void
{
    $dir = albumSubPluginsPath() . $pluginId;
    @unlink($dir . '/main.inc.php');
    if (is_dir($dir)) {
        rmdir($dir);
    }
}

it('fatal-errors with "unknown album" for a cat_id with no matching category row', function (): void {
    $page = H::loginAsAdmin($this);
    $page = H::navigateOk($page, '/admin.php?page=album&cat_id=999999999');

    expect($page->content())->toContain('unknown album');
});

it('falls back to the raw category name when a real render_category_name hook returns a non-string', function (): void {
    $pluginId = 'ct-albumsub-hook-' . uniqid();
    albumSubWriteFixturePlugin($pluginId, <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Album Sub Controller Test -- render_category_name Hook
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/AlbumSubControllerTest.php).
    */

    \Piwigo\PluginConfig\EventDispatcher::get()->addEventHandler(
        'render_category_name',
        static fn (mixed $name): mixed => null
    );
    PHP);

    $db = albumSubDb();
    $prefix = albumSubDbPrefix();
    $db->query(sprintf(
        "INSERT INTO %splugins (id, state, version) VALUES ('%s', 'active', '1.0.0')",
        $prefix,
        $db->real_escape_string($pluginId)
    ));

    try {
        $page = H::loginAsAdmin($this);
        $categoryName = 'CT Album Sub Hook Fallback ' . uniqid();
        $album = H::wsCall($page, 'pwg.categories.add', ['name' => $categoryName]);
        $albumResult = $album['result'] ?? null;
        if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
            throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
        }
        $albumId = (int) $albumResult['id'];

        try {
            $page = H::navigateOk($page, '/admin.php?page=album&cat_id=' . $albumId);

            // A registered handler returning null would otherwise make
            // ADMIN_PAGE_TITLE's <strong>...</strong> wrap a literal empty
            // string -- the `! is_string($category_name)` fallback instead
            // keeps the real, raw category name.
            $page->assertSee($categoryName);
            $page->assertNoJavaScriptErrors();
        } finally {
            H::wsCall($page, 'pwg.categories.delete', [
                'category_id' => $albumId,
                'photo_deletion_mode' => 'force_delete',
                'pwg_token' => H::pwgToken($page),
            ]);
        }
    } finally {
        $db->query(sprintf("DELETE FROM %splugins WHERE id = '%s'", $prefix, $db->real_escape_string($pluginId)));
        $db->close();
        albumSubRemoveFixturePlugin($pluginId);
    }
});
