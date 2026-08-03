<?php

declare(strict_types=1);

namespace Piwigo\Admin\Maintenance;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Db\DbCredentials;
use Piwigo\Feed\FeedEntity;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistorySummaryEntity;
use Piwigo\Image\LoungeEntity;
use Piwigo\Tag\ImageTagEntity;
use Piwigo\Tag\TagEntity;

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
 *
 * Item 15 audit: `purgeHistoryDetail`/`purgeHistorySummary`/
 * `purgeUnusedFeeds`/`countLoungeItems`/`purgeSessionsForDeletedUsers`/
 * `deleteOrphanTags`/`purgeSearchHistory` (Item 15H, once
 * {@see \Piwigo\Search\SavedSearchEntity} existed) converted to DQL
 * against their owning entities. `repairOptimizeAllTables()` stays DBAL
 * permanently (DDL has no DQL grammar).
 */
final readonly class DbMaintenanceRepository
{
    public function __construct(
        private EntityManagerInterface $em,
        private DbCredentials $dbCredentials,
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
        $this->em->createQuery('DELETE FROM ' . \Piwigo\Search\SavedSearchEntity::class . ' s')
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
     *
     * SQL-modernization audit, Item 14 Sub-phase C4: `users` is now
     * mapped ({@see \Piwigo\Users\UserEntity}) -- the multi-auth column
     * indirection this used to take as an `$idColumn` parameter is gone.
     */
    public function purgeSessionsForDeletedUsers(): void
    {
        $sessions = $this->em->createQueryBuilder()
            ->select('s.id', 's.data')
            ->from(\Piwigo\Session\SessionEntity::class, 's')
            ->getQuery()
            ->getArrayResult();

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
            ->delete(\Piwigo\Session\SessionEntity::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $sessionsToDelete, ArrayParameterType::STRING)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }
}
