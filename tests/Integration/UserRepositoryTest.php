<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Users\UserRepository;

final class UserRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private UserRepository $repo;

    private Connection $conn;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        CurrentConfig::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = \Piwigo\Db\EntityManagerFactory::build($this->conn)->getRepository(\Piwigo\Users\UserInfoEntity::class);
    }

    public function test_find_id_by_username_returns_a_fixture_user(): void
    {
        self::assertEquals(\Piwigo\Common\ValueObject\UserId::from(1), $this->repo->findIdByUsername(\Piwigo\Common\ValueObject\Username::from('fixture_admin'), 'id', 'username'));
        self::assertNull($this->repo->findIdByUsername(\Piwigo\Common\ValueObject\Username::from('does-not-exist'), 'id', 'username'));
    }

    public function test_find_username_by_id_returns_a_fixture_user(): void
    {
        self::assertEquals(\Piwigo\Common\ValueObject\Username::from('fixture_admin'), $this->repo->findUsernameById(\Piwigo\Common\ValueObject\UserId::from(1), 'id', 'username'));
    }

    public function test_find_username_by_id_returns_null_for_a_nonexistent_user(): void
    {
        self::assertNull($this->repo->findUsernameById(\Piwigo\Common\ValueObject\UserId::from(999999), 'id', 'username'));
    }

    public function test_find_id_by_email_returns_a_fixture_user(): void
    {
        self::assertEquals(\Piwigo\Common\ValueObject\UserId::from(1), $this->repo->findIdByEmail(\Piwigo\Common\ValueObject\Email::from('fixture_admin@example.test'), 'id', 'mail_address'));
        self::assertNull($this->repo->findIdByEmail(\Piwigo\Common\ValueObject\Email::from('nobody@example.test'), 'id', 'mail_address'));
    }

    public function test_find_id_by_email_is_case_insensitive(): void
    {
        self::assertEquals(\Piwigo\Common\ValueObject\UserId::from(1), $this->repo->findIdByEmail(\Piwigo\Common\ValueObject\Email::from('FIXTURE_ADMIN@EXAMPLE.TEST'), 'id', 'mail_address'));
    }

    public function test_find_by_username_case_insensitive_matches_regardless_of_case(): void
    {
        $found = $this->repo->findByUsernameCaseInsensitive('FIXTURE_ADMIN', 'id', 'username', 'mail_address');

        self::assertNotNull($found);
        self::assertSame('1', $found['id']);
        self::assertSame('fixture_admin', $found['username']);
        self::assertSame('fixture_admin@example.test', $found['email']);
    }

    public function test_find_by_username_case_insensitive_returns_null_when_missing(): void
    {
        self::assertNull($this->repo->findByUsernameCaseInsensitive('nope', 'id', 'username', 'mail_address'));
    }

    public function test_username_exists_case_insensitive(): void
    {
        self::assertTrue($this->repo->usernameExistsCaseInsensitive(\Piwigo\Common\ValueObject\Username::from('FIXTURE_ADMIN'), 'username'));
        self::assertFalse($this->repo->usernameExistsCaseInsensitive(\Piwigo\Common\ValueObject\Username::from('nope'), 'username'));
    }

    public function test_email_exists(): void
    {
        self::assertTrue($this->repo->emailExists(\Piwigo\Common\ValueObject\Email::from('fixture_admin@example.test'), 'mail_address', 'id', null));
        self::assertFalse($this->repo->emailExists(\Piwigo\Common\ValueObject\Email::from('nobody@example.test'), 'mail_address', 'id', null));
    }

    public function test_email_exists_excludes_the_given_user_id(): void
    {
        self::assertFalse($this->repo->emailExists(\Piwigo\Common\ValueObject\Email::from('fixture_admin@example.test'), 'mail_address', 'id', \Piwigo\Common\ValueObject\UserId::from(1)));
        self::assertTrue($this->repo->emailExists(\Piwigo\Common\ValueObject\Email::from('fixture_admin@example.test'), 'mail_address', 'id', \Piwigo\Common\ValueObject\UserId::from(2)));
    }

    public function test_find_all_usernames_includes_fixture_users(): void
    {
        $names = $this->repo->findAllUsernames('username');

        self::assertContains('fixture_admin', $names);
        self::assertContains('guest', $names);
    }

    public function test_insert_user_then_find_id_by_username_round_trips(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));

        $id = $this->repo->insertUser([
            'username' => $username,
            'password' => 'irrelevant-hash',
            'mail_address' => null,
        ]);

        self::assertEquals($id, $this->repo->findIdByUsername(\Piwigo\Common\ValueObject\Username::from($username), 'id', 'username'));

        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id->value);
    }

    public function test_insert_user_infos_then_find_default_user_info_row_round_trips(): void
    {
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insertUser([
            'username' => $username,
            'password' => 'irrelevant-hash',
            'mail_address' => null,
        ]);

        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'registration_date' => '2026-01-01 00:00:00',
            'level' => 0,
        ]);

        $row = $this->repo->findDefaultUserInfoRow($id);

        self::assertNotNull($row);
        self::assertSame('normal', $row->status);

        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id);
    }

    public function test_insert_user_infos_accepts_real_bool_for_the_tinyint_columns(): void
    {
        // User domain Stage 1a: expand/show_nb_comments/show_nb_hits/
        // enabled_high/last_visit_from_history are real tinyint(1)
        // columns now, and UserService::createUserInfos() hands
        // insertUserInfos() a row straight from
        // Projection\UserInfo::toArray() -- real PHP bool, not the old
        // enum('true','false') string. setParameter() with no explicit
        // type binds through mysqli as a plain string, and mysqli's own
        // string-cast of `false` is '' (PHP's own (string) false), which
        // STRICT_TRANS_TABLES rejects as an invalid integer -- confirmed
        // against a real tinyint(1) column before this test was written.
        // `false` (not `true`) is the reproducing value: `(string) true`
        // is '1', a valid numeric string, so only the `false` case ever
        // surfaced this.
        $username = 'p18-test-' . bin2hex(random_bytes(4));
        $id = $this->repo->insertUser([
            'username' => $username,
            'password' => 'irrelevant-hash',
            'mail_address' => null,
        ]);

        $this->repo->insertUserInfos([$id], [
            'status' => 'normal',
            'registration_date' => '2026-01-01 00:00:00',
            'level' => 0,
            'expand' => false,
            'show_nb_comments' => false,
            'show_nb_hits' => false,
            'enabled_high' => true,
            'last_visit_from_history' => false,
        ]);

        $row = $this->repo->findDefaultUserInfoRow($id);

        self::assertNotNull($row);
        self::assertFalse($row->expand);
        self::assertFalse($row->showNbComments);
        self::assertFalse($row->showNbHits);
        self::assertTrue($row->enabledHigh);
        self::assertFalse($row->lastVisitFromHistory);

        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id);
    }

    public function test_insert_user_infos_with_no_ids_is_a_noop(): void
    {
        $countBefore = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        $this->repo->insertUserInfos([], ['status' => 'normal']);

        $countAfter = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        self::assertSame($countBefore, $countAfter);
    }

    public function test_save_preferences_persists_the_json_encoded_value(): void
    {
        $this->repo->savePreferences(\Piwigo\Common\ValueObject\UserId::from(1), ['theme' => 'dark']);

        $value = $this->conn->createQueryBuilder()
            ->select('preferences')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);
        self::assertSame(['theme' => 'dark'], json_decode($value, true));

        $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET preferences = NULL WHERE user_id = 1");
    }

    public function test_save_preferences_is_a_noop_for_a_nonexistent_user(): void
    {
        // No user_infos row exists for this id -- find() returns null and
        // the method returns early rather than persisting a fresh entity.
        $this->repo->savePreferences(\Piwigo\Common\ValueObject\UserId::from(999999), ['theme' => 'dark']);

        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->where('user_id = 999999')
            ->executeQuery()
            ->fetchOne();

        self::assertSame('0', is_scalar($count) ? (string) $count : '');
    }

    public function test_delete_users_from_table_with_no_ids_is_a_noop(): void
    {
        $countBefore = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        $this->repo->deleteUsersFromTable(Tables::userInfos(), []);

        $countAfter = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::userInfos())
            ->executeQuery()
            ->fetchOne();

        self::assertSame($countBefore, $countAfter);
    }

    // findAdminIds()'s `$row['userId'] instanceof UserId` defensive throw
    // (line 336) is not exercised here -- `user_id` is mapped through the
    // 'user_id' custom Doctrine type (see UserInfoEntity), which converts
    // every hydrated value to a real UserId instance unconditionally, DQL
    // scalar selects included. There is no way to make the ORM hand back a
    // non-UserId value through this method's public contract without
    // bypassing the type system entirely (which findAdminIds deliberately
    // doesn't do, precisely so this conversion always applies) -- same
    // "verified untestable without breaking the framework's own guarantee"
    // shape as the HttpClientService-gated skip list, just a different
    // concrete cause.
}
