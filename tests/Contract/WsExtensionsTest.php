<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Covers PwgExtensions' 6 admin_only WS methods. Deliberately never
 * exercises any branch that reaches PemCatalog::extractArchive() (the
 * plugin/theme/language "update" action) or CoreUpdateService::
 * checkPiwigoUpgrade() (pwg.extensions.checkUpdates) -- both perform a
 * real outbound HTTP request to a live third-party server
 * (RequestBootstrap::pemUrl()/download.php, AppInfo::URL/download/
 * all_versions.php), which a test suite must not depend on. Every other
 * branch (guards, install/activate/deactivate/delete/uninstall on a
 * fake, filesystem-absent extension id, ignoreUpdate's config
 * read/write) is real, local, and side-effect-contained.
 */
final class WsExtensionsTest extends ContractTestCase
{
    private Connection $conn;

    /** @var list<int> */
    private array $extraUserIdsToDelete = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->setConfigBool('enable_extensions_install', true);
        $this->conn->executeStatement(
            "UPDATE " . Tables::config() . " SET value = ? WHERE param = 'updates_ignored'",
            ['{"plugins":[],"themes":[],"languages":[]}']
        );
        \Piwigo\Cache\CachePools::config()->clear();

        if ($this->extraUserIdsToDelete !== []) {
            $this->loginAsAdmin();
            $token = $this->getPwgToken();
            $this->callWs('pwg.users.delete', [
                'user_id' => $this->extraUserIdsToDelete,
                'pwg_token' => $token,
            ]);
            $this->extraUserIdsToDelete = [];
        }

