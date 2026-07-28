<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

final class WsUsersMutationTest extends ContractTestCase
{
    private ?int $userId = null;

    private Connection $conn;

    /** @var list<int> */
    private array $extraUserIdsToDelete = [];

    /** @var list<int> */
    private array $imageIdsToDelete = [];

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
        $token = $this->getPwgToken();

        if ($this->userId !== null) {
            $this->callWs('pwg.users.delete', [
                'user_id'   => [$this->userId],
                'pwg_token' => $token,
            ]);
            $this->userId = null;
        }

        if ($this->extraUserIdsToDelete !== []) {
            $this->callWs('pwg.users.delete', [
                'user_id' => $this->extraUserIdsToDelete,
                'pwg_token' => $token,
            ]);
            $this->extraUserIdsToDelete = [];
        }

        foreach ($this->imageIdsToDelete as $imageId) {
            $this->conn->executeStatement('DELETE FROM ' . Tables::favorites() . ' WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM ' . Tables::images() . ' WHERE id = ?', [$imageId]);
        }

        parent::tearDown();
    }

    /** Creates a real user via the WS API and marks it for cleanup in tearDown(). */
    private function createUser(string $username, string $password = 'Test1234!', ?string $email = null): int
    {
        $token = $this->getPwgToken();
        $params = [
            'username' => $username,
            'password' => $password,
            'pwg_token' => $token,
        ];
        if ($email !== null) {
            $params['email'] = $email;
        }

        $response = $this->callWs('pwg.users.add', $params);
        $id = self::firstUserId($response);
        $this->extraUserIdsToDelete[] = $id;

        return $id;
    }

    /**
     * Inserts a real throwaway image row (with a tiny real file on disk)
     * directly via SQL, tracked for cleanup in tearDown().
     */
    private function insertThrowawayImage(): int
    {
        $tmpDir = dirname(__DIR__, 2) . '/upload/2026/08/01';
        $filename = 'ws-users-mutation-test-' . uniqid() . '.jpg';
        file_put_contents($tmpDir . '/' . $filename, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==',
            true
        ));

        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::images() . ' (file, path, md5sum, date_available) VALUES (?, ?, ?, ?)',
            [$filename, 'upload/2026/08/01/' . $filename, md5($filename), '2026-08-01 00:00:00']
        );
        $id = (int) $this->conn->lastInsertId();
        $this->imageIdsToDelete[] = $id;

        return $id;
    }

    /**
     * Narrows a decoded WS response down to result.users[0], asserting the
     * shape at every level. callWs() returns array<string, mixed>, so
     * nothing below the top level is known without explicit checks.
     *
     * @param array<string, mixed> $response
     */
    private static function firstUserId(array $response): int
    {
        $result = $response['result'] ?? null;
        self::assertIsArray($result, 'WS response "result" is not an array');

        $users = $result['users'] ?? null;
        self::assertIsArray($users, 'WS response "result.users" is not an array');

        $user = $users[0] ?? null;
        self::assertIsArray($user, 'WS response "result.users[0]" is not an array');

        $id = $user['id'] ?? null;
        self::assertTrue(
            is_int($id) || (is_string($id) && is_numeric($id)),
            'result.users[0].id is missing or not numeric'
        );

        return (int) $id;
    }

    public function test_add_returns_user_list_shape(): void
    {
        $token    = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $response = $this->callWs('pwg.users.add', [
            'username'   => $username,
            'password'   => 'Test1234!',
            'email'      => $username . '@test.local',
            'pwg_token'  => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);

        $this->userId = self::firstUserId($response);
    }

    public function test_setInfo_returns_user_list_shape(): void
    {
        $token    = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $add      = $this->callWs('pwg.users.add', [
            'username'  => $username,
            'password'  => 'Test1234!',
            'pwg_token' => $token,
        ]);
        $this->userId = self::firstUserId($add);

        $response = $this->callWs('pwg.users.setInfo', [
            'user_id'   => $this->userId,
            'status'    => 'normal',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);
    }

    public function test_setMyInfo_returns_ok(): void
    {
        $token    = $this->getPwgToken();
        $response = $this->callWs('pwg.users.setMyInfo', [
            'nb_image_page' => 24,
            'pwg_token'     => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_preferences_set_returns_ok(): void
    {
        $response = $this->callWs('pwg.users.preferences.set', [
            'param' => 'nb_image_page',
            'value' => 20,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function test_delete_returns_ok(): void
    {
        $token    = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $add      = $this->callWs('pwg.users.add', [
            'username'  => $username,
            'password'  => 'Test1234!',
            'pwg_token' => $token,
        ]);
        $id = self::firstUserId($add);

        $response = $this->callWs('pwg.users.delete', [
            'user_id'   => [$id],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    // ------------------------------------------------------------------ add

    public function test_add_invalid_token_returns_error(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.add', [
            'username' => 'ct_user_' . uniqid(),
            'password' => 'Test1234!',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_add_empty_username_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.add', [
            'username' => '   ',
            'password' => 'Test1234!',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('Name field must not be empty', $response['message']);
    }

    public function test_add_missing_password_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.add', [
            'username' => 'ct_user_' . uniqid(),
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
    }

    /**
     * double_password_type_in_admin defaults to false (CurrentConfig's own
     * static default) -- must be explicitly enabled for add()'s
     * password/password_confirm comparison to run at all.
     */
    public function test_add_password_confirmation_mismatch_returns_error(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('double_password_type_in_admin', 'true')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            $token = $this->getPwgToken();

            $response = $this->callWs('pwg.users.add', [
                'username' => 'ct_user_' . uniqid(),
                'password' => 'FirstPass123!',
                'password_confirm' => 'SecondPass456!',
                'pwg_token' => $token,
            ]);

            self::assertSame('fail', $response['stat']);
            self::assertSame(1003, $response['err']);
            self::assertSame('The passwords do not match', $response['message']);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'double_password_type_in_admin'");
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }

    public function test_add_auto_password_generates_one(): void
    {
        $token = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();

        $response = $this->callWs('pwg.users.add', [
            'username' => $username,
            'auto_password' => true,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $id = self::firstUserId($response);
        $this->extraUserIdsToDelete[] = $id;

        $password = $this->conn->fetchOne('SELECT password FROM ' . Tables::users() . ' WHERE id = ?', [$id]);
        self::assertIsString($password);
        self::assertNotSame('', $password, 'auto_password must generate a real, non-empty hash');
    }

    public function test_add_duplicate_username_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.add', [
            'username' => 'regular_user',
            'password' => 'Test1234!',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('this login is already used', $response['message']);
    }

    // ------------------------------------------------------------ getAuthKey

    public function test_getAuthKey_invalid_token_returns_error(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.getAuthKey', [
            'user_id' => $userId,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_getAuthKey_unknown_user_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.getAuthKey', [
            'user_id' => 999999,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('invalid user_id', $response['message']);
    }

    public function test_getAuthKey_returns_key_shape(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.getAuthKey', [
            'user_id' => $userId,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertSame($userId, $result['user_id']);
        self::assertIsString($result['auth_key'] ?? null);
    }

    // ---------------------------------------------------------------- delete

    public function test_delete_invalid_token_returns_error(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.delete', [
            'user_id' => [$userId],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_delete_protects_the_current_user_from_self_deletion(): void
    {
        $token = $this->getPwgToken();
        $selfId = $this->conn->fetchOne('SELECT id FROM ' . Tables::users() . ' WHERE username = ?', ['fixture_admin']);
        self::assertIsNumeric($selfId);
        $selfId = (int) $selfId;

        $response = $this->callWs('pwg.users.delete', [
            'user_id' => [$selfId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('0 users deleted', $response['result']);

        $stillExists = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::users() . ' WHERE id = ?', [$selfId]);
        self::assertIsNumeric($stillExists);
        self::assertSame(1, (int) $stillExists);
    }

    public function test_delete_protects_the_guest_id(): void
    {
        $token = $this->getPwgToken();
        $guestId = \Piwigo\Config\CurrentConfig::guestId();

        $response = $this->callWs('pwg.users.delete', [
            'user_id' => [$guestId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('0 users deleted', $response['result']);
    }

    /**
     * delete()'s own status-check branch: a non-webmaster admin session
     * additionally protects every other admin/webmaster account, on top of
     * the always-protected self/guest/default/webmaster_id list a
     * webmaster session already gets. fixture_admin is 'webmaster'
     * (protected unconditionally either way), so this needs a throwaway
     * user promoted to plain 'admin' to actually exercise the extra query.
     */
    public function test_delete_as_a_non_webmaster_admin_also_protects_other_admins(): void
    {
        $adminToken = $this->getPwgToken();
        $selfId = $this->conn->fetchOne('SELECT id FROM ' . Tables::users() . ' WHERE username = ?', ['fixture_admin']);
        self::assertIsNumeric($selfId);

        $username = 'ct_nonwm_admin_' . uniqid();
        $password = 'Test1234!';
        $newAdminId = $this->createUser($username, $password);
        $this->callWs('pwg.users.setInfo', [
            'user_id' => $newAdminId,
            'status' => 'admin',
            'pwg_token' => $adminToken,
        ]);

        $login = $this->callWs('pwg.session.login', ['username' => $username, 'password' => $password]);
        self::assertSame('ok', $login['stat']);
        $nonWebmasterToken = $this->getPwgToken();

        $response = $this->callWs('pwg.users.delete', [
            'user_id' => [(int) $selfId],
            'pwg_token' => $nonWebmasterToken,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('0 users deleted', $response['result']);
        $stillExists = $this->conn->fetchOne('SELECT COUNT(*) FROM ' . Tables::users() . ' WHERE id = ?', [(int) $selfId]);
        self::assertIsNumeric($stillExists);
        self::assertSame(1, (int) $stillExists);

        $this->loginAsAdmin();
    }

    // -------------------------------------------------------------- setInfo

    public function test_setInfo_invalid_token_returns_error(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.setInfo', [
            'user_id' => $userId,
            'status' => 'normal',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_setInfo_duplicate_username_returns_error(): void
    {
        $token = $this->getPwgToken();
        $otherUsername = 'ct_user_' . uniqid();
        $this->createUser($otherUsername);
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWs('pwg.users.setInfo', [
            'user_id' => $userId,
            'username' => $otherUsername,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('this login is already used', $response['message']);
    }

    // ------------------------------------------------------------ setMyInfo

    public function test_setMyInfo_invalid_token_returns_error(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.setMyInfo', [
            'nb_image_page' => 24,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_setMyInfo_guest_forbidden(): void
    {
        // This class's own setUp() already WS-authenticates the shared
        // cookie jar as admin, so a genuinely anonymous call needs a
        // completely separate, never-logged-in jar -- a guest session
        // still has a valid pwg_token (session.getStatus works
        // unauthenticated), so this exercises setMyInfo()'s own
        // AccessControl::isAGuest() gate, not just a missing-token
        // rejection.
        $jar = tempnam(sys_get_temp_dir(), 'pwg_ct_guest_');
        self::assertNotFalse($jar);
        $status = $this->wsWithCookieJar($jar, 'pwg.session.getStatus');
        $statusResult = $status['result'];
        self::assertIsArray($statusResult);
        $token = $statusResult['pwg_token'] ?? null;
        self::assertIsString($token);

        $response = $this->wsWithCookieJar($jar, 'pwg.users.setMyInfo', [
            'nb_image_page' => 24,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Access Denied', $response['message']);
    }

    public function test_setMyInfo_wrong_current_password_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWsAllowingServerError('pwg.users.setMyInfo', [
            'password' => 'definitely-wrong-password',
            'new_password' => 'NewPass123!',
            'conf_new_password' => 'NewPass123!',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Current password is wrong', $response['message']);
    }

    public function test_setMyInfo_new_password_confirmation_mismatch_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWsAllowingServerError('pwg.users.setMyInfo', [
            'password' => 'fixture_admin',
            'new_password' => 'NewPass123!',
            'conf_new_password' => 'SomethingElse456!',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('The passwords do not match', $response['message']);
    }

    public function test_setMyInfo_changes_password_successfully(): void
    {
        $userId = $this->createUser('ct_pwchange_' . uniqid(), 'OldPass123!');
        $token = $this->getPwgToken();
        // switch the session onto the throwaway user before changing their
        // own password
        $login = $this->callWs('pwg.session.login', [
            'username' => $this->conn->fetchOne('SELECT username FROM ' . Tables::users() . ' WHERE id = ?', [$userId]),
            'password' => 'OldPass123!',
        ]);
        self::assertSame('ok', $login['stat']);
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.setMyInfo', [
            'password' => 'OldPass123!',
            'new_password' => 'NewPass456!',
            'conf_new_password' => 'NewPass456!',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('Your changes have been applied.', $response['result']);

        // log back in as admin so tearDown()'s own delete calls are authorized
        $this->loginAsAdmin();
        $reLogin = $this->callWs('pwg.session.login', [
            'username' => $this->conn->fetchOne('SELECT username FROM ' . Tables::users() . ' WHERE id = ?', [$userId]),
            'password' => 'NewPass456!',
        ]);
        self::assertSame('ok', $reLogin['stat'], 'the new password must actually authenticate');
    }

    /**
     * allow_user_customization=false unsets nb_image_page/theme/language/
     * recent_period/expand/show_nb_comments/show_nb_hits from $params
     * before checkAndSaveUserInfos() runs -- a requested theme change is
     * silently ignored rather than erroring.
     */
    public function test_setMyInfo_ignores_theme_when_user_customization_is_disabled(): void
    {
        $this->conn->executeStatement(
            "INSERT INTO " . Tables::config() . " (param, value) VALUES ('allow_user_customization', 'false')
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        \Piwigo\Cache\CachePools::config()->clear();

        try {
            $token = $this->getPwgToken();
            $before = $this->conn->fetchOne('SELECT theme FROM ' . Tables::userInfos() . ' WHERE user_id = 1');

            $response = $this->callWs('pwg.users.setMyInfo', [
                'theme' => 'a_theme_that_should_be_ignored',
                'pwg_token' => $token,
            ]);

            self::assertSame('ok', $response['stat']);
            $after = $this->conn->fetchOne('SELECT theme FROM ' . Tables::userInfos() . ' WHERE user_id = 1');
            self::assertSame($before, $after);
        } finally {
            $this->conn->executeStatement("DELETE FROM " . Tables::config() . " WHERE param = 'allow_user_customization'");
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }

    // -------------------------------------------------------- preferencesSet

    public function test_preferencesSet_invalid_param_name_returns_error(): void
    {
        $response = $this->callWs('pwg.users.preferences.set', [
            'param' => 'not a valid name!',
            'value' => 'x',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
    }

    public function test_preferencesSet_stores_a_json_value(): void
    {
        $response = $this->callWs('pwg.users.preferences.set', [
            'param' => 'ct_pref_' . uniqid(),
            'value' => json_encode(['a' => 1, 'b' => 2]),
            'is_json' => true,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
    }

    // ------------------------------------------------------------ favorites

    public function test_favoritesAdd_guest_forbidden(): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'pwg_ct_guest_');
        self::assertNotFalse($jar);

        $response = $this->wsWithCookieJar($jar, 'pwg.users.favorites.add', ['image_id' => 1]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('User must be logged in.', $response['message']);
    }

    public function test_favoritesAdd_unknown_image_returns_404(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.favorites.add', ['image_id' => 999999]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('image_id not found', $response['message']);
    }

    public function test_favoritesAdd_and_remove_roundtrip(): void
    {
        $imageId = $this->insertThrowawayImage();
        $selfId = $this->conn->fetchOne('SELECT id FROM ' . Tables::users() . ' WHERE username = ?', ['fixture_admin']);
        self::assertIsNumeric($selfId);
        $selfId = (int) $selfId;

        $add = $this->callWs('pwg.users.favorites.add', ['image_id' => $imageId]);
        self::assertSame('ok', $add['stat']);

        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::favorites() . ' WHERE user_id = ? AND image_id = ?',
            [$selfId, $imageId]
        );
        self::assertIsNumeric($count);
        self::assertSame(1, (int) $count);

        $remove = $this->callWs('pwg.users.favorites.remove', ['image_id' => $imageId]);
        self::assertSame('ok', $remove['stat']);

        $countAfter = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM ' . Tables::favorites() . ' WHERE user_id = ? AND image_id = ?',
            [$selfId, $imageId]
        );
        self::assertIsNumeric($countAfter);
        self::assertSame(0, (int) $countAfter);
    }

    public function test_favoritesRemove_guest_forbidden(): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'pwg_ct_guest_');
        self::assertNotFalse($jar);

        $response = $this->wsWithCookieJar($jar, 'pwg.users.favorites.remove', ['image_id' => 1]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('User must be logged in.', $response['message']);
    }

    public function test_favoritesRemove_unknown_image_returns_404(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.favorites.remove', ['image_id' => 999999]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('image_id not found', $response['message']);
    }

    // ---------------------------------------------------- generatePasswordLink

    public function test_generatePasswordLink_invalid_token_returns_error(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.generatePasswordLink', [
            'user_id' => $userId,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_generatePasswordLink_unknown_user_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.generatePasswordLink', [
            'user_id' => 999999,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This user does not exist.', $response['message']);
    }

    public function test_generatePasswordLink_guest_target_returns_error(): void
    {
        $token = $this->getPwgToken();
        $guestId = \Piwigo\Config\CurrentConfig::guestId();

        $response = $this->callWsAllowingServerError('pwg.users.generatePasswordLink', [
            'user_id' => $guestId,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Password reset is not allowed for this user', $response['message']);
    }

    public function test_generatePasswordLink_returns_link_for_regular_user(): void
    {
        $token = $this->getPwgToken();
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWs('pwg.users.generatePasswordLink', [
            'user_id' => $userId,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
        self::assertIsString($result['generated_link'] ?? null);
        self::assertIsString($result['time_validation'] ?? null);
    }

    // --------------------------------------------------------- setMainUser

    public function test_setMainUser_invalid_token_returns_error(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.setMainUser', [
            'user_id' => 1,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_setMainUser_unknown_user_returns_error(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.setMainUser', [
            'user_id' => 999999,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('This user does not exist.', $response['message']);
    }

    public function test_setMainUser_non_webmaster_target_returns_error(): void
    {
        $token = $this->getPwgToken();
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.setMainUser', [
            'user_id' => $userId,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('This user cannot become a main user because he is not a webmaster.', $response['message']);
    }

    public function test_setMainUser_promotes_a_webmaster(): void
    {
        $originalWebmasterId = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'webmaster_id'");
        self::assertIsString($originalWebmasterId);

        $token = $this->getPwgToken();
        $userId = $this->createUser('ct_user_' . uniqid());
        $setStatus = $this->callWs('pwg.users.setInfo', [
            'user_id' => $userId,
            'status' => 'webmaster',
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $setStatus['stat']);

        try {
            $response = $this->callWs('pwg.users.setMainUser', [
                'user_id' => $userId,
                'pwg_token' => $token,
            ]);

            self::assertSame('ok', $response['stat']);
            self::assertSame('The main user has been changed.', $response['result']);

            $stored = $this->conn->fetchOne("SELECT value FROM " . Tables::config() . " WHERE param = 'webmaster_id'");
            self::assertSame((string) $userId, $stored);
        } finally {
            $this->conn->executeStatement(
                "UPDATE " . Tables::config() . " SET value = ? WHERE param = 'webmaster_id'",
                [$originalWebmasterId]
            );
            \Piwigo\Cache\CachePools::config()->clear();
        }
    }

    // ------------------------------------------------------------- api_key

    /**
     * Every api_key.* method requires ApiKeyService::connectedWithPwgUi() --
     * this class's own setUp() already authenticates the shared cookie jar
     * via a WS-based pwg.session.login, and identification.php's real login
     * flow (loginAsAdminViaUI()) doesn't flip $_SESSION['connected_with'] to
     * 'pwg_ui' on a session that's already authenticated some other way.
     * A completely fresh, separate cookie jar sidesteps that entirely --
     * same rationale as WsSessionTest::test_login_via_pkid_authentication_key's
     * own dedicated jar.
     *
     * @return array{jar: string, token: string}
     */
    private function apiKeySession(): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'pwg_ct_apikey_');
        self::assertNotFalse($cookieJar);

        $url = $this->baseUrl . '/identification.php';

        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        curl_exec($ch);
        unset($ch);

        $ch = curl_init($url);
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'login' => '1',
            'username' => 'fixture_admin',
            'password' => 'fixture_admin',
        ]));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        self::assertIsString($body);
        self::assertSame(302, $status, 'UI login failed — expected redirect, got HTTP ' . $status . ': ' . $body);

        $status = $this->wsWithCookieJar($cookieJar, 'pwg.session.getStatus');
        $statusResult = $status['result'];
        self::assertIsArray($statusResult);
        $token = $statusResult['pwg_token'] ?? null;
        self::assertIsString($token);

        return ['jar' => $cookieJar, 'token' => $token];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function wsWithCookieJar(string $cookieJar, string $method, array $params = []): array
    {
        assert($cookieJar !== '');
        $ch = curl_init($this->baseUrl . '/ws.php?format=json');
        self::assertNotFalse($ch);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge(['method' => $method], $params)));
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->testHeader());

        $body = curl_exec($ch);
        unset($ch);

        self::assertIsString($body);
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public function test_createApiKey_invalid_token_returns_error(): void
    {
        ['jar' => $jar] = $this->apiKeySession();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.create', [
            'key_name' => 'ct key ' . uniqid(),
            'duration' => 1,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_createApiKey_invalid_duration_returns_error(): void
    {
        ['jar' => $jar, 'token' => $token] = $this->apiKeySession();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.create', [
            'key_name' => 'ct key ' . uniqid(),
            'duration' => 1000000,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(400, $response['err']);
        self::assertSame('Invalid duration max days is 999999', $response['message']);
    }

    public function test_createApiKey_key_name_too_long_returns_error(): void
    {
        ['jar' => $jar, 'token' => $token] = $this->apiKeySession();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.create', [
            'key_name' => str_repeat('x', 101),
            'duration' => 1,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(400, $response['err']);
        self::assertSame('Key name is too long', $response['message']);
    }

    /** @return array{jar: string, pkid: string, token: string} */
    private function createRealApiKey(): array
    {
        ['jar' => $jar, 'token' => $token] = $this->apiKeySession();
        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.create', [
            'key_name' => 'ct key ' . uniqid(),
            'duration' => 1,
            'pwg_token' => $token,
        ]);
        self::assertSame('ok', $response['stat'], 'api_key.create failed: ' . json_encode($response));
        $result = $response['result'];
        self::assertIsArray($result);
        $pkid = $result['auth_key'] ?? null;
        self::assertIsString($pkid);

        return ['jar' => $jar, 'pkid' => $pkid, 'token' => $token];
    }

    public function test_editApiKey_invalid_pkid_format_returns_error(): void
    {
        ['jar' => $jar, 'token' => $token] = $this->apiKeySession();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.edit', [
            'key_name' => 'renamed',
            'pkid' => 'not-a-real-pkid',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_editApiKey_edits_the_name(): void
    {
        ['jar' => $jar, 'pkid' => $pkid, 'token' => $token] = $this->createRealApiKey();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.edit', [
            'key_name' => 'renamed key',
            'pkid' => $pkid,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('API Key has been successfully edited.', $response['result']);
    }

    public function test_revokeApiKey_invalid_pkid_format_returns_error(): void
    {
        ['jar' => $jar, 'token' => $token] = $this->apiKeySession();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.revoke', [
            'pkid' => 'not-a-real-pkid',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function test_revokeApiKey_revokes_the_key(): void
    {
        ['jar' => $jar, 'pkid' => $pkid, 'token' => $token] = $this->createRealApiKey();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.revoke', [
            'pkid' => $pkid,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('API Key has been successfully revoked.', $response['result']);
    }

    public function test_getApiKey_guest_forbidden(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.api_key.get', ['pwg_token' => 'wrong']);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function test_getApiKey_returns_the_list(): void
    {
        ['jar' => $jar, 'token' => $token] = $this->createRealApiKey();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.get', ['pwg_token' => $token]);

        self::assertSame('ok', $response['stat']);
        self::assertIsArray($response['result']);
        self::assertNotEmpty($response['result']);
    }
}
