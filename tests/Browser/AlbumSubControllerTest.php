<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

function albumSubDb(): mysqli|Connection
{
    return H::connect();
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

    expect($page->content())
        ->toContain('unknown album');
});

it('fatal-errors instead of silently swallowing a real render_category_name hook that returns something other than a RenderCategoryName instance', function (): void {
    // RenderCategoryName is dispatched from shared, site-wide render paths
    // (confirmed live: identification.php itself 500s once this plugin is
    // active), not just AlbumSubController's own page -- the plugin is
    // activated only around the one navigation under test, strictly AFTER
    // login and album creation and deactivated again strictly BEFORE
    // cleanup, so it can't break the login flow or the delete WS call.
    $pluginId = 'ct-albumsub-hook-' . uniqid();
    $pluginSource = <<<'PHP'
    <?php

    declare(strict_types=1);

    /*
    Plugin Name: Album Sub Controller Test -- render_category_name Hook
    Version: 1.0.0
    Description: Test-only fixture plugin (tests/Browser/AlbumSubControllerTest.php).
    */

    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Event\Template\RenderCategoryName::class,
        static fn (mixed $event): mixed => null
    );
    PHP;

    $db = albumSubDb();

    $page = H::loginAsAdmin($this);
    $categoryName = 'CT Album Sub Hook Fallback ' . uniqid();
    $album = H::wsCall($page, 'pwg.categories.add', [
        'name' => $categoryName,
    ]);
    $albumResult = $album['result'] ?? null;
    if (! is_array($albumResult) || ! is_numeric($albumResult['id'] ?? null)) {
        throw new RuntimeException('pwg.categories.add did not return a numeric id: ' . var_export($album, true));
    }
    $albumId = (int) $albumResult['id'];

    try {
        albumSubWriteFixturePlugin($pluginId, $pluginSource);
        H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", H::dbEscape($db, $pluginId)));

        try {
            // dispatchChange() now enforces its own instanceof contract --
            // a misbehaving handler makes the request fail loud (an HTTP
            // 500) rather than silently degrading. display_errors is off
            // site-wide (Core\ErrorCollector::install() forces it, and
            // php.ini already has it off too), so the response body itself
            // carries no exception detail to assert on -- the status code
            // is the only reliable, environment-independent signal.
            $response = H::rawGet($page, '/admin.php?page=album&cat_id=' . $albumId);
            expect($response['status'])->toBe(500);
        } finally {
            H::dbQuery($db, sprintf("DELETE FROM plugins WHERE id = '%s'", H::dbEscape($db, $pluginId)));
            albumSubRemoveFixturePlugin($pluginId);
        }
    } finally {
        H::dbClose($db);
        H::wsCall($page, 'pwg.categories.delete', [
            'category_id' => $albumId,
            'photo_deletion_mode' => 'force_delete',
            'pwg_token' => H::pwgToken($page),
        ]);
    }
});
