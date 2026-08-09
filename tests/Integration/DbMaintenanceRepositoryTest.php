<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Override;
use Piwigo\Core\Kernel;
use LogicException;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Tests\Support\DbCredentialsTestFactory;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\Maintenance\DbMaintenanceRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;

/**
 * Fixture: piwigo_history/piwigo_history_summary/piwigo_search/
 * piwigo_lounge/piwigo_user_feed are all empty; piwigo_sessions has several
 * rows (1 real session for user 1, plus a handful of anonymous/guest
 * sessions with empty `data`). Every test inserts its own disposable rows
 * and cleans up via try/finally, matching this suite's established
 * pattern.
 */
final class DbMaintenanceRepositoryTest extends IntegrationTestCase
{
    private static bool $fixtureReady = false;

    private DbMaintenanceRepository $repo;

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
        $this->repo = new DbMaintenanceRepository(EntityManagerFactory::build($this->conn), DbCredentialsTestFactory::get());
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
        // Fixture already has a real session for user 1, plus however
        // many incidental anonymous/guest sessions (empty `data`, no
        // pwg_uid at all) the fixture-regen browser flow created along
        // the way -- purgeSessionsForDeletedUsers() only ever matches a
        // real `pwg_uid|i:N;` payload, so anonymous sessions always
        // survive too. Assert the invariant this method actually
        // guarantees (nothing is purged, since no session here references
        // a deleted user) rather than a specific total row count, which
        // varies with how many anonymous sessions a given regen run
        // happens to create.
        $before = $this->countRows(Tables::sessions());

        $this->repo->purgeSessionsForDeletedUsers();

        self::assertSame($before, $this->countRows(Tables::sessions()), 'no session here references a deleted user, so nothing should be purged');

        $realUserSession = $this->conn->createQueryBuilder()
            ->select('id')
            ->from(Tables::sessions())
            ->where('data LIKE :pattern')
            ->setParameter('pattern', '%pwg\_uid|i:1;%')
            ->executeQuery()
            ->fetchOne();
        self::assertIsString($realUserSession, 'the real session for user 1 must survive the purge');
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
            $this->repo->purgeSessionsForDeletedUsers();

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

    // purgeSessionsForDeletedUsers()'s `if (! is_string($data)) { continue;
    // }` guard (its own line 209) is left uncovered: `piwigo_sessions.data`
    // is `mediumtext NOT NULL` (confirmed via install/piwigo_structure-mysql.sql
    // and this file's own fixture schema) -- DBAL always returns a TEXT
    // column as a PHP string, and the column can never hold NULL. There is
    // no real row this branch could `continue` past; pure static-analysis
    // narrowing for `$session['data']` (a raw DBAL row typing as
    // `array<string, mixed>`), not a reachable runtime state.

    public function test_repair_optimize_all_tables_completes_without_throwing(): void
    {
        // Per this method's own docblock, REPAIR/ALTER .../OPTIMIZE issue
        // no meaningful return value to assert on -- completing without an
        // exception already means every introspected table (found via real
        // Schema-API calls, not hand-parsed SHOW TABLES/DESC output)
        // round-tripped correctly through REPAIR TABLE/(if it has a
        // primary key) ALTER TABLE ... ORDER BY/OPTIMIZE TABLE.
        self::expectNotToPerformAssertions();

        $this->repo->repairOptimizeAllTables();
    }

