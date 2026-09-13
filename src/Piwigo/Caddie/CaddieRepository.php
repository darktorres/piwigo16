<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\BatchWriter;

/**
 * Persistence layer for the caddie domain: `caddie` (a per-user
 * "shopping basket" of image ids, added from fill_caddie()/ws_caddie_add()).
 *
 * Real DQL against {@see CaddieEntity} -- `addElements()` bulk-writes via
 * {@see \Piwigo\Db\BatchWriter}'s own `ignore` option instead, see its
 * own docblock for why.
 *
 * @extends EntityRepository<CaddieEntity>
 */
final class CaddieRepository extends EntityRepository
{
    /**
     * Adds the given elements to a user's caddie. An element already
     * present, or one whose id doesn't reference a real image at all
     * (`fk_caddie_element_id`), is silently skipped (matching MySQL's own
     * `INSERT IGNORE`, which downgrades both a duplicate-key error and a
     * foreign-key violation to a warning) -- behaviorally the same as the
     * originals' own "diff against what's already there, then insert only
     * the new ones" two-step, without needing the extra SELECT. Returns
     * the number of elements actually newly added.
     *
     * Used to be a plain DBAL `Connection::insert()` per element, each
     * wrapped in its own `catch (ConstraintViolationException)` for this
     * exact IGNORE semantic -- deliberately NOT `persist()`/`flush()`,
     * since a caught failure from a failed `flush()` leaves the owning
     * EntityManager permanently closed (`Doctrine\ORM\UnitOfWork::
     * commit()`'s own `finally` branch calls `$em->close()` on any
     * failure, and `clear()` cannot undo that), and a find-then-insert
     * two-step would reintroduce a real TOCTOU race (a concurrent request
     * inserting between the existence check and the insert) the atomic
     * `INSERT IGNORE` doesn't have.
     *
     * `Db\BatchWriter::massInsert(..., ['ignore' => true])` preserves both
     * properties at once -- still one atomic INSERT per chunk (no
     * check-then-insert step) and it never touches the ORM's unit of
     * work either -- same fix as `Group\GroupRepository::addMembers()`'s
     * own identical shape, while collapsing what used to be one round
     * trip per element into `N/500`. The one remaining gap, "how many
     * were actually newly added" without a separate existence check, is
     * closed by `massInsert()`'s own return value: MySQL/PostgreSQL/
     * SQLite's `IGNORE`/`ON CONFLICT DO NOTHING`/`OR IGNORE` variants all
     * already exclude skipped rows from their own affected-row count.
     * Called from `SiteUpdateSubController` via `CaddieService::
     * fillCurrentUserCaddie()` with every newly-synced photo id when
     * "add to caddie" is checked -- genuinely gallery-scale, not a
     * handful.
     *
     * @param array<int, int> $elementIds
     */
    public function addElements(int $userId, array $elementIds): int
    {
        $rows = array_map(static fn (int $elementId): array => [
            'element_id' => $elementId,
            'user_id' => $userId,
        ], $elementIds);

        return new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert('caddie', ['element_id', 'user_id'], $rows, [
                'ignore' => true,
            ]);
    }

    /**
     * Every element_id in $userId's own caddie -- Admin\BatchManager\
     * FilterResolver's own "caddie" prefilter.
     *
     * @return list<int>
     */
    public function findElementIdsForUser(int $userId): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.elementId')
            ->where('c.userId = :userId')
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $rows
        ));
    }

    /**
     * Empties $userId's caddie then adds $elementIds -- Admin\
     * PhotosAddDirectPageRenderer's own "batch" action, unlike
     * addElements() above which only ever adds on top of what's there.
     *
     * The DELETE half is real DQL; the INSERT half stays on
     * {@see \Piwigo\Db\BatchWriter} permanently, same as every other
     * bulk-write call site in this codebase.
     *
     * @param list<int> $elementIds
     */
    public function replaceForUser(int $userId, array $elementIds): void
    {
        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(CaddieEntity::class, 'c')
            ->where('c.userId = :userId')
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->execute();
        $em->clear();

        $inserts = [];
        foreach ($elementIds as $elementId) {
            $inserts[] = [
                'user_id' => $userId,
                'element_id' => $elementId,
            ];
        }

        if ($inserts === []) {
            return;
        }

        new BatchWriter($em->getConnection())
            ->massInsert('caddie', array_keys($inserts[0]), $inserts);
    }

    /**
     * Removes only the given elements from $userId's caddie --
     * Admin\BatchManagerGlobalPageRenderer's own "remove_from_caddie"
     * action, unlike replaceForUser() above which clears everything.
     *
     * @param list<int> $elementIds
     */
    public function removeElementsForUser(int $userId, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(CaddieEntity::class, 'c')
            ->where('c.elementId IN (:elementIds)')
            ->andWhere('c.userId = :userId')
            ->setParameter('elementIds', $elementIds, ArrayParameterType::INTEGER)
            ->setParameter('userId', UserId::from($userId))
            ->getQuery()
            ->execute();
        $em->clear();
    }
}
