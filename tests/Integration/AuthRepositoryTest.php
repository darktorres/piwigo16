<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Common\ValueObject\UserId;
use DateTimeImmutable;
use Piwigo\History\HistoryRepository;
use Piwigo\History\HistoryEntity;
use Doctrine\DBAL\Connection;
use Piwigo\Auth\AuthRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;

final class AuthRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private AuthRepository $repo;

    private Connection $conn;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnectionFromEnv();

        if (! self::$fixtureReady) {
            $this->resetDatabase();
            $this->loadFixture(dirname(__DIR__, 2) . '/tests/Fixtures/piwigo-17.0.sql');
            self::$fixtureReady = true;
        }

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $this->conn = DbConnection::build();
        $this->repo = new AuthRepository(EntityManagerFactory::build($this->conn));
    }

    public function test_find_username_and_password_returns_a_fixture_user(): void
    {
        $found = $this->repo->findUsernameAndPassword(UserId::from(1));

        self::assertNotNull($found);
        self::assertSame('fixture_admin', $found->username);
        self::assertStringStartsWith('$2y$', $found->password);
    }

    public function test_find_username_and_password_returns_null_for_a_missing_user(): void
    {
        self::assertNull($this->repo->findUsernameAndPassword(UserId::from(999999)));
    }

    public function test_update_language_persists_the_new_value(): void
    {
        $this->repo->updateLanguage(UserId::from(1), 'fr_FR');

        $value = $this->conn->createQueryBuilder()
            ->select('language')
            ->from('user_infos')
            ->where('user_id = :id')
            ->setParameter('id', 1)
            ->executeQuery()
            ->fetchOne();

        self::assertSame('fr_FR', $value);

        $this->conn->executeStatement(
            'UPDATE ' . 'user_infos' . " SET language = 'en_UK' WHERE user_id = 1"
        );
    }

    public function test_update_language_is_a_no_op_for_a_nonexistent_user(): void
    {
        // 999999 has no user_infos row -- em->find() returns null, so this
        // must return without writing/throwing.
        $this->repo->updateLanguage(UserId::from(999999), 'fr_FR');

        self::expectNotToPerformAssertions();
    }

    public function test_clear_activation_key_is_a_no_op_for_a_nonexistent_user(): void
    {
        $this->repo->clearActivationKey(UserId::from(999999));

        self::expectNotToPerformAssertions();
    }

    public function test_clear_activation_key_nulls_both_columns_for_a_real_user(): void
    {
        $now = new DateTimeImmutable('2026-08-01 00:00:00');
        $this->repo->setActivationKey(UserId::from(4), 'a-hash', $now);

        $this->repo->clearActivationKey(UserId::from(4));

        $row = $this->conn->createQueryBuilder()
            ->select('activation_key', 'activation_key_expire')
            ->from('user_infos')
            ->where('user_id = :id')
            ->setParameter('id', 4)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertNull($row['activation_key']);
        self::assertNull($row['activation_key_expire']);
    }

    public function test_set_activation_key_is_a_no_op_for_a_nonexistent_user(): void
    {
        $this->repo->setActivationKey(UserId::from(999999), 'a-hash', new DateTimeImmutable('2026-08-01 00:00:00'));

        self::expectNotToPerformAssertions();
    }

    public function test_set_activation_key_persists_the_hash_and_formatted_expiry_for_a_real_user(): void
    {
        $expire = new DateTimeImmutable('2026-08-15 12:30:00');

        $this->repo->setActivationKey(UserId::from(4), 'freshly-hashed-value', $expire);

        try {
            $row = $this->conn->createQueryBuilder()
                ->select('activation_key', 'activation_key_expire')
                ->from('user_infos')
                ->where('user_id = :id')
                ->setParameter('id', 4)
                ->executeQuery()
                ->fetchAssociative();

            self::assertIsArray($row);
            self::assertSame('freshly-hashed-value', $row['activation_key']);
            self::assertSame('2026-08-15 12:30:00', $row['activation_key_expire']);
        } finally {
            $this->repo->clearActivationKey(UserId::from(4));
        }
    }

    public function test_find_last_visit_from_history_returns_null_when_the_user_has_no_history_rows(): void
    {
        // Fixture user 4 (power_user) has no history rows -- same fixture
        // shape AuthServiceTest's hasAlreadyLoggedIn() test relies on.
        self::assertNull($this->repo->findLastVisitFromHistory(4, $this->historyLookup()));
    }

    public function test_find_last_visit_from_history_returns_the_most_recent_date_and_time(): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . 'history' . ' (date, time, user_id, IP) VALUES (?, ?, ?, ?)',
            ['2026-07-20', '08:00:00', 4, '10.0.0.5']
        );
        $this->conn->executeStatement(
            'INSERT INTO ' . 'history' . ' (date, time, user_id, IP) VALUES (?, ?, ?, ?)',
            ['2026-07-25', '14:30:00', 4, '10.0.0.5']
        );

        try {
            self::assertSame('2026-07-25 14:30:00', $this->repo->findLastVisitFromHistory(4, $this->historyLookup()));
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . 'history' . ' WHERE user_id = 4');
        }
    }

    private function historyLookup(): HistoryRepository
    {
        return EntityManagerFactory::build($this->conn)->getRepository(HistoryEntity::class);
    }

    public function test_save_last_visit_from_history_persists_a_non_null_value(): void
    {
        $this->repo->saveLastVisitFromHistory(4, '2026-07-25 14:30:00');

        try {
            $row = $this->conn->createQueryBuilder()
                ->select('last_visit', 'last_visit_from_history')
                ->from('user_infos')
                ->where('user_id = :id')
                ->setParameter('id', 4)
                ->executeQuery()
                ->fetchAssociative();

            self::assertIsArray($row);
            self::assertSame('2026-07-25 14:30:00', $row['last_visit']);
            // last_visit_from_history is a genuine boolean column -- a
            // native PHP bool on Postgres, numeric 1/0 on MySQL.
            self::assertSame(1, (int) (bool) $row['last_visit_from_history']);
        } finally {
            $lastVisitLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
            $this->conn->executeStatement(
                'UPDATE ' . 'user_infos' . " SET last_visit = NULL, last_visit_from_history = {$lastVisitLiteral} WHERE user_id = 4"
            );
        }
    }

    public function test_save_last_visit_from_history_persists_a_null_value(): void
    {
        // Prime a non-null value first so this test proves the null branch
        // actually clears it, rather than trivially observing an
        // already-null column.
        $this->repo->saveLastVisitFromHistory(4, '2026-07-25 14:30:00');

        $this->repo->saveLastVisitFromHistory(4, null);

        $row = $this->conn->createQueryBuilder()
            ->select('last_visit')
            ->from('user_infos')
            ->where('user_id = :id')
            ->setParameter('id', 4)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertNull($row['last_visit']);

        $lastVisitLiteral = $this->dbDriver === 'pgsql' ? 'false' : '0';
        $this->conn->executeStatement(
            'UPDATE ' . 'user_infos' . " SET last_visit_from_history = {$lastVisitLiteral} WHERE user_id = 4"
        );
    }
}
