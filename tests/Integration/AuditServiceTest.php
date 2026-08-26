<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use Override;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\AuditService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Tests\Support\DbTransactionTestOverride;

final class AuditServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private AuditService $service;

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

        // PILOT (transaction-wrapping rollout): begin before any container
        // resolution below -- see ApiKeyServiceGetAvailableTest.php's own
        // comment for the full reasoning.
        DbTransactionTestOverride::begin();

        $currentConfig = Kernel::container()->get(CurrentConfig::class);
        if (! $currentConfig instanceof CurrentConfig) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
        }
        $currentConfig->reset();
        ConfigLoader::applyDefaults();
        ConfigLoader::applyEnvOverrides();

        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';

        $this->conn = DbConnection::build();
        $repo = TypedRepository::narrow(EntityManagerFactory::build($this->conn)->getRepository(AuditLogEntity::class), AuditRepository::class);
        $this->service = new AuditService($repo);
        // The fixture seeds 3 real audit_log rows (group-creation events) --
        // cleared so every test starts from a genuinely empty table, same
        // reasoning as AuditRepositoryTest's own setUp().
        $this->conn->executeStatement('DELETE FROM audit_log');
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM audit_log');
        DbTransactionTestOverride::rollback();
        parent::tearDown();
    }

    public function testRecordPersistsARowAndReturnsItsId(): void
    {
        $id = $this->service->record(UserId::from(1), 'create', 'user', 42, null, [
            'username' => 'alice',
        ]);

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
        self::assertSame('10.20.30.40', $row['ip_address']);
        self::assertNull($row['prev_hash']);
        self::assertIsString($row['row_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['row_hash']);
    }

    public function testActorIdContributesItsBareDigitsToTheRowHash(): void
    {
        // The hash payload joins `(string) $actorId`. Retyping the column
        // from int to UserId only stays safe because UserId::__toString()
        // yields the same bare digits -- anything else (an object hash, a
        // "UserId(1)" debug form) would silently invalidate every row
        // written before the retype, and verifyChain() would only reveal it
        // on an installation that already had history.
        //
        // Pinned by recomputing the documented payload here rather than by
        // calling the private method, so a change to either the payload
        // shape or UserId's string form fails this test.
        $this->service->record(UserId::from(1), 'create', 'user', 42);

        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from('audit_log')
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertIsString($row['created_at']);
        $expected = hash('sha256', implode('|', [
            '',
            '1',
            'create',
            'user',
            '42',
            '',
            '',
            '10.20.30.40',
            $row['created_at'],
        ]));

        self::assertSame($expected, $row['row_hash']);
    }

    public function testRecordLinksTheSecondRowToTheFirst(): void
    {
        $this->service->record(UserId::from(1), 'create', 'user', 1);
        $secondId = $this->service->record(UserId::from(1), 'delete', 'group', 2);

        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'prev_hash', 'row_hash')
            ->from('audit_log')
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(2, $rows);
        self::assertSame($rows[0]['row_hash'], $rows[1]['prev_hash']);
        self::assertSame($secondId, $rows[1]['id']);
    }

    public function testVerifyChainIsTrueForAnEmptyLog(): void
    {
        self::assertTrue($this->service->verifyChain());
    }

    public function testVerifyChainIsTrueForAnUntamperedChain(): void
    {
        $this->service->record(UserId::from(1), 'create', 'user', 1, null, [
            'username' => 'alice',
        ]);
        $this->service->record(UserId::from(1), 'update', 'user', 1, [
            'username' => 'alice',
        ], [
            'username' => 'alice2',
        ]);
        $this->service->record(null, 'autoupdate', 'system', null);

        self::assertTrue($this->service->verifyChain());
    }

    public function testVerifyChainIsFalseWhenARowsContentIsAltered(): void
    {
        $this->service->record(UserId::from(1), 'create', 'user', 1);
        $this->service->record(UserId::from(1), 'delete', 'group', 2);

        // tamper with the first row's action after the fact -- its own
        // row_hash no longer matches its (now different) content, and the
        // second row's prev_hash no longer matches a hash that reflects
        // reality either way.
        $this->conn->createQueryBuilder()
            ->update('audit_log')
            ->set('action', "'tampered'")
            ->where('action = :action')
            ->setParameter('action', 'create')
            ->executeStatement();

        self::assertFalse($this->service->verifyChain());
    }

    public function testVerifyChainIsFalseWhenAStoredHashIsAltered(): void
    {
        $this->service->record(UserId::from(1), 'create', 'user', 1);
        $this->service->record(UserId::from(1), 'delete', 'group', 2);

        $this->conn->createQueryBuilder()
            ->update('audit_log')
            ->set('row_hash', ':fake')
            ->where('action = :action')
            ->setParameter('fake', str_repeat('f', 64))
            ->setParameter('action', 'create')
            ->executeStatement();

        self::assertFalse($this->service->verifyChain());
    }

    public function testVerifyChainIsFalseWhenARowsPrevHashIsTamperedDirectly(): void
    {
        // Content and row_hash both stay internally consistent for every
        // row -- only the *link* between rows 1 and 2 is severed by
        // overwriting the second row's stored prev_hash with a value that
        // doesn't match the first row's real row_hash. This exercises the
        // "prevHash !== expectedPrevHash" branch specifically, distinct
        // from the "recomputed hash mismatch" branch already covered by
        // the row-content and stored-hash tampering tests above.
        $this->service->record(UserId::from(1), 'create', 'user', 1);
        $this->service->record(UserId::from(1), 'delete', 'group', 2);

        $this->conn->createQueryBuilder()
            ->update('audit_log')
            ->set('prev_hash', ':fake')
            ->where('action = :action')
            ->setParameter('fake', str_repeat('a', 64))
            ->setParameter('action', 'delete')
            ->executeStatement();

        self::assertFalse($this->service->verifyChain());
    }
}
