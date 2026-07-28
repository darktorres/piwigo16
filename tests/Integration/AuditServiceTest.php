<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Audit\AuditService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\Tables;

final class AuditServiceTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private AuditService $service;

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

        $_SERVER['REMOTE_ADDR'] = '10.20.30.40';

        $this->conn = DbConnection::build();
        $repo = EntityManagerFactory::build($this->conn)->getRepository(AuditLogEntity::class);
        $this->service = new AuditService($repo);
        // The fixture seeds 3 real audit_log rows (group-creation events) --
        // cleared so every test starts from a genuinely empty table, same
        // reasoning as AuditRepositoryTest's own setUp().
        $this->conn->executeStatement('DELETE FROM ' . Tables::auditLog());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::auditLog());
        parent::tearDown();
    }

    public function test_record_persists_a_row_and_returns_its_id(): void
    {
        $id = $this->service->record(1, 'create', 'user', 42, null, ['username' => 'alice']);

        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from(Tables::auditLog())
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
        self::assertSame(['username' => 'alice'], json_decode($row['after_json'], true));
        self::assertSame('10.20.30.40', $row['ip_address']);
        self::assertNull($row['prev_hash']);
        self::assertIsString($row['row_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['row_hash']);
    }

    public function test_record_links_the_second_row_to_the_first(): void
    {
        $this->service->record(1, 'create', 'user', 1);
        $secondId = $this->service->record(1, 'delete', 'group', 2);

        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'prev_hash', 'row_hash')
            ->from(Tables::auditLog())
            ->orderBy('id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        self::assertCount(2, $rows);
        self::assertSame($rows[0]['row_hash'], $rows[1]['prev_hash']);
        self::assertSame($secondId, $rows[1]['id']);
    }

    public function test_verify_chain_is_true_for_an_empty_log(): void
    {
        self::assertTrue($this->service->verifyChain());
    }

    public function test_verify_chain_is_true_for_an_untampered_chain(): void
    {
        $this->service->record(1, 'create', 'user', 1, null, ['username' => 'alice']);
        $this->service->record(1, 'update', 'user', 1, ['username' => 'alice'], ['username' => 'alice2']);
        $this->service->record(null, 'autoupdate', 'system', null);

        self::assertTrue($this->service->verifyChain());
    }

    public function test_verify_chain_is_false_when_a_rows_content_is_altered(): void
    {
        $this->service->record(1, 'create', 'user', 1);
        $this->service->record(1, 'delete', 'group', 2);

        // tamper with the first row's action after the fact -- its own
        // row_hash no longer matches its (now different) content, and the
        // second row's prev_hash no longer matches a hash that reflects
        // reality either way.
        $this->conn->createQueryBuilder()
            ->update(Tables::auditLog())
            ->set('action', "'tampered'")
            ->where('action = :action')
            ->setParameter('action', 'create')
            ->executeStatement();

        self::assertFalse($this->service->verifyChain());
    }

    public function test_verify_chain_is_false_when_a_stored_hash_is_altered(): void
    {
        $this->service->record(1, 'create', 'user', 1);
        $this->service->record(1, 'delete', 'group', 2);

        $this->conn->createQueryBuilder()
            ->update(Tables::auditLog())
            ->set('row_hash', ':fake')
            ->where('action = :action')
            ->setParameter('fake', str_repeat('f', 64))
            ->setParameter('action', 'create')
            ->executeStatement();

        self::assertFalse($this->service->verifyChain());
    }

    public function test_verify_chain_is_false_when_a_rows_prev_hash_is_tampered_directly(): void
    {
        // Content and row_hash both stay internally consistent for every
        // row -- only the *link* between rows 1 and 2 is severed by
        // overwriting the second row's stored prev_hash with a value that
        // doesn't match the first row's real row_hash. This exercises the
        // "prevHash !== expectedPrevHash" branch specifically, distinct
        // from the "recomputed hash mismatch" branch already covered by
        // the row-content and stored-hash tampering tests above.
        $this->service->record(1, 'create', 'user', 1);
        $this->service->record(1, 'delete', 'group', 2);

        $this->conn->createQueryBuilder()
            ->update(Tables::auditLog())
            ->set('prev_hash', ':fake')
            ->where('action = :action')
            ->setParameter('fake', str_repeat('a', 64))
            ->setParameter('action', 'delete')
            ->executeStatement();

        self::assertFalse($this->service->verifyChain());
    }
}
