<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
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

        Config::reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new UserRepository($this->conn);
    }

    public function test_find_id_by_username_returns_a_fixture_user(): void
    {
        self::assertSame(1, $this->repo->findIdByUsername('fixture_admin', 'id', 'username'));
        self::assertFalse($this->repo->findIdByUsername('does-not-exist', 'id', 'username'));
    }

    public function test_find_username_by_id_returns_a_fixture_user(): void
    {
        self::assertSame('fixture_admin', $this->repo->findUsernameById(1, 'id', 'username'));
    }

    public function test_find_username_by_id_returns_null_for_a_nonexistent_user(): void
    {
        self::assertNull($this->repo->findUsernameById(999999, 'id', 'username'));
    }

    public function test_find_id_by_email_returns_a_fixture_user(): void
    {
        self::assertSame(1, $this->repo->findIdByEmail('fixture_admin@example.test', 'id', 'mail_address'));
        self::assertFalse($this->repo->findIdByEmail('nobody@example.test', 'id', 'mail_address'));
    }

    public function test_find_id_by_email_is_case_insensitive(): void
    {
        self::assertSame(1, $this->repo->findIdByEmail('FIXTURE_ADMIN@EXAMPLE.TEST', 'id', 'mail_address'));
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
        self::assertTrue($this->repo->usernameExistsCaseInsensitive('FIXTURE_ADMIN', 'username'));
        self::assertFalse($this->repo->usernameExistsCaseInsensitive('nope', 'username'));
    }

    public function test_email_exists(): void
    {
        self::assertTrue($this->repo->emailExists('fixture_admin@example.test', 'mail_address', 'id', null));
        self::assertFalse($this->repo->emailExists('nobody@example.test', 'mail_address', 'id', null));
    }

    public function test_email_exists_excludes_the_given_user_id(): void
    {
        self::assertFalse($this->repo->emailExists('fixture_admin@example.test', 'mail_address', 'id', 1));
        self::assertTrue($this->repo->emailExists('fixture_admin@example.test', 'mail_address', 'id', 2));
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

        self::assertSame($id, $this->repo->findIdByUsername($username, 'id', 'username'));

        $this->conn->executeStatement('DELETE FROM ' . Tables::users() . ' WHERE id = ' . $id);
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
        self::assertSame('normal', $row['status']);

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

    public function test_save_preferences_persists_the_serialized_value(): void
    {
        $this->repo->savePreferences(1, serialize(['theme' => 'dark']));

        $value = $this->conn->createQueryBuilder()
            ->select('preferences')
            ->from(Tables::userInfos())
            ->where('user_id = 1')
            ->executeQuery()
            ->fetchOne();

        self::assertIsString($value);
        self::assertSame(['theme' => 'dark'], unserialize($value));

        $this->conn->executeStatement('UPDATE ' . Tables::userInfos() . " SET preferences = NULL WHERE user_id = 1");
    }
}
