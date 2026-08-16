<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;

/**
 * The fixture actually seeds 3 real audit_log rows (group-creation events
 * for Editors/Reviewers/Guests) -- cleared once in setUp() below so every
 * test starts from a genuinely empty table, then each test cleans up its
 * own rows via tearDown() -- same isolation discipline as
 * CaddieRepositoryTest/ActivityServiceTest.
 */
final class AuditRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private AuditRepository $repo;

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
        $this->repo = EntityManagerFactory::build($this->conn)->getRepository(AuditLogEntity::class);
        $this->conn->executeStatement('DELETE FROM audit_log');
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM audit_log');
        parent::tearDown();
    }

    public function testFindLatestRowHashReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->repo->findLatestRowHash());
    }

    public function testInsertReturnsTheNewRowIdAndPersistsEveryColumn(): void
    {
        $id = $this->repo->insert(
            UserId::from(1),
            'create',
            'user',
            42,
            null,
            '{"username":"alice"}',
            IpAddress::from('10.0.0.1'),
            SqlDateTime::from('2026-07-12 10:00:00'),
            null,
            str_repeat('a', 64),
        );

        self::assertGreaterThan(0, $id);

        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from('audit_log')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame(1, $row['actor_id']);
        self::assertSame('create', $row['action']);
        self::assertSame('user', $row['entity_type']);
        self::assertSame(42, $row['entity_id']);
        self::assertNull($row['before_json']);
        // MySQL's native JSON column type re-serializes on write (e.g.
        // adds a space after ':'), so compare decoded values, not raw
        // bytes -- same reasoning as AuditService::canonicalJson()'s own
        // docblock.
        self::assertIsString($row['after_json']);
        self::assertSame([
            'username' => 'alice',
        ], json_decode($row['after_json'], true));
        self::assertSame('10.0.0.1', $row['ip_address']);
        self::assertNull($row['prev_hash']);
        self::assertSame(str_repeat('a', 64), $row['row_hash']);
    }

    public function testInsertAllowsANullActorId(): void
    {
        $id = $this->repo->insert(
            null,
            'autoupdate',
            'system',
            null,
            null,
            null,
            null,
            SqlDateTime::from('2026-07-12 10:00:00'),
            null,
            str_repeat('b', 64),
        );

        $actorId = $this->conn->createQueryBuilder()
            ->select('actor_id')
            ->from('audit_log')
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();

        self::assertNull($actorId);
    }

    public function testFindLatestRowHashReturnsTheMostRecentlyInserted(): void
    {
        $this->repo->insert(UserId::from(1), 'create', 'user', 1, null, null, null, SqlDateTime::from('2026-07-12 10:00:00'), null, str_repeat('a', 64));
        $this->repo->insert(UserId::from(1), 'create', 'user', 2, null, null, null, SqlDateTime::from('2026-07-12 10:00:01'), str_repeat('a', 64), str_repeat('b', 64));

        self::assertSame(str_repeat('b', 64), $this->repo->findLatestRowHash());
    }

    public function testFindAllInOrderReturnsRowsOldestFirstWithTheChainLinked(): void
    {
        $this->repo->insert(UserId::from(1), 'create', 'user', 1, null, null, null, SqlDateTime::from('2026-07-12 10:00:00'), null, str_repeat('a', 64));
        $this->repo->insert(UserId::from(1), 'delete', 'group', 2, '{"name":"x"}', null, null, SqlDateTime::from('2026-07-12 10:00:01'), str_repeat('a', 64), str_repeat('b', 64));

        $rows = $this->repo->findAllInOrder();

        self::assertCount(2, $rows);
        self::assertSame('create', $rows[0]->action);
        self::assertNull($rows[0]->prevHash);
        self::assertSame(str_repeat('a', 64), $rows[0]->rowHash);
        self::assertSame('delete', $rows[1]->action);
        self::assertSame(str_repeat('a', 64), $rows[1]->prevHash);
        self::assertIsString($rows[1]->beforeJson);
        self::assertSame([
            'name' => 'x',
        ], json_decode($rows[1]->beforeJson, true));
    }
}
