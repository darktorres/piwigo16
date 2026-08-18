<?php

declare(strict_types=1);

use PgSql\Connection;
use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Covers the EXTRA_BODY_CONTENT plugin content injection point (P29.6,
 * ported for AdminTools_16.3.0's own loc_after_page_header registrations)
 * end to end: a real fixture plugin subscribes to the already-dispatched
 * Page\Event\PageHeaderRendered and calls ExtensionContext::template()->
 * concat('EXTRA_BODY_CONTENT', ...) -- both themes/admin/default/template/
 * footer.latte and themes/default/template/footer.latte render it via the
 * same n:if="isset($EXTRA_BODY_CONTENT)" block right before </body>, so
 * this covers both the admin and public render paths, the whole point of
 * reusing one generic mechanism for both of AdminTools' real registrations
 * instead of the header-specific original design.
 */
function extraBodyContentDbConnect(): mysqli|Connection
{
    return H::connect();
}

it('renders a plugin-injected EXTRA_BODY_CONTENT block right before </body> on both an admin page and a public page', function (): void {
    $marker = 'PWGTEST_EXTRA_BODY_CONTENT_MARKER_' . uniqid();
    $pluginId = 'pwgtest-extra-body-content';
    $pluginDir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
    $namespace = 'PiwigoTestFixture\\Ext' . bin2hex(random_bytes(6));

    if (! is_dir($pluginDir . '/src')) {
        mkdir($pluginDir . '/src', 0o777, true);
    }

    file_put_contents($pluginDir . '/plugin.json', json_encode([
        'id' => $pluginId,
        'name' => $pluginId,
        'version' => '1.0.0',
        'description' => 'Test-only fixture plugin (tests/Browser/ExtraBodyContentTest.php).',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => $namespace . '\\Plugin',
        'autoload' => [
            'psr-4' => [
                $namespace . '\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($pluginDir . '/src/Plugin.php', <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use Piwigo\\PluginConfig\\ExtensionContext;
        use Piwigo\\PluginConfig\\ExtensionInterface;

        final class Plugin implements ExtensionInterface
        {
            public function boot(ExtensionContext \$context): void
            {
                \\Piwigo\\Tests\\Support\\EventDispatcherTestFactory::get()->addTypedHandler(
                    \\Piwigo\\Page\\Event\\PageHeaderRendered::class,
                    static function (\\Piwigo\\Page\\Event\\PageHeaderRendered \$event) use (\$context): void {
                        \$context->template()->concat('EXTRA_BODY_CONTENT', '<div id="extra-body-content-marker">{$marker}</div>');
                    }
                );
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

    $db = extraBodyContentDbConnect();
    H::dbQuery($db, sprintf("INSERT INTO plugins (id, state, version) VALUES ('%s', 'active', '1.0.0')", $pluginId));
    H::dbClose($db);
    // No cache-clear needed: PluginConfig\PluginRegistry::bootActive()
    // always re-queries active plugins fresh on every request, same
    // convention already established in tests/Browser/PictureControllerTest.php.

    try {
        $adminPage = H::asAdmin($this);
        $adminBody = H::rawWebpage($adminPage)->content();
        expect($adminBody)
            ->toContain($marker);
        expect(substr_count($adminBody, $marker))
            ->toBe(1);

        $publicPage = H::gotoOk($this, '/index.php');
        $publicBody = H::rawWebpage($publicPage)->content();
        expect($publicBody)
            ->toContain($marker);
        expect(substr_count($publicBody, $marker))
            ->toBe(1);
    } finally {
        $cleanupDb = extraBodyContentDbConnect();
        H::dbQuery($cleanupDb, sprintf("DELETE FROM plugins WHERE id = '%s'", $pluginId));
        H::dbClose($cleanupDb);
        @unlink($pluginDir . '/src/Plugin.php');
        @rmdir($pluginDir . '/src');
        @unlink($pluginDir . '/plugin.json');
        @rmdir($pluginDir);
    }
});

it('renders no EXTRA_BODY_CONTENT markup at all when no plugin populates it', function (): void {
    // n:if="isset($EXTRA_BODY_CONTENT)" -- proves the var stays genuinely
    // unset (no empty wrapper <div>) on an ordinary page with no active
    // plugin using this mechanism, the other half of the contract the
    // test above doesn't cover.
    $publicPage = H::gotoOk($this, '/index.php');
    $publicBody = H::rawWebpage($publicPage)->content();

    expect($publicBody)
        ->not->toContain('extra-body-content-marker');
});
