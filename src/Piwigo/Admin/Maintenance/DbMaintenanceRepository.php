<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\Tables;

/**
 * Persistence layer for admin/maintenance_actions.php's own raw SQL
 * (originally duplicated, with 2 known behavioral drifts, in
 * admin/maintenance_env.php -- see MaintenanceActionDispatcher's own
 * docblock for the consolidated ~18-case dispatch switch and the drifts
 * its consolidation fixed).
 *
 * Owns no table itself -- every method here is a cross-domain maintenance
 * sweep against a table another repository owns (history/tags/sessions
 * each have their own entity) -- holds EntityManagerInterface directly
 * rather than being resolved via getRepository(), same shape as
 * Auth\AuthRepository, and clears the identity map after each bulk write
 * that bypasses one of those owned entities.
 */
final readonly class DbMaintenanceRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private DbCredentials $dbCredentials,
    ) {}

    public function purgeHistoryDetail(): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::history())
            ->executeStatement();
        $this->em->clear();
    }

    public function purgeHistorySummary(): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::historySummary())
            ->executeStatement();
    }

    public function purgeUnusedFeeds(): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::userFeed())
            ->where('last_check IS NULL')
            ->executeStatement();
        $this->em->clear();
    }

    public function purgeSearchHistory(): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::search())
            ->executeStatement();
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
     */
    public function deleteOrphanTags(): int
    {
        $conn = $this->em->getConnection();
        $orphanTagIds = $conn->createQueryBuilder()
            ->select('t.id')
            ->from(Tables::tags(), 't')
            ->leftJoin('t', Tables::imageTag(), 'it', 't.id = it.tag_id')
            ->where('it.tag_id IS NULL')
            ->andWhere('t.lastmodified < SUBDATE(NOW(), INTERVAL 1 DAY)')
            ->executeQuery()
            ->fetchFirstColumn();

        if ($orphanTagIds === []) {
            return 0;
        }

        $conn->createQueryBuilder()
            ->delete(Tables::tags())
            ->where('id IN (:ids)')
            ->setParameter('ids', $orphanTagIds, ArrayParameterType::INTEGER)
            ->executeStatement();
        $this->em->clear();

        return count($orphanTagIds);
    }

    /**
     * Repairs, re-orders (by primary key), and optimizes every table with
     * this install's own DB prefix -- ported from
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
     */
    public function repairOptimizeAllTables(): void
    {
        // SQL-modernization audit: verified, all 5 heredoc blocks below
        // splice only identifier-shaped values (table names from SHOW
        // TABLES/the fixed DB prefix, column names from DESC results),
        // never a real value -- REPAIR/OPTIMIZE/ALTER...ORDER BY have no
        // bind-able parameter positions in SQL at all (unlike DML, table/
        // column identifiers in DDL-ish statements can never be
        // placeholders in any dialect), so there's no QueryBuilder/bound-
        // parameter form to convert to here regardless.
        $conn = $this->em->getConnection();
        $prefix = $this->dbCredentials->prefix;
        $allTables = array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $conn->fetchFirstColumn(<<<SQL
                SHOW TABLES LIKE '{$prefix}%'
                SQL)
        );

        $allTablesCsv = implode(', ', $allTables);
        $conn->executeStatement(<<<SQL
            REPAIR TABLE {$allTablesCsv}
            SQL);

        foreach ($allTables as $tableName) {
            $allPrimaryKey = [];
            foreach ($conn->fetchAllAssociative(<<<SQL
                DESC {$tableName};
                SQL) as $column) {
                if (($column['Key'] ?? null) === 'PRI' && is_scalar($column['Field'])) {
                    $allPrimaryKey[] = (string) $column['Field'];
                }
            }

            if ($allPrimaryKey !== []) {
                $primaryKeyCsv = implode(', ', $allPrimaryKey);
                $conn->executeStatement(<<<SQL
                    ALTER TABLE {$tableName} ORDER BY {$primaryKeyCsv};
                    SQL);
            }
        }

        $conn->executeStatement(<<<SQL
            OPTIMIZE TABLE {$allTablesCsv}
            SQL);
    }

    public function countLoungeItems(): int
    {
        $value = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::lounge())
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Sessions belonging to a since-deleted user id -- should never happen
     * in practice, purged defensively.
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: `users` is now
     * mapped ({@see \Piwigo\Users\UserEntity}) -- the multi-auth column
     * indirection this used to take as an `$idColumn` parameter is gone.
     */
    public function purgeSessionsForDeletedUsers(): void
    {
        $conn = $this->em->getConnection();
        $sessions = $conn->createQueryBuilder()
            ->select('id', 'data')
            ->from(Tables::sessions())
            ->executeQuery()
            ->fetchAllAssociative();

        $allUserIds = $this->em->createQueryBuilder()
            ->select('u.id')
            ->from(\Piwigo\Users\UserEntity::class, 'u')
            ->getQuery()
            ->getArrayResult();
        $allUserIdStrings = [];
        foreach ($allUserIds as $row) {
            if (is_array($row) && ($row['id'] ?? null) instanceof \Piwigo\Common\ValueObject\UserId) {
                $allUserIdStrings[] = (string) $row['id']->value;
            }
        }

        $sessionsToDelete = [];
        foreach ($sessions as $session) {
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

        $conn->createQueryBuilder()
            ->delete(Tables::sessions())
            ->where('id IN (:ids)')
            ->setParameter('ids', $sessionsToDelete, ArrayParameterType::STRING)
            ->executeStatement();
        $this->em->clear();
    }
}
