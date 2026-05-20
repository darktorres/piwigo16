<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration\Repository;

use Doctrine\DBAL\Connection;
use Piwigo\History\HistoryRepository;
use Piwigo\Tests\Integration\IntegrationTestCase;

/**
 * Real-DB integration tests for {@see HistoryRepository}. Locks in the
 * F8-b contract: fk_history_user_id ON DELETE CASCADE removes history
 * rows when their owning user is deleted.
 *
 * Fixture seeds no history rows; each test inserts its own.
 */
final class HistoryRepositoryTest extends IntegrationTestCase
{
    private const string FIXTURE = __DIR__ . '/../../../dev/fixtures/piwigo-17.0.sql';

    private Connection $conn;
    private HistoryRepository $repo;

    #[\Override]
    protected function setUp(): void
    {
        $this->setUpConnectionFromEnv();
        $this->resetDatabaseFast(self::FIXTURE);
        $this->conn = $this->newDbalConnection();
        $this->repo = new HistoryRepository($this->conn, 'piwigo_');
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->conn->close();
    }

    public function test_insertLog_round_trips_via_findLastVisitByUserId(): void
    {
        $this->repo->insertLog(3, '127.0.0.1', 'categories', '1', null, null, null, null, null, null);

        $last = $this->repo->findLastVisitByUserId(3);
        self::assertNotNull($last);
        self::assertNotEmpty($last->date);
        self::assertNotEmpty($last->time);
    }

    public function test_countAll_increments_with_inserts(): void
    {
        $start = $this->repo->countAll();

        $this->repo->insertLog(3, '127.0.0.1', null, null, null, null, null, null, null, null);
        $this->repo->insertLog(4, '127.0.0.1', null, null, null, null, null, null, null, null);

        self::assertSame($start + 2, $this->repo->countAll());
    }

    /**
     * F8-b regression guard: fk_history_user_id ON DELETE CASCADE — the
     * user's history rows must disappear when the user is deleted.
     */
    public function test_user_delete_cascades_to_history(): void
    {
        $this->repo->insertLog(3, '127.0.0.1', null, null, null, null, null, null, null, null);
        $this->repo->insertLog(3, '127.0.0.1', null, null, null, null, null, null, null, null);
        self::assertSame(2, $this->countByUserId(3), 'precondition');

        $this->conn->executeStatement('DELETE FROM piwigo_users WHERE id = 3');

        self::assertSame(0, $this->countByUserId(3));
    }

    /**
     * Placed last in source order so the post-DDL `markSchemaDirty()` lands
     * at a class boundary — the next class's setUp re-clones from the
     * template anyway, so the dirty flag costs nothing extra.
     */
    public function test_extendSectionEnum_alters_enum_values(): void
    {
        $this->markSchemaDirty();

        $newEnumValues = ['categories', 'tags', 'search', 'list', 'favorites',
            'most_visited', 'best_rated', 'recent_pics', 'recent_cats', 'custom_section'];
        $this->repo->extendSectionEnum($newEnumValues);

        // Insert into the newly-allowed enum value; pre-extend this would
        // fail with a strict-mode truncation error.
        $this->repo->insertLog(3, '127.0.0.1', 'custom_section', null, null, null, null, null, null, null);

        $section = $this->conn->executeQuery(
            'SELECT section FROM piwigo_history WHERE user_id = 3 ORDER BY id DESC LIMIT 1'
        )->fetchOne();
        self::assertSame('custom_section', $section);
    }

    private function countByUserId(int $userId): int
    {
        $value = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM piwigo_history WHERE user_id = ?',
            [$userId]
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }
}
