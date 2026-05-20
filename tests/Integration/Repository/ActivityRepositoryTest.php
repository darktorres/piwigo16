<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see ActivityRepository}. Locks in the
 * F8-a contract: performed_by IS NULL for system events; LEFT JOIN keeps
 * those rows; findActivityPage($systemOnly = true) filters them; and the
 * fk_activity_performed_by ON DELETE SET NULL preserves audit rows after
 * user deletion.
 *
 * Fixture seeds no activity rows; each test inserts its own.
 */
final class ActivityRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private ActivityRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new ActivityRepository($this->conn, 'piwigo_');

        // The fixture install + content seed writes ~15 activity rows.
        // Each test asserts against its own controlled inputs, so clear
        // the audit log first.
        $this->conn->executeStatement('TRUNCATE TABLE piwigo_activity');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function activityRow(array $overrides): array
    {
        return array_merge([
            'object'       => 'photo',
            'object_id'    => 1,
            'action'       => 'view',
            'performed_by' => 1,
            'session_idx'  => 'sess-test',
            'ip_address'   => '127.0.0.1',
            'details'      => '{}',
            'user_agent'   => 'phpunit',
        ], $overrides);
    }

    public function test_insertActivityRowsBatch_accepts_null_performed_by(): void
    {
        $this->repo->insertActivityRowsBatch([
            $this->activityRow(['performed_by' => null]),
        ]);

        $row = $this->conn->executeQuery(
            'SELECT performed_by FROM piwigo_activity LIMIT 1'
        )->fetchAssociative();
        self::assertIsArray($row);
        self::assertNull($row['performed_by'], 'F8-a: nullable column accepts NULL');
    }

    public function test_findSystemActivityRows_returns_null_username_for_null_performed_by(): void
    {
        $this->repo->insertActivityRowsBatch([
            $this->activityRow(['object' => 'system', 'object_id' => 1, 'action' => 'maintenance', 'performed_by' => null]),
        ]);

        $rows = $this->repo->findSystemActivityRows('piwigo_users', 'id', 'username');

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->performedBy);
        self::assertNull($rows[0]->username, 'LEFT JOIN returns NULL when performed_by IS NULL');
    }

    public function test_findActivityPage_systemOnly_filters_to_null_performed_by(): void
    {
        $this->repo->insertActivityRowsBatch([
            $this->activityRow(['action' => 'view', 'performed_by' => 1]),
            $this->activityRow(['action' => 'view', 'performed_by' => null]),
        ]);

        $systemRows = $this->repo->findActivityPage(
            performedBy: null,
            action: null,
            object: null,
            dateMin: null,
            dateMax: null,
            objectId: null,
            connectionsMode: 'all',
            adminIds: [],
            limit: 50,
            offset: 0,
            systemOnly: true,
        );

        self::assertCount(1, $systemRows);
        self::assertNull($systemRows[0]->performedBy);
    }

    public function test_findActivityCountByPerformer_buckets_null_under_system_key(): void
    {
        $this->repo->insertActivityRowsBatch([
            $this->activityRow(['performed_by' => 1]),
            $this->activityRow(['performed_by' => 1]),
            $this->activityRow(['performed_by' => null]),
        ]);

        $counts = $this->repo->findActivityCountByPerformer();

        // performed_by=1 (int key after PHP's numeric-string→int coercion),
        // null performed_by bucketed under the 'system' string key.
        self::assertSame(2, $counts[1]);
        self::assertSame(1, $counts['system']);
    }

    /**
     * F8-a regression guard: fk_activity_performed_by ON DELETE SET NULL —
     * deleting the user must preserve the activity row but blank the link.
     */
    public function test_user_delete_sets_performed_by_to_null(): void
    {
        $this->repo->insertActivityRowsBatch([
            $this->activityRow(['performed_by' => 3]),
        ]);

        $this->conn->executeStatement('DELETE FROM piwigo_users WHERE id = 3');

        $row = $this->conn->executeQuery(
            'SELECT performed_by FROM piwigo_activity LIMIT 1'
        )->fetchAssociative();
        self::assertIsArray($row);
        self::assertNull($row['performed_by'], 'audit row survives user delete with performed_by = NULL');
    }
}
