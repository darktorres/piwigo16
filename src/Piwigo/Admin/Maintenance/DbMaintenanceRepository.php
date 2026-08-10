<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Name\OptionallyQualifiedName;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Feed\FeedEntity;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistorySummaryEntity;
use Piwigo\Image\LoungeEntity;
use Piwigo\Search\SavedSearchEntity;
use Piwigo\Session\SessionEntity;
use Piwigo\Tag\ImageTagEntity;
use Piwigo\Tag\TagEntity;
use Piwigo\Users\UserEntity;

/**
 * Persistence layer for admin/maintenance_actions.php's own raw SQL --
 * see MaintenanceActionDispatcher's own docblock for the dispatch switch
 * that calls into it.
 *
 * Owns no table itself -- every method here is a cross-domain maintenance
 * sweep against a table another repository owns (history/tags/sessions
 * each have their own entity) -- holds EntityManagerInterface directly
 * rather than being resolved via getRepository(), same shape as
 * Auth\AuthRepository, and clears the identity map after each bulk write
 * that bypasses one of those owned entities.
 *
 * `purgeHistoryDetail`/`purgeHistorySummary`/`purgeUnusedFeeds`/
 * `countLoungeItems`/`purgeSessionsForDeletedUsers`/`deleteOrphanTags`/
 * `purgeSearchHistory` operate via DQL against their owning entities.
 * `repairOptimizeAllTables()` uses DBAL directly (DDL has no DQL
 * grammar).
 */
