<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the caddie domain: `caddie` (a per-user
 * "shopping basket" of image ids, added from fill_caddie()/ws_caddie_add()).
 *
 * Item 15 Sub-item E: converted to real DQL against {@see CaddieEntity} --
 * `addElements()` stays on raw DBAL `INSERT IGNORE` (see its own docblock).
 *
 * @extends EntityRepository<CaddieEntity>
 */
final class CaddieRepository extends EntityRepository
{
    /**
     * Adds the given elements to a user's caddie. An element already
     * present is silently skipped (INSERT IGNORE against the table's own
     * (user_id, element_id) primary key) -- behaviorally the same as the
     * originals' own "diff against what's already there, then insert only
     * the new ones" two-step, without needing the extra SELECT. Returns
     * the number of elements actually newly added.
     *
     * Item 15 audit, re-verified: the plan's own text suggested a
     * "find-or-persist" ORM rewrite here, matching
     * {@see \Piwigo\Group\GroupRepository::addMembers()}'s own identical
     * shape -- but that method's own Item 14 audit already settled this
     * exact question and rejected it: ORM `persist()`/`flush()` has no
     * `INSERT IGNORE` equivalent at all, and a find-then-persist two-step
     * introduces a real TOCTOU race (a concurrent request inserting
     * between the existence check and the insert) that the atomic
     * `INSERT IGNORE` doesn't have. Stays on raw DBAL, matching
     * `addMembers()`'s own settled precedent exactly.
     *
     * @param array<int, int> $elementIds
     */
    public function addElements(int $userId, array $elementIds): int
    {
        $added = 0;
        foreach ($elementIds as $elementId) {
            $added += (int) $this->getEntityManager()
                ->getConnection()
                ->executeStatement(
                    'INSERT IGNORE INTO ' . Tables::caddie() . ' (element_id, user_id) VALUES (?, ?)',
                    [$elementId, $userId],
                    [ParameterType::INTEGER, ParameterType::INTEGER],
                );
        }

        return $added;
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
     * Item 15 audit: the DELETE half converted to real DQL; the INSERT
     * half stays on {@see \Piwigo\Db\BatchWriter} permanently (kept per
     * the user's explicit choice, same as every other bulk-write call
     * site in this plan).
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

        new \Piwigo\Db\BatchWriter($em->getConnection())
            ->massInsert(Tables::caddie(), array_keys($inserts[0]), $inserts);
    }

    /**
     * Removes only the given elements from $userId's caddie --
     * Admin\BatchManagerGlobalPageRenderer's own "remove_from_caddie"
     * action, unlike replaceForUser() above which clears everything.
     *
     * Item 15 audit: converted to real DQL.
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
