<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\Group\GroupRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see GroupRepository}. The fixture seeds
 * no groups; each test sets up its own.
 */
final class GroupRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private GroupRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new GroupRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insertNew_round_trips_via_findNameById(): void
    {
        $id = $this->repo->insertNew('photographers', 0);

        self::assertGreaterThan(0, $id);
        self::assertSame('photographers', $this->repo->findNameById($id));
        self::assertTrue($this->repo->existsById($id));
    }

    public function test_countByName_finds_inserted_group(): void
    {
        $this->repo->insertNew('reviewers', 0);
        self::assertSame(1, $this->repo->countByName('reviewers'));
        self::assertSame(0, $this->repo->countByName('missing_group'));
    }

    public function test_insertUserGroupIgnoreDuplicates_associates_members(): void
    {
        $groupId = $this->repo->insertNew('editors', 0);

        $this->repo->insertUserGroupIgnoreDuplicates([
            ['group_id' => $groupId, 'user_id' => 3],
            ['group_id' => $groupId, 'user_id' => 4],
        ]);

        $userIds = $this->repo->findUserIdsByGroupId($groupId);
        sort($userIds);
        self::assertSame([3, 4], $userIds);
    }

    public function test_deleteByIds_cascades_through_user_group_and_group_access(): void
    {
        $groupId = $this->repo->insertNew('temp', 0);
        $this->repo->insertUserGroupIgnoreDuplicates([['group_id' => $groupId, 'user_id' => 3]]);
        $this->conn->executeStatement(
            'INSERT INTO piwigo_group_access (group_id, cat_id) VALUES (?, ?)',
            [$groupId, 1]
        );

        $this->repo->deleteByIds([$groupId]);

        self::assertFalse($this->repo->existsById($groupId));
        $remainingUg = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_user_group WHERE group_id = ?',
            [$groupId]
        )->fetchOne();
        $remainingGa = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_group_access WHERE group_id = ?',
            [$groupId]
        )->fetchOne();
        self::assertSame(0, $remainingUg, 'user_group rows must cascade');
        self::assertSame(0, $remainingGa, 'group_access rows must cascade');
    }
}
