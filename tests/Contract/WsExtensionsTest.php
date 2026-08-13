<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Core\Env;
use Piwigo\Db\DbConnection;

/**
 * Covers Extensions' 6 admin_only WS methods. Deliberately never
 * exercises any branch that reaches PemCatalog::extractArchive() (the
 * plugin/theme/language "update" action) or CoreUpdateService::
 * checkPiwigoUpgrade() (pwg.extensions.checkUpdates) -- both perform a
 * real outbound HTTP request to a live third-party server
 * (RequestBootstrap::pemUrl()/download.php, AppInfo::URL/download/
 * all_versions.php), which a test suite must not depend on. Every other
 * branch (guards, install/activate/deactivate/delete/uninstall on a
 * fake, filesystem-absent extension id, ignoreUpdate's real
 * extension_ignored_updates read/write) is real, local, and
 * side-effect-contained.
 */
final class WsExtensionsTest extends ContractTestCase
{
    private Connection $conn;

    /**
     * @var list<int>
     */
    private array $extraUserIdsToDelete = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->conn = DbConnection::build();
        $this->loginAsAdmin();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->setConfigBool('enable_extensions_install', true);
        $this->conn->executeStatement('DELETE FROM extension_ignored_updates');

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
        $this->upsertConfig($param, $encoded);
        $this->configCachePool()
            ->clear();
    }

    /**
     * Creates a user with status='admin' (passes the WS layer's admin_only
     * gate, which accepts admin OR webmaster) but not 'webmaster' -- the
     * only way to reach Extensions' own stricter
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

    public function testPluginsPerformActionInvalidTokenReturnsError(): void
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

    public function testPluginsPerformActionNonWebmasterReturnsError(): void
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

    public function testPluginsPerformActionDeleteWithInstallDisabledReturnsError(): void
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

    public function testPluginsPerformActionInstallOnANonexistentPluginIsASafeNoop(): void
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

    public function testPluginsPerformActionActivateOnANonexistentPluginIsASafeNoopAndClearsTheTemplateCache(): void
    {
        // dbRow is null and fsEntry is null (no matching plugins/ directory)
        // -- performPluginAction('activate', ...) delegates to
        // performPluginAction('install', ...) first, which itself breaks
        // out immediately with $errors=[] (same fsEntry-absent shortcut as
        // the 'install' no-op test above), so $errors stays [] the whole
        // way through -- covers the success branch (not the error one),
        // including the deleteCompiledTemplates() call gated on
        // action in {activate, deactivate}.
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.plugins.performAction', [
            'action' => 'activate',
            'plugin' => 'ct_fake_plugin_' . uniqid(),
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertTrue($response['result']);
    }

    // --------------------------------------------------------- themesPerformAction

    public function testThemesPerformActionInvalidTokenReturnsError(): void
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

    public function testThemesPerformActionDeleteWithInstallDisabledReturnsError(): void
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

    /**
     * ExtensionLifecycle::performThemeAction()'s 'deactivate' case refuses
     * to deactivate the last remaining theme ("you need at least one
     * theme") -- but only once it has a real dbRow to act on; the fixture's
     * themes table starts out completely empty (confirmed live --
     * without a seeded row, 'default' hits the null-dbRow safe-noop branch
     * instead, same as the "never installed" test below), so this test
     * seeds the single row itself and removes it afterward.
     */
    public function testThemesPerformActionDeactivateTheOnlyRegisteredThemeReturnsError(): void
    {
        $token = $this->getPwgToken();

        $this->conn->executeStatement(
            'INSERT INTO themes (id, version, name) VALUES (?, ?, ?)',
            ['default', '1.0.0', 'default']
        );

        try {
            // WsErrorResponse(500, ...) mirrors onto a real HTTP 500 status --
            // callWs()'s generic "< 500" guard would wrongly reject this
            // well-formed business-rule error.
            $response = $this->callWsAllowingServerError('pwg.themes.performAction', [
                'action' => 'deactivate',
                'theme' => 'default',
                'pwg_token' => $token,
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(500, $response['err']);
            self::assertSame('Impossible to deactivate this theme, you need at least one theme.', $response['message']);
        } finally {
            $this->conn->executeStatement('DELETE FROM themes WHERE id = ?', ['default']);
        }
    }

    public function testThemesPerformActionDeactivateOnANeverInstalledThemeIsASafeNoop(): void
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

    public function testUpdateInstallDisabledReturnsError(): void
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

    public function testUpdateNonWebmasterReturnsError(): void
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

    public function testUpdateInvalidTokenReturnsError(): void
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

    public function testUpdateInvalidTypeReturnsError(): void
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

    public function testIgnoreUpdateNonWebmasterReturnsError(): void
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

    public function testIgnoreUpdateInvalidTokenReturnsError(): void
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

    public function testIgnoreUpdateMissingIdReturnsError(): void
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

    public function testIgnoreUpdateInvalidTypeReturnsError(): void
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

    public function testIgnoreUpdateAddsExtensionToTheIgnoreList(): void
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

        // extension_type stores ExtensionType::value (singular: 'plugin'),
        // not the plural wire-format 'plugins' the WS param itself uses --
        // see ExtensionIgnoredUpdateEntity's own docblock.
        $rows = $this->conn->fetchAllAssociative(
            "SELECT extension_id FROM extension_ignored_updates WHERE extension_type = 'plugin'"
        );
        self::assertCount(1, $rows);
        self::assertSame($pluginId, $rows[0]['extension_id']);
    }

    public function testIgnoreUpdateIgnoringTheSameExtensionTwiceDoesNotError(): void
    {
        // Adversarially-motivated: extension_ignored_updates has a
        // composite (extension_type, extension_id) PK -- a set-membership
        // table, not an append-only log -- so re-ignoring an
        // already-ignored extension (e.g. two admins clicking "ignore" in
        // the same session, or checkExtensions() re-syncing an id that's
        // still pending) must be a no-op, never a duplicate-key error.
        $token = $this->getPwgToken();
        $pluginId = 'ct_fake_plugin_' . uniqid();
        $params = [
            'type' => 'plugins',
            'id' => $pluginId,
            'pwg_token' => $token,
        ];

        $first = $this->callWs('pwg.extensions.ignoreUpdate', $params);
        $second = $this->callWs('pwg.extensions.ignoreUpdate', $params);

        self::assertSame('ok', $first['stat']);
        self::assertSame('ok', $second['stat']);

        $rows = $this->conn->fetchAllAssociative(
            "SELECT extension_id FROM extension_ignored_updates WHERE extension_type = 'plugin' AND extension_id = ?",
            [$pluginId]
        );
        self::assertCount(1, $rows, 'ignoring the same extension twice must not create a duplicate row');
    }

    public function testIgnoreUpdateResetWithTypeClearsOnlyThatType(): void
    {
        $token = $this->getPwgToken();
        $pluginId = 'ct_fake_plugin_' . uniqid();
        $now = Env::now()->format('Y-m-d H:i:s');
        $this->conn->insert('extension_ignored_updates', [
            'extension_type' => 'plugin',
            'extension_id' => $pluginId,
            'ignored_at' => $now,
        ]);
        $this->conn->insert('extension_ignored_updates', [
            'extension_type' => 'theme',
            'extension_id' => 'some_theme',
            'ignored_at' => $now,
        ]);

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'type' => 'plugins',
            'reset' => true,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);

        $pluginRows = $this->conn->fetchAllAssociative(
            "SELECT extension_id FROM extension_ignored_updates WHERE extension_type = 'plugin'"
        );
        self::assertSame([], $pluginRows);

        $themeRows = $this->conn->fetchAllAssociative(
            "SELECT extension_id FROM extension_ignored_updates WHERE extension_type = 'theme'"
        );
        self::assertSame(['some_theme'], array_column($themeRows, 'extension_id'), 'reset with a specific type must not touch other types');
    }

    public function testIgnoreUpdateResetWithoutTypeClearsEverything(): void
    {
        $token = $this->getPwgToken();
        $now = Env::now()->format('Y-m-d H:i:s');
        $this->conn->insert('extension_ignored_updates', [
            'extension_type' => 'plugin',
            'extension_id' => 'a',
            'ignored_at' => $now,
        ]);
        $this->conn->insert('extension_ignored_updates', [
            'extension_type' => 'theme',
            'extension_id' => 'b',
            'ignored_at' => $now,
        ]);
        $this->conn->insert('extension_ignored_updates', [
            'extension_type' => 'language',
            'extension_id' => 'c',
            'ignored_at' => $now,
        ]);

        $response = $this->callWs('pwg.extensions.ignoreUpdate', [
            'reset' => true,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);

        $remaining = $this->conn->fetchOne('SELECT COUNT(*) FROM extension_ignored_updates');
        self::assertSame(0, $remaining);
    }
}
