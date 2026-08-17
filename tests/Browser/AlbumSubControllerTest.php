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

/**
 * Writes a real `plugin.json` + PSR-4-autoloadable `ExtensionInterface`
 * class -- the plugin/theme contract's own fixture shape, loaded via
 * `PluginConfig\PluginRegistry::bootActive()`. `$bootBodySource` is spliced verbatim into the
 * fixture class's own `boot()` method body -- the same "runs once, early
 * in the request" timing the old top-level `main.inc.php` code had.
 * The namespace is derived from random bytes, not `$pluginId` (which can
 * start with a digit after its own `uniqid()` suffix -- not a legal
 * leading character for a PHP identifier).
 */
function albumSubWriteFixturePlugin(string $pluginId, string $bootBodySource): void
{
    $dir = albumSubPluginsPath() . $pluginId;
    if (! is_dir($dir . '/src')) {
        mkdir($dir . '/src', 0o777, true);
    }

    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    file_put_contents($dir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/AlbumSubControllerTest.php).',
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

function albumSubRemoveFixturePlugin(string $pluginId): void
{
    $dir = albumSubPluginsPath() . $pluginId;
    @unlink($dir . '/src/Plugin.php');
    @rmdir($dir . '/src');
    @unlink($dir . '/plugin.json');
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

it('renders the real category name when a render_category_name hook returns something other than a RenderCategoryName instance', function (): void {
    // RenderCategoryName is dispatched from shared, site-wide render
    // paths, not just AlbumSubController's own page -- the plugin is
    // activated only around the one navigation under test, strictly AFTER
    // login and album creation and deactivated again strictly BEFORE
    // cleanup, so it can't break the login flow or the delete WS call.
    $pluginId = 'ct-albumsub-hook-' . uniqid();
    $pluginSource = <<<'PHP'
    \Piwigo\Tests\Support\EventDispatcherTestFactory::get()->addTypedHandler(
        \Piwigo\Category\Event\RenderCategoryName::class,
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
            // dispatch() never reads a handler's return value (Plan 2
            // Stage A step 2), so a misbehaving handler that returns
            // garbage without touching $event->categoryName leaves it
            // exactly as the caller set it -- the page renders normally
            // with the real category name, rather than crashing or
            // dropping it.
            $response = H::rawGet($page, '/admin.php?page=album&cat_id=' . $albumId);
            expect($response['status'])->toBe(200);
            expect($response['body'])->toContain($categoryName);
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
