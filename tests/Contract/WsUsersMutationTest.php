<?php

declare(strict_types=1);

namespace Piwigo\Tests\Contract;

use Doctrine\DBAL\Connection;
use Override;
use Piwigo\Db\DbConnection;

final class WsUsersMutationTest extends ContractTestCase
{
    private ?int $userId = null;

    private Connection $conn;

    /**
     * @var list<int>
     */
    private array $extraUserIdsToDelete = [];

    /**
     * @var list<int>
     */
    private array $imageIdsToDelete = [];

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
        $token = $this->getPwgToken();

        if ($this->userId !== null) {
            $this->callWs('pwg.users.delete', [
                'user_id' => [$this->userId],
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
            $this->conn->executeStatement('DELETE FROM favorites WHERE image_id = ?', [$imageId]);
            $this->conn->executeStatement('DELETE FROM images WHERE id = ?', [$imageId]);
        }

        parent::tearDown();
    }

    /**
     * Creates a real user via the WS API and marks it for cleanup in tearDown().
     */
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
     * ContractTestCase never boots a Kernel in-process (every real request
     * goes through the actual HTTP server under test, a separate process
     * with its own container) -- reads the real, DB-backed guest_id
     * config value directly, matching this file's own established
     * "query 'config' over SQL" idiom, and falling back to
     * CurrentConfig::guestId()'s own compiled-in default (2) for the
     * common case where the fixture never overrides it, the same
     * fallback ConfigService::loadConfFromDb() itself would apply for a
     * genuinely missing row.
     */
    private function guestId(): int
    {
        $raw = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'guest_id'");
        if (! is_string($raw)) {
            return 2;
        }
        $decoded = json_decode($raw, true);

        return is_int($decoded) ? $decoded : 2;
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
            'INSERT INTO images (file, path, md5sum, date_available) VALUES (?, ?, ?, ?)',
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

    public function testAddReturnsUserListShape(): void
    {
        $token = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $response = $this->callWs('pwg.users.add', [
            'username' => $username,
            'password' => 'Test1234!',
            'email' => $username . '@test.local',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);

        $this->userId = self::firstUserId($response);
    }

    public function testSetInfoReturnsUserListShape(): void
    {
        $token = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $add = $this->callWs('pwg.users.add', [
            'username' => $username,
            'password' => 'Test1234!',
            'pwg_token' => $token,
        ]);
        $this->userId = self::firstUserId($add);

        $response = $this->callWs('pwg.users.setInfo', [
            'user_id' => $this->userId,
            'status' => 'normal',
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertMatchesSchema('users.getList', $response);
    }

    public function testSetMyInfoReturnsOk(): void
    {
        $token = $this->getPwgToken();
        $response = $this->callWs('pwg.users.setMyInfo', [
            'nb_image_page' => 24,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function testPreferencesSetReturnsOk(): void
    {
        $response = $this->callWs('pwg.users.preferences.set', [
            'param' => 'nb_image_page',
            'value' => 20,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    public function testDeleteReturnsOk(): void
    {
        $token = $this->getPwgToken();
        $username = 'ct_user_' . uniqid();
        $add = $this->callWs('pwg.users.add', [
            'username' => $username,
            'password' => 'Test1234!',
            'pwg_token' => $token,
        ]);
        $id = self::firstUserId($add);

        $response = $this->callWs('pwg.users.delete', [
            'user_id' => [$id],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
    }

    // ------------------------------------------------------------------ add

    public function testAddInvalidTokenReturnsError(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.add', [
            'username' => 'ct_user_' . uniqid(),
            'password' => 'Test1234!',
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testAddEmptyUsernameReturnsError(): void
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

    public function testAddMissingPasswordReturnsError(): void
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
    public function testAddPasswordConfirmationMismatchReturnsError(): void
    {
        $this->upsertConfig('double_password_type_in_admin', 'true');
        $this->configCachePool()
            ->clear();
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
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'double_password_type_in_admin'");
            $this->configCachePool()
                ->clear();
        }
    }

    public function testAddAutoPasswordGeneratesOne(): void
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

        $password = $this->conn->fetchOne('SELECT password FROM users WHERE id = ?', [$id]);
        self::assertIsString($password);
        self::assertNotSame('', $password, 'auto_password must generate a real, non-empty hash');
    }

    public function testAddDuplicateUsernameReturnsError(): void
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

    public function testGetAuthKeyInvalidTokenReturnsError(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.getAuthKey', [
            'user_id' => $userId,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testGetAuthKeyUnknownUserReturnsError(): void
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

    public function testGetAuthKeyReturnsKeyShape(): void
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

    public function testDeleteInvalidTokenReturnsError(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.delete', [
            'user_id' => [$userId],
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testDeleteProtectsTheCurrentUserFromSelfDeletion(): void
    {
        $token = $this->getPwgToken();
        $selfId = $this->conn->fetchOne('SELECT id FROM users WHERE username = ?', ['fixture_admin']);

        $response = $this->callWs('pwg.users.delete', [
            'user_id' => [$selfId],
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('0 users deleted', $response['result']);

        $stillExists = $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [$selfId]);
        self::assertSame(1, $stillExists);
    }

    public function testDeleteProtectsTheGuestId(): void
    {
        $token = $this->getPwgToken();
        $guestId = $this->guestId();

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
    public function testDeleteAsANonWebmasterAdminAlsoProtectsOtherAdmins(): void
    {
        $adminToken = $this->getPwgToken();
        $selfId = $this->conn->fetchOne('SELECT id FROM users WHERE username = ?', ['fixture_admin']);
        self::assertIsNumeric($selfId);

        $username = 'ct_nonwm_admin_' . uniqid();
        $password = 'Test1234!';
        $newAdminId = $this->createUser($username, $password);
        $this->callWs('pwg.users.setInfo', [
            'user_id' => $newAdminId,
            'status' => 'admin',
            'pwg_token' => $adminToken,
        ]);

        $login = $this->callWs('pwg.session.login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertSame('ok', $login['stat']);
        $nonWebmasterToken = $this->getPwgToken();

        $response = $this->callWs('pwg.users.delete', [
            'user_id' => [$selfId],
            'pwg_token' => $nonWebmasterToken,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('0 users deleted', $response['result']);
        $stillExists = $this->conn->fetchOne('SELECT COUNT(*) FROM users WHERE id = ?', [$selfId]);
        self::assertSame(1, $stillExists);

        $this->loginAsAdmin();
    }

    // -------------------------------------------------------------- setInfo

    public function testSetInfoInvalidTokenReturnsError(): void
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

    public function testSetInfoDuplicateUsernameReturnsError(): void
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

    public function testSetMyInfoInvalidTokenReturnsError(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.setMyInfo', [
            'nb_image_page' => 24,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testSetMyInfoGuestForbidden(): void
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

    public function testSetMyInfoWrongCurrentPasswordReturnsError(): void
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

    public function testSetMyInfoNewPasswordConfirmationMismatchReturnsError(): void
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

    public function testSetMyInfoChangesPasswordSuccessfully(): void
    {
        $userId = $this->createUser('ct_pwchange_' . uniqid(), 'OldPass123!');
        $token = $this->getPwgToken();
        // switch the session onto the throwaway user before changing their
        // own password
        $login = $this->callWs('pwg.session.login', [
            'username' => $this->conn->fetchOne('SELECT username FROM users WHERE id = ?', [$userId]),
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
            'username' => $this->conn->fetchOne('SELECT username FROM users WHERE id = ?', [$userId]),
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
    public function testSetMyInfoIgnoresThemeWhenUserCustomizationIsDisabled(): void
    {
        $this->upsertConfig('allow_user_customization', 'false');
        $this->configCachePool()
            ->clear();
        try {
            $token = $this->getPwgToken();
            $before = $this->conn->fetchOne('SELECT theme FROM user_infos WHERE user_id = 1');

            $response = $this->callWs('pwg.users.setMyInfo', [
                'theme' => 'a_theme_that_should_be_ignored',
                'pwg_token' => $token,
            ]);

            self::assertSame('ok', $response['stat']);
            $after = $this->conn->fetchOne('SELECT theme FROM user_infos WHERE user_id = 1');
            self::assertSame($before, $after);
        } finally {
            $this->conn->executeStatement("DELETE FROM config WHERE param = 'allow_user_customization'");
            $this->configCachePool()
                ->clear();
        }
    }

    /**
     * activate_comments=false unsets show_nb_comments from $params before
     * checkAndSaveUserInfos() runs -- same "silently ignored, not applied"
     * shape as allow_user_customization above, but for a different config
     * flag/field pair. fixture_admin's show_nb_comments starts at 0 (see
     * the fixture's own user_infos row), so requesting `true` here
     * would be a real, detectable change if it weren't dropped.
     */
    public function testSetMyInfoIgnoresShowNbCommentsWhenCommentsAreDisabled(): void
    {
        $this->conn->executeStatement(
            "UPDATE config SET value = 'false' WHERE param = 'activate_comments'"
        );
        $this->configCachePool()
            ->clear();
        try {
            $token = $this->getPwgToken();
            $before = $this->conn->fetchOne('SELECT show_nb_comments FROM user_infos WHERE user_id = 1');

            $response = $this->callWs('pwg.users.setMyInfo', [
                'show_nb_comments' => true,
                'pwg_token' => $token,
            ]);

            self::assertSame('ok', $response['stat']);
            $after = $this->conn->fetchOne('SELECT show_nb_comments FROM user_infos WHERE user_id = 1');
            self::assertSame($before, $after, 'show_nb_comments must be silently dropped, not applied, while comments are disabled gallery-wide');
        } finally {
            $this->conn->executeStatement("UPDATE config SET value = 'true' WHERE param = 'activate_comments'");
            $this->configCachePool()
                ->clear();
        }
    }

    /**
     * SPECIAL_USER unsets password/theme/language whenever the *currently
     * logged-in* user's own id equals CurrentConfig::defaultUserId() (the
     * account whose settings serve as defaults for new registrations) --
     * a distinct condition from AccessControl::isAGuest() (checked by
     * session *status*, not id), so a real, logged-in 'normal'-status
     * throwaway user can hit it by pointing default_user_id at their own
     * id without ever being treated as a guest.
     */
    public function testSetMyInfoSpecialUserBranchDropsPasswordThemeAndLanguage(): void
    {
        $username = 'ct_special_' . uniqid();
        $password = 'Test1234!';
        $userId = $this->createUser($username, $password);

        $originalDefaultUserId = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'default_user_id'");

        $this->upsertConfig('default_user_id', (string) $userId);
        $this->configCachePool()
            ->clear();
        try {
            $before = $this->conn->fetchAssociative(
                'SELECT theme, language FROM user_infos WHERE user_id = ?',
                [$userId]
            );
            self::assertIsArray($before);

            $login = $this->callWs('pwg.session.login', [
                'username' => $username,
                'password' => $password,
            ]);
            self::assertSame('ok', $login['stat']);
            $token = $this->getPwgToken();

            $response = $this->callWs('pwg.users.setMyInfo', [
                'password' => $password,
                'new_password' => 'ShouldBeIgnored123!',
                'conf_new_password' => 'ShouldBeIgnored123!',
                'theme' => 'a_theme_that_should_be_ignored',
                'language' => 'a_lang_that_should_be_ignored',
                'pwg_token' => $token,
            ]);

            self::assertSame('ok', $response['stat'], 'SPECIAL_USER must silently drop password/theme/language, not error');
            self::assertSame('Your changes have been applied.', $response['result']);

            $after = $this->conn->fetchAssociative(
                'SELECT theme, language FROM user_infos WHERE user_id = ?',
                [$userId]
            );
            self::assertSame($before, $after, 'theme/language must be unchanged -- SPECIAL_USER must have dropped them');

            $this->loginAsAdmin();
            $reLogin = $this->callWs('pwg.session.login', [
                'username' => $username,
                'password' => $password,
            ]);
            self::assertSame('ok', $reLogin['stat'], 'password must be unchanged -- SPECIAL_USER must have dropped it too');
        } finally {
            $this->loginAsAdmin();
            if ($originalDefaultUserId === false) {
                $this->conn->executeStatement("DELETE FROM config WHERE param = 'default_user_id'");
            } else {
                $this->conn->executeStatement(
                    "UPDATE config SET value = ? WHERE param = 'default_user_id'",
                    [$originalDefaultUserId]
                );
            }
            $this->configCachePool()
                ->clear();
        }
    }

    /**
     * setMyInfo()'s error branch (checkAndSaveUserInfos() returning
     * ['error' => [...]]) is reachable through a malformed 'email' --
     * 'email' is the only field setMyInfo() ever forwards that
     * checkAndSaveUserInfos() actually validates (username/status/level/
     * group_id/enabled_high are always unset by setMyInfo() itself before
     * that call).
     */
    public function testSetMyInfoInvalidEmailFormatReturnsError(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWs('pwg.users.setMyInfo', [
            'email' => 'not-a-valid-email',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
        self::assertSame('mail address must be like xxx@yyy.eee (example: jack@altern.org)', $response['message']);
    }

    // -------------------------------------------------------- preferencesSet

    public function testPreferencesSetInvalidParamNameReturnsError(): void
    {
        $response = $this->callWs('pwg.users.preferences.set', [
            'param' => 'not a valid name!',
            'value' => 'x',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(1003, $response['err']);
    }

    public function testPreferencesSetStoresAJsonValue(): void
    {
        $response = $this->callWs('pwg.users.preferences.set', [
            'param' => 'ct_pref_' . uniqid(),
            'value' => json_encode([
                'a' => 1,
                'b' => 2,
            ]),
            'is_json' => true,
        ]);

        self::assertSame('ok', $response['stat']);
        $result = $response['result'];
        self::assertIsArray($result);
    }

    // ------------------------------------------------------------ favorites

    public function testFavoritesAddGuestForbidden(): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'pwg_ct_guest_');
        self::assertNotFalse($jar);

        $response = $this->wsWithCookieJar($jar, 'pwg.users.favorites.add', [
            'image_id' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('User must be logged in.', $response['message']);
    }

    public function testFavoritesAddUnknownImageReturns404(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.favorites.add', [
            'image_id' => 999999,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('image_id not found', $response['message']);
    }

    public function testFavoritesAddAndRemoveRoundtrip(): void
    {
        $imageId = $this->insertThrowawayImage();
        $selfId = $this->conn->fetchOne('SELECT id FROM users WHERE username = ?', ['fixture_admin']);

        $add = $this->callWs('pwg.users.favorites.add', [
            'image_id' => $imageId,
        ]);
        self::assertSame('ok', $add['stat']);

        $count = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM favorites WHERE user_id = ? AND image_id = ?',
            [$selfId, $imageId]
        );
        self::assertSame(1, $count);

        $remove = $this->callWs('pwg.users.favorites.remove', [
            'image_id' => $imageId,
        ]);
        self::assertSame('ok', $remove['stat']);

        $countAfter = $this->conn->fetchOne(
            'SELECT COUNT(*) FROM favorites WHERE user_id = ? AND image_id = ?',
            [$selfId, $imageId]
        );
        self::assertSame(0, $countAfter);
    }

    public function testFavoritesRemoveGuestForbidden(): void
    {
        $jar = tempnam(sys_get_temp_dir(), 'pwg_ct_guest_');
        self::assertNotFalse($jar);

        $response = $this->wsWithCookieJar($jar, 'pwg.users.favorites.remove', [
            'image_id' => 1,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('User must be logged in.', $response['message']);
    }

    public function testFavoritesRemoveUnknownImageReturns404(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.favorites.remove', [
            'image_id' => 999999,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(404, $response['err']);
        self::assertSame('image_id not found', $response['message']);
    }

    // ---------------------------------------------------- generatePasswordLink

    public function testGeneratePasswordLinkInvalidTokenReturnsError(): void
    {
        $userId = $this->createUser('ct_user_' . uniqid());

        $response = $this->callWsAllowingServerError('pwg.users.generatePasswordLink', [
            'user_id' => $userId,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testGeneratePasswordLinkUnknownUserReturnsError(): void
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

    public function testGeneratePasswordLinkGuestTargetReturnsError(): void
    {
        $token = $this->getPwgToken();
        $guestId = $this->guestId();

        $response = $this->callWsAllowingServerError('pwg.users.generatePasswordLink', [
            'user_id' => $guestId,
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('Password reset is not allowed for this user', $response['message']);
    }

    public function testGeneratePasswordLinkReturnsLinkForRegularUser(): void
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

    /**
     * fixture_admin is 'webmaster' (see WsUsersMutationTest's own
     * test_delete_as_a_non_webmaster_admin_also_protects_other_admins
     * docblock), which is always allowed to target another webmaster --
     * only a non-webmaster 'admin' session exercises generatePasswordLink()'s
     * own "only webmaster can reset another webmaster's password" guard.
     */
    public function testGeneratePasswordLinkAdminCannotTargetAWebmaster(): void
    {
        $adminToken = $this->getPwgToken();
        $selfId = $this->conn->fetchOne('SELECT id FROM users WHERE username = ?', ['fixture_admin']);
        self::assertIsNumeric($selfId);

        $username = 'ct_nonwm_admin_' . uniqid();
        $password = 'Test1234!';
        $newAdminId = $this->createUser($username, $password);
        $this->callWs('pwg.users.setInfo', [
            'user_id' => $newAdminId,
            'status' => 'admin',
            'pwg_token' => $adminToken,
        ]);

        $login = $this->callWs('pwg.session.login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertSame('ok', $login['stat']);
        $nonWebmasterToken = $this->getPwgToken();

        $response = $this->callWsAllowingServerError('pwg.users.generatePasswordLink', [
            'user_id' => $selfId,
            'pwg_token' => $nonWebmasterToken,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('You cannot perform this action', $response['message']);

        $this->loginAsAdmin();
    }

    /**
     * send_by_mail=true drives generatePasswordLink() through both of its
     * own mail-content branches on the *same* throwaway user:
     * AuthService::hasAlreadyLoggedIn() (misleadingly named -- true means
     * "no login history yet") starts true for a brand-new user, selecting
     * generateSetPasswordMail(); logging that user in once (recording a
     * real 'login' activity row -- see AuthService::authenticate()) flips
     * it to false, selecting generateResetPasswordMail() on the second
     * call instead.
     *
     * Both calls point smtp_host at a real, unreachable-but-instantly-
     * refusing loopback address (127.0.0.1:1 -- nothing ever listens on
     * TCP port 1) so MailService::mail()'s real Symfony EsmtpTransport
     * genuinely attempts, and genuinely fails, a real connection, landing
     * on the `false` result branch -- deterministically and in
     * milliseconds, unlike this sandbox's default `native://default`
     * sendmail_path transport, whose real local `sendmail` binary hangs
     * for many seconds trying to actually deliver (confirmed empirically:
     * `sendmail -t -i` against a throwaway message did not return within
     * 12s here; see also RegisterControllerTest's own docblock for the
     * same real transport, now bounded by
     * Piwigo\Mail\BoundedSendmailTransport). This sandbox has no reachable
     * SMTP relay at all, so the *success* branch
     * ($send_by_mail_response = 'Mail sent at : ...') is not reachable
     * through any real send from here without standing up dedicated mail
     * infrastructure this suite has no existing precedent for -- left
     * genuinely untested; called out explicitly in this task's own
     * coverage-closure report rather than silently skipped.
     */
    public function testGeneratePasswordLinkSendByMailCoversBothFirstLoginAndReturningUser(): void
    {
        $token = $this->getPwgToken();
        $username = 'ct_pwdlink_' . uniqid();
        $password = 'Test1234!';
        $email = $username . '@example.test';
        $userId = $this->createUser($username, $password, $email);

        $originalSmtpHost = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'smtp_host'");
        $this->upsertConfig('smtp_host', '"127.0.0.1:1"');
        $this->configCachePool()
            ->clear();
        try {
            $first = $this->callWs('pwg.users.generatePasswordLink', [
                'user_id' => $userId,
                'send_by_mail' => true,
                'pwg_token' => $token,
            ]);
            self::assertSame('ok', $first['stat']);
            $firstResult = $first['result'];
            self::assertIsArray($firstResult);
            self::assertArrayHasKey('send_by_mail', $firstResult);
            self::assertFalse($firstResult['send_by_mail']);

            $login = $this->callWs('pwg.session.login', [
                'username' => $username,
                'password' => $password,
            ]);
            self::assertSame('ok', $login['stat']);
            $this->loginAsAdmin();
            $token = $this->getPwgToken();

            $second = $this->callWs('pwg.users.generatePasswordLink', [
                'user_id' => $userId,
                'send_by_mail' => true,
                'pwg_token' => $token,
            ]);
            self::assertSame('ok', $second['stat']);
            $secondResult = $second['result'];
            self::assertIsArray($secondResult);
            self::assertArrayHasKey('send_by_mail', $secondResult);
            self::assertFalse($secondResult['send_by_mail']);
        } finally {
            $this->loginAsAdmin();
            if ($originalSmtpHost === false) {
                $this->conn->executeStatement("DELETE FROM config WHERE param = 'smtp_host'");
            } else {
                $this->conn->executeStatement(
                    "UPDATE config SET value = ? WHERE param = 'smtp_host'",
                    [$originalSmtpHost]
                );
            }
            $this->configCachePool()
                ->clear();
        }
    }

    // --------------------------------------------------------- setMainUser

    public function testSetMainUserInvalidTokenReturnsError(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.setMainUser', [
            'user_id' => 1,
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    /**
     * setMainUser() checks AccessControl::isWebmaster() *before* the
     * pwg_token check (see its own source order) -- the invalid-token test
     * above still passes only because fixture_admin (this class's own
     * setUp() session) already is a webmaster and clears that first guard
     * for free. A real, logged-in non-webmaster 'admin' session is needed
     * to exercise this guard itself.
     */
    public function testSetMainUserForbiddenForANonWebmaster(): void
    {
        $adminToken = $this->getPwgToken();
        $username = 'ct_nonwm_' . uniqid();
        $password = 'Test1234!';
        $newAdminId = $this->createUser($username, $password);
        $this->callWs('pwg.users.setInfo', [
            'user_id' => $newAdminId,
            'status' => 'admin',
            'pwg_token' => $adminToken,
        ]);

        $login = $this->callWs('pwg.session.login', [
            'username' => $username,
            'password' => $password,
        ]);
        self::assertSame('ok', $login['stat']);
        $nonWebmasterToken = $this->getPwgToken();

        $response = $this->callWsAllowingServerError('pwg.users.setMainUser', [
            'user_id' => 1,
            'pwg_token' => $nonWebmasterToken,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
        self::assertSame('You cannot perform this action', $response['message']);

        $this->loginAsAdmin();
    }

    public function testSetMainUserUnknownUserReturnsError(): void
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

    public function testSetMainUserNonWebmasterTargetReturnsError(): void
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

    public function testSetMainUserPromotesAWebmaster(): void
    {
        $originalWebmasterId = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'webmaster_id'");
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

            $stored = $this->conn->fetchOne("SELECT value FROM config WHERE param = 'webmaster_id'");
            self::assertSame((string) $userId, $stored);
        } finally {
            $this->conn->executeStatement(
                "UPDATE config SET value = ? WHERE param = 'webmaster_id'",
                [$originalWebmasterId]
            );
            $this->configCachePool()
                ->clear();
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

        return [
            'jar' => $cookieJar,
            'token' => $token,
        ];
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array_merge([
            'method' => $method,
        ], $params)));
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

    public function testCreateApiKeyInvalidTokenReturnsError(): void
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

    public function testCreateApiKeyInvalidDurationReturnsError(): void
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

    public function testCreateApiKeyKeyNameTooLongReturnsError(): void
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

    /**
     * @return array{jar: string, pkid: string, token: string}
     */
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

        return [
            'jar' => $jar,
            'pkid' => $pkid,
            'token' => $token,
        ];
    }

    public function testEditApiKeyInvalidPkidFormatReturnsError(): void
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

    /**
     * editApiKey() checks AccessControl::isAGuest() and
     * ApiKeyService::connectedWithPwgUi() as two *separate* `if`
     * statements (unlike createApiKey()/revokeApiKey(), which combine
     * both into one `or` condition) -- this class's own setUp() already
     * authenticates the shared cookie jar via pwg.session.login (not
     * identification.php), so isAGuest() is false but connectedWithPwgUi()
     * is still false too, isolating that second guard on its own line.
     */
    public function testEditApiKeyForbiddenWhenNotConnectedViaPwgUi(): void
    {
        $token = $this->getPwgToken();

        $response = $this->callWsAllowingServerError('pwg.users.api_key.edit', [
            'key_name' => 'irrelevant',
            'pkid' => 'pkid-20260101-abcdefghijklmnopqrst',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function testEditApiKeyEditsTheName(): void
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

    public function testRevokeApiKeyInvalidPkidFormatReturnsError(): void
    {
        ['jar' => $jar, 'token' => $token] = $this->apiKeySession();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.revoke', [
            'pkid' => 'not-a-real-pkid',
            'pwg_token' => $token,
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(403, $response['err']);
    }

    public function testRevokeApiKeyRevokesTheKey(): void
    {
        ['jar' => $jar, 'pkid' => $pkid, 'token' => $token] = $this->createRealApiKey();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.revoke', [
            'pkid' => $pkid,
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertSame('API Key has been successfully revoked.', $response['result']);
    }

    public function testGetApiKeyGuestForbidden(): void
    {
        $response = $this->callWsAllowingServerError('pwg.users.api_key.get', [
            'pwg_token' => 'wrong',
        ]);

        self::assertSame('fail', $response['stat']);
        self::assertSame(401, $response['err']);
        self::assertSame('Acces Denied', $response['message']);
    }

    public function testGetApiKeyReturnsTheList(): void
    {
        ['jar' => $jar, 'token' => $token] = $this->createRealApiKey();

        $response = $this->wsWithCookieJar($jar, 'pwg.users.api_key.get', [
            'pwg_token' => $token,
        ]);

        self::assertSame('ok', $response['stat']);
        self::assertIsArray($response['result']);
        self::assertNotEmpty($response['result']);
    }
}