    /**
     * Cross-checks introspectTableNames()/introspectTablePrimaryKeyConstraint()
     * -- the exact calls repairOptimizeAllTables() itself uses -- against
     * raw MySQL-reported ground truth computed independently in this
     * test: test_repair_optimize_all_tables_completes_without_throwing()
     * above only proves the method doesn't throw, and wouldn't catch a
     * table silently dropped from the introspected set or a wrong PK
     * column for one specific table.
     *
     * DESC's own row order for a composite PK is column-*definition*
     * order, not the PRIMARY KEY clause's own *declared* column order --
     * confirmed live on `piwigo_rate` (columns defined
     * user_id/element_id/anonymous_id, but `PRIMARY KEY (element_id,
     * user_id, anonymous_id)` declared in a different order).
     * `introspectTablePrimaryKeyConstraint()` reports the real declared
     * order (matching `SHOW CREATE TABLE`'s own `PRIMARY KEY (...)`
     * clause exactly) -- ground truth here is `SHOW CREATE TABLE`, not
     * DESC, precisely because `ALTER TABLE ... ORDER BY` (what this
     * method's own PK columns feed into) cares about the constraint's
     * real column order, not table column-definition order.
     */
    public function test_repair_optimize_all_tables_introspection_matches_raw_show_tables_and_create_table(): void
    {
        $prefix = DbCredentialsTestFactory::get()->prefix;
        $schemaManager = $this->conn->createSchemaManager();

        $introspectedTableNames = array_values(array_filter(
            array_map(
                static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(),
                $schemaManager->introspectTableNames(),
            ),
            static fn (string $name): bool => str_starts_with($name, $prefix),
        ));
        sort($introspectedTableNames);

        // SHOW TABLES is MySQL-only syntax ("syntax error at or near
        // 'LIKE'" on Postgres). Postgres's real ground-truth equivalent is
        // pg_tables.
        /** @var list<string> $rawTableNames */
        $rawTableNames = $this->dbDriver === 'pgsql'
            ? $this->conn->fetchFirstColumn(
                'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE ?',
                [$prefix . '%']
            )
            : $this->conn->fetchFirstColumn('SHOW TABLES LIKE ' . $this->conn->quote($prefix . '%'));
        sort($rawTableNames);

        self::assertSame($rawTableNames, $introspectedTableNames, 'introspectTableNames() must find the exact same table set raw SHOW TABLES does');
        self::assertNotEmpty($introspectedTableNames, 'the fixture must have real prefixed tables for this comparison to prove anything');

        $sawACompositePrimaryKey = false;

        foreach ($introspectedTableNames as $tableName) {
            $primaryKey = $schemaManager->introspectTablePrimaryKeyConstraint(
                OptionallyQualifiedName::unquoted($tableName)
            );
            $introspectedPkColumns = $primaryKey === null ? [] : array_map(
                static fn (UnqualifiedName $column): string => $column->getIdentifier()->getValue(),
                $primaryKey->getColumnNames(),
            );

            // SHOW CREATE TABLE is MySQL-only syntax. Postgres's own
            // pg_get_constraintdef() reports the exact same "PRIMARY KEY
            // (col1, col2)" shape for a table's primary key constraint, so
            // the same regex-based extraction below applies unchanged once
            // fed this instead.
            if ($this->dbDriver === 'pgsql') {
                // Same `::type`-cast misread as UniqueExecLock::isRunningPostgres().
                // @phpstan-ignore dba.syntaxError
                $createTableSql = $this->conn->fetchOne(
                    "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE contype = 'p' AND conrelid = ?::regclass",
                    [$tableName]
                );
                $createTableSql = is_string($createTableSql) ? $createTableSql : '';
            } else {
                $createTableRow = $this->conn->fetchAssociative('SHOW CREATE TABLE ' . $tableName);
                self::assertIsArray($createTableRow);
                $createTableSql = $createTableRow['Create Table'] ?? null;
                self::assertIsString($createTableSql);
            }

            $matched = preg_match('/PRIMARY KEY\s*\(([^)]*)\)/', $createTableSql, $matches);
            $rawPkColumns = $matched !== 1 ? [] : array_map(
                // '`' (MySQL's own SHOW CREATE TABLE quoting) and '"'
                // (Postgres's, though none of this schema's own PK
                // columns actually need it).
                static fn (string $column): string => trim($column, '`" '),
                explode(',', $matches[1]),
            );

            if (count($rawPkColumns) > 1) {
                $sawACompositePrimaryKey = true;
            }

            self::assertSame($rawPkColumns, $introspectedPkColumns, "introspectTablePrimaryKeyConstraint() must match SHOW CREATE TABLE's own declared PRIMARY KEY column order for {$tableName}");
        }

        self::assertTrue($sawACompositePrimaryKey, 'the fixture must have at least one composite PK for this comparison to prove column *order* is preserved, not just column identity');
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
