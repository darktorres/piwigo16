<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Override;

final class WsPluginsTest extends ContractTestCase
{
    /**
     * @var list<string>
     */
    private array $pluginDirsToRemove = [];

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->pluginDirsToRemove as $dir) {
            $this->removeDirRecursive($dir);
        }
        parent::tearDown();
    }

    private function removeDirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        foreach ($entries !== false ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * pluginsGetList()'s foreach loop body (id/name/version/state/
     * description) never ran at all before this test -- this environment's
     * plugins/ directory has no real plugin subdirectories (confirmed
     * live: only the bare index.php stub), so $fs_plugins was always
     * empty. A real, throwaway plugin directory (with the exact
     * name/version/description fields ExtensionScanner::scanPlugin()
     * reads from plugin.json) exercises the real scan + merge-with-DB-state
     * logic; never installed in the DB, so 'state' must resolve to the
     * 'uninstalled' fallback.
     */
    public function testGetListIncludesARealPluginDirectoryFromDisk(): void
    {
        $pluginId = 'ct_fake_plugin_' . uniqid();
        $dir = dirname(__DIR__, 2) . '/plugins/' . $pluginId;
        self::assertTrue(mkdir($dir, 0775, true));
        $this->pluginDirsToRemove[] = $dir;
        file_put_contents($dir . '/plugin.json', json_encode([
            'name' => 'CT Fake Plugin',
            'version' => '1.2.3',
            'description' => 'A throwaway plugin directory for Contract test coverage.',
            'author' => 'Contract Tests',
        ], JSON_THROW_ON_ERROR));

        $response = $this->wsAdmin('pwg.plugins.getList');

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        $byId = array_column($result, null, 'id');
        self::assertArrayHasKey($pluginId, $byId);
        $entry = $byId[$pluginId];
        self::assertIsArray($entry);
        self::assertSame('CT Fake Plugin', $entry['name']);
        self::assertSame('1.2.3', $entry['version']);
        self::assertSame('uninstalled', $entry['state']);
        self::assertSame('A throwaway plugin directory for Contract test coverage.', $entry['description']);
    }

    public function testGetListResponseMatchesSchema(): void
    {
        $response = $this->wsAdmin('pwg.plugins.getList');

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('pwg.plugins.getList', $response);
    }

    public function testGetListResultIsAnArray(): void
    {
        $response = $this->wsAdmin('pwg.plugins.getList');

        self::assertIsArray($response['result']);
    }

    public function testGetListForbiddenForGuest(): void
    {
        $response = $this->ws('pwg.plugins.getList');

        self::assertSame('fail', $response['stat']);
    }
}
