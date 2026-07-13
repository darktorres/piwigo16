<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Fixture: piwigo_history/piwigo_history_summary/piwigo_search/
 * piwigo_lounge/piwigo_user_feed are all empty; piwigo_sessions has 1 row
 * (user 1's real session). Every test inserts its own disposable rows and
 * cleans up via try/finally, matching this suite's established pattern.
 */
final class DbMaintenanceRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private DbMaintenanceRepository $repo;

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
        $this->repo = new DbMaintenanceRepository($this->conn);
    }

    public function test_purge_history_detail_deletes_every_row(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::history())
            ->values(['user_id' => ':userId'])
            ->setParameter('userId', 1)
            ->executeStatement();

        $this->repo->purgeHistoryDetail();

        self::assertSame(0, $this->countRows(Tables::history()));
    }

    public function test_purge_history_summary_deletes_every_row(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::historySummary())
            ->values(['year' => ':year'])
            ->setParameter('year', 2026)
            ->executeStatement();

        $this->repo->purgeHistorySummary();

        self::assertSame(0, $this->countRows(Tables::historySummary()));
    }

    public function test_purge_unused_feeds_only_removes_never_checked_feeds(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::userFeed())
            ->values(['id' => ':id', 'user_id' => ':userId', 'last_check' => 'NULL'])
            ->setParameter('id', 'never-checked')
            ->setParameter('userId', 1)
            ->executeStatement();
        $this->conn->createQueryBuilder()
            ->insert(Tables::userFeed())
            ->values(['id' => ':id', 'user_id' => ':userId', 'last_check' => ':lastCheck'])
            ->setParameter('id', 'checked-once')
            ->setParameter('userId', 1)
            ->setParameter('lastCheck', '2026-07-01 00:00:00')
            ->executeStatement();

        try {
            $this->repo->purgeUnusedFeeds();

            $remaining = $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::userFeed())
                ->executeQuery()
                ->fetchFirstColumn();
            self::assertSame(['checked-once'], $remaining);
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::userFeed());
        }
    }

    public function test_purge_search_history_deletes_every_row(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::search())
            ->values(['created_by' => ':createdBy'])
            ->setParameter('createdBy', 1)
            ->executeStatement();

        $this->repo->purgeSearchHistory();

        self::assertSame(0, $this->countRows(Tables::search()));
    }

    public function test_count_lounge_items_matches_real_rows(): void
    {
        self::assertSame(0, $this->repo->countLoungeItems());

        $this->conn->createQueryBuilder()
            ->insert(Tables::lounge())
            ->values(['image_id' => ':imageId', 'category_id' => ':categoryId'])
            ->setParameter('imageId', 1)
            ->setParameter('categoryId', 1)
            ->executeStatement();

        try {
            self::assertSame(1, $this->repo->countLoungeItems());
        } finally {
            $this->conn->executeStatement('DELETE FROM ' . Tables::lounge());
        }
    }

    public function test_delete_orphan_tags_removes_a_tag_with_no_linked_image_older_than_a_day(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::tags())
            ->values([
                'name' => ':name',
                'url_name' => ':urlName',
                'lastmodified' => ':lastmodified',
            ])
            ->setParameter('name', 'orphan-tag')
            ->setParameter('urlName', 'orphan-tag')
            ->setParameter('lastmodified', '2020-01-01 00:00:00')
            ->executeStatement();

        try {
            $deleted = $this->repo->deleteOrphanTags();

            self::assertSame(1, $deleted);
            $remaining = $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::tags())
                ->where('name = :name')
                ->setParameter('name', 'orphan-tag')
                ->executeQuery()
                ->fetchOne();
            self::assertFalse($remaining, 'the orphan tag must have been deleted');
        } finally {
            $this->conn->createQueryBuilder()
                ->delete(Tables::tags())
                ->where('name = :name')
                ->setParameter('name', 'orphan-tag')
                ->executeStatement();
        }
    }

    public function test_delete_orphan_tags_keeps_a_tag_linked_to_an_image(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::tags())
            ->values([
                'name' => ':name',
                'url_name' => ':urlName',
                'lastmodified' => ':lastmodified',
            ])
            ->setParameter('name', 'linked-tag')
            ->setParameter('urlName', 'linked-tag')
            ->setParameter('lastmodified', '2020-01-01 00:00:00')
            ->executeStatement();
        $tagId = $this->conn->lastInsertId();

        $this->conn->createQueryBuilder()
            ->insert(Tables::imageTag())
            ->values(['image_id' => ':imageId', 'tag_id' => ':tagId'])
            ->setParameter('imageId', 1)
            ->setParameter('tagId', $tagId)
            ->executeStatement();

        try {
            $this->repo->deleteOrphanTags();

            $remaining = $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::tags())
                ->where('name = :name')
                ->setParameter('name', 'linked-tag')
                ->executeQuery()
                ->fetchOne();
            self::assertNotFalse($remaining, 'a tag linked to an image must not be deleted');
        } finally {
            $this->conn->createQueryBuilder()
                ->delete(Tables::imageTag())
                ->where('tag_id = :tagId')
                ->setParameter('tagId', $tagId)
                ->executeStatement();
            $this->conn->createQueryBuilder()
                ->delete(Tables::tags())
                ->where('name = :name')
                ->setParameter('name', 'linked-tag')
                ->executeStatement();
        }
    }

    public function test_purge_sessions_for_deleted_users_keeps_sessions_for_real_users(): void
    {
        // Fixture already has a real session for user 1.
        $this->repo->purgeSessionsForDeletedUsers('id');

        self::assertSame(1, $this->countRows(Tables::sessions()));
    }

    public function test_purge_sessions_for_deleted_users_removes_a_session_for_a_nonexistent_user(): void
    {
        $this->conn->createQueryBuilder()
            ->insert(Tables::sessions())
            ->values(['id' => ':id', 'data' => ':data', 'expiration' => ':expiration'])
            ->setParameter('id', 'orphan-session')
            ->setParameter('data', 'pwg_uid|i:999999;')
            ->setParameter('expiration', '2030-01-01 00:00:00')
            ->executeStatement();

        try {
            $this->repo->purgeSessionsForDeletedUsers('id');

            $remaining = $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::sessions())
                ->where('id = :id')
                ->setParameter('id', 'orphan-session')
                ->executeQuery()
                ->fetchOne();
            self::assertFalse($remaining, 'the orphan session must have been purged');
        } finally {
            $this->conn->createQueryBuilder()
                ->delete(Tables::sessions())
                ->where('id = :id')
                ->setParameter('id', 'orphan-session')
                ->executeStatement();
        }
    }

    private function countRows(string $table): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($table)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
