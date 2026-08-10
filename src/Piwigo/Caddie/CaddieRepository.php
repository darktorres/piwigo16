<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Db\BatchWriter;

/**
 * Persistence layer for the caddie domain: `caddie` (a per-user
 * "shopping basket" of image ids, added from fill_caddie()/ws_caddie_add()).
 *
 * Real DQL against {@see CaddieEntity} -- `addElements()` stays on
 * plain DBAL (a bound, portable `INSERT` via
 * `Connection::insert()`, no MySQL-specific `IGNORE` syntax, duplicate
 * rows and nonexistent `element_id`s alike caught as
 * {@see \Doctrine\DBAL\Exception\ConstraintViolationException} per
 * element -- see its own docblock for why this isn't `persist()`).
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
     * Stays on raw DBAL, matching
     * {@see \Piwigo\Group\GroupRepository::addMembers()}'s own identical
     * shape and settled precedent: ORM `persist()`/`flush()` has no
     * `INSERT IGNORE` equivalent at all, and a find-then-persist two-step
     * introduces a real TOCTOU race (a concurrent request inserting
     * between the existence check and the insert) that the atomic
     * `INSERT IGNORE` doesn't have.
     *
     * Catches {@see ConstraintViolationException}, not just
     * {@see \Doctrine\DBAL\Exception\UniqueConstraintViolationException}
     * -- confirmed via a real test (`addElements() silently skips a
     * nonexistent image id`) that `INSERT IGNORE`'s tolerance here covers
     * both the duplicate-row case and the foreign-key case, since
     * `element_id` genuinely references `images.id`.
     *
     * @param array<int, int> $elementIds
     */
    public function addElements(int $userId, array $elementIds): int
    {
        $conn = $this->getEntityManager()
            ->getConnection();

        $added = 0;
        foreach ($elementIds as $elementId) {
            try {
                $conn->insert(
                    'caddie',
                    [
                        'element_id' => $elementId,
                        'user_id' => $userId,
                    ],
                    [
                        'element_id' => ParameterType::INTEGER,
                        'user_id' => ParameterType::INTEGER,
                    ],
                );
                $added++;
            } catch (ConstraintViolationException) {
                // Already in the caddie, or element_id doesn't reference a
                // real image -- same "IGNORE" semantic the raw INSERT
                // IGNORE this replaces had. Plain DBAL insert(), not
                // persist()/flush(): a caught ConstraintViolationException
                // from a failed flush() leaves the owning EntityManager
                // permanently closed (Doctrine\ORM\UnitOfWork::commit()'s
                // own finally branch calls $em->close() on any failure,
                // and clear() cannot undo that -- verified empirically,
                // not assumed), which would break every other repository
                // sharing this request's EntityManager. A plain DBAL
                // insert() never touches the ORM's unit of work at all,
                // so a caught failure here has no such blast radius.
            }
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