        parent::tearDown();
    }

    private function setConfigBool(string $param, bool $value): void
    {
        $encoded = $value ? 'true' : 'false';
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::config() . ' (param, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$param, $encoded]
        );
        \Piwigo\Cache\CachePools::config()->clear();
    }

    /**
     * Creates a user with status='admin' (passes the WS layer's admin_only
     * gate, which accepts admin OR webmaster) but not 'webmaster' -- the
     * only way to reach PwgExtensions' own stricter
     * AccessControl::isWebmaster() guards. Logs the session onto that user
     * and returns a token valid for it; tearDown() deletes the user.
     */
    private function loginAsNonWebmasterAdmin(): string
    {
        $adminToken = $this->getPwgToken();
        $username = 'ct_admin_' . uniqid();
        $password = 'Test1234!';
        $add = $this->callWs('pwg.users.add', [
            'username' => $username,
            'password' => $password,
            'pwg_token' => $adminToken,
        ]);
        self::assertSame('ok', $add['stat']);
        $result = $add['result'];
        self::assertIsArray($result);
        $users = $result['users'] ?? null;
        self::assertIsArray($users);
        $user = $users[0] ?? null;
        self::assertIsArray($user);
        $userId = $user['id'] ?? null;
        self::assertIsInt($userId);
        $this->extraUserIdsToDelete[] = $userId;

        $setStatus = $this->callWs('pwg.users.setInfo', [
            'user_id' => $userId,
            'status' => 'admin',
            'pwg_token' => $adminToken,
        ]);
        self::assertSame('ok', $setStatus['stat']);

        $login = $this->callWs('pwg.session.login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertSame('ok', $login['stat']);

        return $this->getPwgToken();
    }

    // -------------------------------------------------------- pluginsPerformAction

    public function test_pluginsPerformAction_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.plugins.performAction', [
            'action' => 'install',
            'plugin' => 'ct_fake_plugin',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_pluginsPerformAction_non_webmaster_returns_error(): void
    {
        $token = $this->loginAsNonWebmasterAdmin();

        $response = $this->callWs('pwg.plugins.performAction', [
            'action' => 'install',
            'plugin' => 'ct_fake_plugin',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Webmaster status is required.', $response['message']);
    }

    public function test_pluginsPerformAction_delete_with_install_disabled_returns_error(): void
    {
        $this->setConfigBool('enable_extensions_install', false);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.plugins.performAction', [
            'action' => 'delete',
            'plugin' => 'ct_fake_plugin',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Piwigo extensions install/update/delete system is disabled', $response['message']);
    }

    public function test_pluginsPerformAction_install_on_a_nonexistent_plugin_is_a_safe_noop(): void
    {
        // fsEntry is null for a plugin id with no matching directory --
        // performPluginAction('install', ...) breaks out immediately with
        // $errors=[] (see ExtensionLifecycle::performPluginAction()), so
        // this genuinely returns success without installing anything.
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.plugins.performAction', [
            'action' => 'install',
            'plugin' => 'ct_fake_plugin_' . uniqid(),
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertTrue($response['result']);
    }

    // --------------------------------------------------------- themesPerformAction

    public function test_themesPerformAction_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.themes.performAction', [
            'action' => 'activate',
            'theme' => 'ct_fake_theme',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_themesPerformAction_delete_with_install_disabled_returns_error(): void
    {
        $this->setConfigBool('enable_extensions_install', false);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.themes.performAction', [
            'action' => 'delete',
            'theme' => 'ct_fake_theme',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Piwigo extensions install/update/delete system is disabled', $response['message']);
    }

    public function test_themesPerformAction_deactivate_on_a_never_installed_theme_is_a_safe_noop(): void
    {
        // performThemeAction('deactivate', ...) with no matching DB row
        // records an 'error' activity detail internally but never appends
        // to $errors, so the WS layer still reports success -- same "no
        // real side effect, no real error" shape as the plugin install
        // no-op above.
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.themes.performAction', [
            'action' => 'deactivate',
            'theme' => 'ct_fake_theme_' . uniqid(),
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertTrue($response['result']);
    }

    // ------------------------------------------------------------------- update

    public function test_update_install_disabled_returns_error(): void
    {
        $this->setConfigBool('enable_extensions_install', false);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.extensions.update', [
            'type' => 'plugins',
            'id' => 'ct_fake_plugin',
            'revision' => '1',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Piwigo extensions install/update system is disabled', $response['message']);
    }

    public function test_update_non_webmaster_returns_error(): void
    {
        $token = $this->loginAsNonWebmasterAdmin();

        $response = $this->callWs('pwg.extensions.update', [
            'type' => 'plugins',
            'id' => 'ct_fake_plugin',
            'revision' => '1',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Webmaster status is required.', $response['message']);
    }

    public function test_update_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.extensions.update', [
            'type' => 'plugins',
            'id' => 'ct_fake_plugin',
            'revision' => '1',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_update_invalid_type_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.extensions.update', [
            'type' => 'not-a-real-type',
            'id' => 'ct_fake_plugin',
            'revision' => '1',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('invalid extension type', $response['message']);
    }

    // ------------------------------------------------------------- ignoreUpdate

    public function test_ignoreUpdate_non_webmaster_returns_error(): void
    {
        $token = $this->loginAsNonWebmasterAdmin();

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'type' => 'plugins',
            'id' => 'ct_fake_plugin',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Access denied', $response['message']);
    }

    public function test_ignoreUpdate_invalid_token_returns_error(): void
    {
        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'type' => 'plugins',
            'id' => 'ct_fake_plugin',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid security token', $response['message']);
    }

    public function test_ignoreUpdate_missing_id_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'type' => 'plugins',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid parameters', $response['message']);
    }

    public function test_ignoreUpdate_invalid_type_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'type' => 'not-a-real-type',
            'id' => 'ct_fake_plugin',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Invalid parameters', $response['message']);
    }

    public function test_ignoreUpdate_adds_extension_to_the_ignore_list(): void
    {
        $token = $this->getPwgToken();
        $pluginId = 'ct_fake_plugin_' . uniqid();

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'type' => 'plugins',
            'id' => $pluginId,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertTrue($response['result']);

        $stored = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'updates_ignored'");
        self::assertIsString($stored);
        $decoded = json_decode($stored, true);
        self::assertIsArray($decoded);
        $plugins = $decoded['plugins'] ?? null;
        self::assertIsArray($plugins);
        self::assertContains($pluginId, $plugins);
    }

    public function test_ignoreUpdate_reset_with_type_clears_only_that_type(): void
    {
        $token = $this->getPwgToken();
        $pluginId = 'ct_fake_plugin_' . uniqid();
        $this->conn->executeStatement(
            "UPDATE " . Tables::config() . " SET value = ? WHERE param = 'updates_ignored'",
            [json_encode(['plugins' => [$pluginId], 'themes' => ['some_theme'], 'languages' => []])]
        );
        \Piwigo\Cache\CachePools::config()->clear();

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'type' => 'plugins',
            'reset' => true,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);

        $stored = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'updates_ignored'");
        self::assertIsString($stored);
        $decoded = json_decode($stored, true);
        self::assertIsArray($decoded);
        self::assertSame([], $decoded['plugins']);
        self::assertSame(['some_theme'], $decoded['themes'], 'reset with a specific type must not touch other types');
    }

    public function test_ignoreUpdate_reset_without_type_clears_everything(): void
    {
        $token = $this->getPwgToken();
        $this->conn->executeStatement(
            "UPDATE " . Tables::config() . " SET value = ? WHERE param = 'updates_ignored'",
            [json_encode(['plugins' => ['a'], 'themes' => ['b'], 'languages' => ['c']])]
        );
        \Piwigo\Cache\CachePools::config()->clear();

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'reset' => true,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);

        $stored = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'updates_ignored'");
        self::assertIsString($stored);
        $decoded = json_decode($stored, true);
        self::assertSame(['plugins' => [], 'themes' => [], 'languages' => []], $decoded);
    }
}
