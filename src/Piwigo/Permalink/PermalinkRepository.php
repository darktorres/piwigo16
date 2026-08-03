<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Piwigo\Category\CategoryEntity;
use Piwigo\Core\Env;
use Piwigo\Permalink\Projection\OldPermalink;

/**
 * Persistence layer for the category-permalink domain. Owns no table
 * itself in the "single mapped entity" sense -- `categories.permalink`
 * is Category\CategoryEntity's own column (writes here go through it,
 * find+set+flush, rather than raw DBAL, since a raw write would leave
 * any CategoryEntity already in this EntityManager's identity map
 * stale); `old_permalinks` is mapped as {@see OldPermalinkEntity} below.
 * Holds EntityManagerInterface directly, same shape as Auth\AuthRepository.
 *
 * Further SQL-modernization audit, Item 16A/16B: every method now runs
 * as real DQL -- never audited by Item 14/15. `old_permalinks` was
 * deliberately never entity-mapped anywhere in the campaign until 16B
 * (see the former {@see \Piwigo\Category\CategoryRepository::
 * touchOldPermalinkHit()} cross-reference, since corrected).
 */
final readonly class PermalinkRepository
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Return the category id whose current permalink matches, or null.
     */
    public function findCategoryIdByPermalink(string $permalink): ?int
    {
        // `permalink` is `categories`' own real UNIQUE KEY (categories_i3) --
        // at most one row can ever match, matching this method's own
        // long-standing no-LIMIT single-value contract.
        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(CategoryEntity::class, 'c')
            ->where('c.permalink = :permalink')
            ->setParameter('permalink', $permalink)
            ->getQuery()
            ->getSingleColumnResult();

        return isset($ids[0]) && is_numeric($ids[0]) ? (int) $ids[0] : null;
    }

    /**
     * Return the category id a permalink was historically used by, or
     * null -- an INNER JOIN against `categories` (not just reading
     * `OldPermalinkEntity::$catId` directly) matters: a stale
     * `old_permalinks` row referencing an already-deleted category must
     * still resolve to null here, same real-existence-check behavior the
     * original's own join preserved.
     */
    public function findOldCategoryId(string $permalink): ?int
    {
        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(OldPermalinkEntity::class, 'op')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'op.catId = c.id')
            ->where('op.permalink = :permalink')
            ->setMaxResults(1)
            ->setParameter('permalink', $permalink)
            ->getQuery()
            ->getSingleColumnResult();

        return isset($ids[0]) && is_numeric($ids[0]) ? (int) $ids[0] : null;
    }

    /**
     * Return the current permalink for a category, or null if unset.
     */
    public function findPermalinkByCategoryId(int $catId): ?string
    {
        $entity = $this->em->find(CategoryEntity::class, $catId);

        return $entity !== null && $entity->permalink !== '' ? $entity->permalink : null;
    }

    public function clearCategoryPermalink(int $catId): void
    {
        $entity = $this->em->find(CategoryEntity::class, $catId);
        if ($entity === null) {
            return;
        }

        $entity->permalink = null;
        $this->em->flush();
    }

    public function setCategoryPermalink(int $catId, string $permalink): void
    {
        $entity = $this->em->find(CategoryEntity::class, $catId);
        if ($entity === null) {
            return;
        }

        $entity->permalink = $permalink;
        $this->em->flush();
    }

    /**
     * Marks an existing old-permalink row (cat_id, permalink) as deleted
     * now. The timestamp is PHP-computed via {@see Env::now()} (stays
     * PIWIGO_TEST_NOW-aware for deterministic tests) rather than a raw
     * SQL NOW() -- same pattern used throughout this campaign.
     */
    public function markOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->em->createQueryBuilder()
            ->update(OldPermalinkEntity::class, 'op')
            ->set('op.dateDeleted', ':deleted')
            ->where('op.catId = :catId')
            ->andWhere('op.permalink = :permalink')
            ->setParameter('deleted', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('catId', $catId)
            ->setParameter('permalink', $permalink)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    /**
     * Inserts a new old-permalink row already marked deleted now (the
     * category never actually used this permalink live -- it's being
     * recorded purely so the name can't be reused without going through
     * the permalink-history deletion flow first).
     */
    public function insertOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->em->persist(new OldPermalinkEntity(
            permalink: $permalink,
            catId: $catId,
            dateDeleted: Env::now()->format('Y-m-d H:i:s'),
            lastHit: null,
            hit: 0,
        ));
        $this->em->flush();
    }

    public function deleteOldPermalink(int $catId, string $permalink): void
    {
        $this->em->createQueryBuilder()
            ->delete(OldPermalinkEntity::class, 'op')
            ->where('op.catId = :catId')
            ->andWhere('op.permalink = :permalink')
            ->setParameter('catId', $catId)
            ->setParameter('permalink', $permalink)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    /**
     * Deletes a single old-permalink row by its permalink value alone (no
     * cat_id known -- admin/permalinks.php's "delete permanently" action
     * only has the permalink string from the link it renders). No LIMIT
     * needed: `permalink` is old_permalinks' own primary key (see
     * install/piwigo_structure-mysql.sql), so at most one row can ever
     * match. Returns whether a row was actually deleted, mirroring the
     * legacy \Piwigo\Db\MysqliDb::changes() == 0 check this replaces.
     */
    public function deleteOldPermalinkByValue(string $permalink): bool
    {
        $affected = $this->em->createQueryBuilder()
            ->delete(OldPermalinkEntity::class, 'op')
            ->where('op.permalink = :permalink')
            ->setParameter('permalink', $permalink)
            ->getQuery()
            ->execute();
        $this->em->clear();

        return $affected > 0;
    }

    /**
     * Every deleted-permalink row, optionally ordered by a caller-parsed
     * {@see OldPermalinkSortField} -- typed replacement for
     * `PermalinksSubController`'s own bare column-name string, same
     * "bounded token set, not genuinely open-ended admin text" reasoning
     * as `Image\PhotoSortField`.
     *
     * @return list<OldPermalink>
     */
    public function findAllOrderedBy(?OldPermalinkSortField $sortField): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('op')
            ->from(OldPermalinkEntity::class, 'op');

        if ($sortField !== null) {
            $qb->orderBy($sortField->dqlProperty());
        }

        $entities = $qb->getQuery()
            ->getResult();

        return array_map(static fn (OldPermalinkEntity $op): OldPermalink => OldPermalink::fromEntity($op), $entities);
    }
}