final readonly class DbMaintenanceRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function purgeHistoryDetail(): void
    {
        $this->em->createQuery('DELETE FROM ' . HistoryEntity::class . ' h')
            ->execute();
        $this->em->clear();
    }

    public function purgeHistorySummary(): void
    {
        $this->em->createQuery('DELETE FROM ' . HistorySummaryEntity::class . ' hs')
            ->execute();
    }

    public function purgeUnusedFeeds(): void
    {
        $this->em->createQueryBuilder()
            ->delete(FeedEntity::class, 'f')
            ->where('f.lastCheck IS NULL')
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    public function purgeSearchHistory(): void
    {
        $this->em->createQuery('DELETE FROM ' . SavedSearchEntity::class . ' s')
            ->execute();
        $this->em->clear();
    }

    /**
     * Deletes tags with no linked image and untouched for over a day
     * (matches TagService::getOrphanTags()/deleteTags()'s own cutoff).
     * Returns the number of tags deleted, for CLI output.
     *
     * Deliberately does NOT replicate TagService::deleteTags()'s side
     * effects -- the `delete_tags` event trigger, activity logging,
     * `lastmodified` touch on affected images, and the
     * `CurrentUser::rawAttributes['nb_available_tags']` reset. Those are
     * user-facing tag-management concerns; this method backs an
     * operator-run CLI
     * maintenance sweep (`bin/piwigo maintenance:orphan-tags`), where a
     * plain DB cleanup is the correct scope. The existing admin web UI
     * action (`admin/maintenance_actions.php`'s `delete_orphan_tags` case)
     * is untouched and keeps the full side-effect behavior.
     *
     * The cutoff uses DQL's own `CURRENT_TIMESTAMP()`/`DATE_SUB()`
     * (compiles to the DB server's real clock), not a PHP-computed
     * `Env::now()` parameter -- same reasoning as
     * {@see \Piwigo\Tag\TagRepository::findOrphanTags()}'s own docblock
     * (a real clock-source-mismatch bug found and fixed converting that
     * method): this sweep must line up with real wall-clock-backdated
     * data, not a frozen `PIWIGO_TEST_NOW` value.
     */
    public function deleteOrphanTags(): int
    {
        $orphanTagIds = $this->em->createQueryBuilder()
            ->select('t.id')
            ->from(TagEntity::class, 't')
            ->leftJoin(ImageTagEntity::class, 'it', Join::WITH, 't.id = it.tagId')
            ->where('it.tagId IS NULL')
            ->andWhere("t.lastmodified < DATE_SUB(CURRENT_TIMESTAMP(), 1, 'day')")
            ->getQuery()
            ->getSingleColumnResult();

        if ($orphanTagIds === []) {
            return 0;
        }

        $orphanTagIdValues = array_map(
            static fn (mixed $id): int => $id instanceof TagId ? $id->value : (is_numeric($id) ? (int) $id : 0),
            $orphanTagIds
        );

        $this->em->createQueryBuilder()
            ->delete(TagEntity::class, 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $orphanTagIdValues, ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $this->em->clear();

        return count($orphanTagIdValues);
    }

    /**
     * Repairs, re-orders (by primary key), and optimizes every table in
     * this database -- ported from
     * \Piwigo\Db\MysqliDb::doMaintenanceAllTables(), the "database" action's
     * only real caller (confirmed via a direct grep; not reused elsewhere).
     * Table/column names come from `SHOW TABLES`/`DESC`, never user input,
     * so splicing them into raw SQL here matches the original's own
     * approach (none of these 4 statement kinds are parameterizable SQL
     * identifiers anyway).
     *
     * The original tracked a running boolean across all 3 mutating
     * statements (mysqli_query() returns false on failure when
     * \Piwigo\Config\CurrentConfig::dieOnSqlError() is off) to pick a
     * success/error message. DBAL has no such "off" mode -- a failed
     * statement throws instead -- so completing this method without an
     * exception already means every step succeeded; no return value
     * needed.
     *
     * Postgres has no `REPAIR TABLE`/`ALTER TABLE ...
     * ORDER BY`/`OPTIMIZE TABLE` -- and neither of the first two has a
     * safe, meaningful Postgres equivalent worth porting (REPAIR TABLE is
     * a MyISAM-era corruption-recovery statement; this schema is
     * uniformly InnoDB, where REPAIR TABLE is already a real no-op.
     * ALTER TABLE ... ORDER BY physically re-sorts row storage by PK --
     * its closest Postgres analog, CLUSTER, needs a specific index chosen
     * and is a far more disruptive, not-routinely-automated operation in
     * real Postgres practice, not a like-for-like swap). `VACUUM
     * (ANALYZE)` (reclaim dead tuples + refresh planner stats) + `REINDEX
     * TABLE` (rebuild index bloat) are Postgres's own real maintenance-
     * sweep primitives -- verified live that `VACUUM` cannot run inside
     * an explicit transaction block, which this method's own
     * all-bare-executeStatement()-calls shape (confirmed by reading the
     * full method body: no beginTransaction()/transactional() wraps any
     * of these statements) already satisfies.
     */
    public function repairOptimizeAllTables(): void
    {
        // The table list and per-table primary key columns come from
        // DBAL's Schema API (introspectTableNames()/
        // introspectTablePrimaryKeyConstraint()) -- typed, no raw-row-shape
        // guessing against MySQL's own display formatting ('Key' === 'PRI'
        // string matching) needed. REPAIR/ALTER ... ORDER BY/OPTIMIZE (and
        // their Postgres equivalents) have no DBAL Schema-API or
        // QueryBuilder equivalent (that API defines/diffs schemas for
        // migrations, not maintenance commands) and no bind-able parameter
        // position in any dialect (table/column identifiers in DDL-ish
        // statements can never be placeholders) -- those stay raw SQL,
        // spliced only from already-introspected identifier-shaped values.
        $conn = $this->em->getConnection();
        $schemaManager = $conn->createSchemaManager();

        // Identifier::toString() quotes (e.g. `"activity"`) -- getValue()
        // is the real unquoted name, matching what SHOW TABLES/DESC's own
        // output gave the original raw-splice version.
        $allTableNames = $schemaManager->introspectTableNames();
        $allTables = array_map(
            static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()
                ->getValue(),
            $allTableNames
        );

        if ($conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            foreach ($allTables as $table) {
                $conn->executeStatement("VACUUM (ANALYZE) {$table}");
                $conn->executeStatement("REINDEX TABLE {$table}");
            }

            return;
        }

        $allTablesCsv = implode(', ', $allTables);
        $conn->executeStatement(<<<SQL
            REPAIR TABLE {$allTablesCsv}
            SQL);

        foreach ($allTableNames as $tableName) {
            $primaryKey = $schemaManager->introspectTablePrimaryKeyConstraint($tableName);
            if ($primaryKey === null) {
                continue;
            }

            $primaryKeyCsv = implode(', ', array_map(
                static fn (UnqualifiedName $column): string => $column->getIdentifier()
                    ->getValue(),
                $primaryKey->getColumnNames()
            ));
            $tableNameString = $tableName->getUnqualifiedName()
                ->getValue();
            $conn->executeStatement(<<<SQL
                ALTER TABLE {$tableNameString} ORDER BY {$primaryKeyCsv};
                SQL);
        }

        $conn->executeStatement(<<<SQL
            OPTIMIZE TABLE {$allTablesCsv}
            SQL);
    }

    public function countLoungeItems(): int
    {
        $value = $this->em->createQueryBuilder()
            ->select('COUNT(l.imageId)')
            ->from(LoungeEntity::class, 'l')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Sessions belonging to a since-deleted user id -- should never happen
     * in practice, purged defensively.
     */
    public function purgeSessionsForDeletedUsers(): void
    {
        $sessions = $this->em->createQueryBuilder()
            ->select('s.id', 's.data')
            ->from(SessionEntity::class, 's')
            ->getQuery()
            ->getArrayResult();

        $allUserIds = $this->em->createQueryBuilder()
            ->select('u.id')
            ->from(UserEntity::class, 'u')
            ->getQuery()
            ->getArrayResult();
        $allUserIdStrings = [];
        foreach ($allUserIds as $row) {
            if (is_array($row) && ($row['id'] ?? null) instanceof UserId) {
                $allUserIdStrings[] = (string) $row['id']->value;
            }
        }

        $sessionsToDelete = [];
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            $data = $session['data'] ?? null;
            if (! is_string($data)) {
                continue;
            }
            if ((bool) preg_match('/pwg_uid\|i:(\d+);/', $data, $matches)
                and ! in_array($matches[1], $allUserIdStrings, true)) {
                $sessionId = $session['id'] ?? null;
                if (is_string($sessionId)) {
                    $sessionsToDelete[] = $sessionId;
                }
            }
        }

        if ($sessionsToDelete === []) {
            return;
        }

        $this->em->createQueryBuilder()
            ->delete(SessionEntity::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $sessionsToDelete, ArrayParameterType::STRING)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }
}
