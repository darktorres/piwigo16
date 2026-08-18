<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Override;
use Piwigo\Category\CategoryEntity;
use Piwigo\Category\OldPermalinkLookupInterface;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Common\ValueObject\SqlDateTime;
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
 * Every method runs as real DQL.
 */
final readonly class PermalinkRepository implements OldPermalinkLookupInterface
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
     * Return the category id a retired permalink used to point to, or
     * null -- an INNER JOIN against `categories` (not just reading
     * `OldPermalinkEntity::$catId` directly) matters: a stale
     * `old_permalinks` row referencing an already-deleted category must
     * still resolve to null here, same real-existence-check behavior the
     * original's own join preserved.
     *
     * `op.permalink` is VO-typed ({@see \Piwigo\Db\Type\PermalinkType}),
     * whose `convertToDatabaseValue()` is strict (VO-only, throws on a
     * raw scalar) -- `tryFrom()`, not `from()`, since this method already
     * models "no match" as a valid, graceful outcome (`?int`), and a
     * caller-supplied string that can't even be a well-formed permalink
     * can never match a real row either way.
     */
    public function findOldCategoryId(string $permalink): ?int
    {
        $permalinkVo = Permalink::tryFrom($permalink);
        if (! $permalinkVo instanceof Permalink) {
            return null;
        }

        $ids = $this->em->createQueryBuilder()
            ->select('c.id')
            ->from(OldPermalinkEntity::class, 'op')
            ->innerJoin(CategoryEntity::class, 'c', Join::WITH, 'op.catId = c.id')
            ->where('op.permalink = :permalink')
            ->setMaxResults(1)
            ->setParameter('permalink', $permalinkVo)
            ->getQuery()
            ->getSingleColumnResult();

        return isset($ids[0]) && is_numeric($ids[0]) ? (int) $ids[0] : null;
    }

    /**
     * Return the current permalink for a category, or null if unset.
     */
    public function findPermalinkByCategoryId(int $catId): ?string
    {
        $catIdVo = CategoryId::tryFrom($catId);
        if (! $catIdVo instanceof CategoryId) {
            return null;
        }

        return $this->em->find(CategoryEntity::class, $catIdVo)?->permalink?->value;
    }

    public function clearCategoryPermalink(int $catId): void
    {
        $catIdVo = CategoryId::tryFrom($catId);
        if (! $catIdVo instanceof CategoryId) {
            return;
        }

        $entity = $this->em->find(CategoryEntity::class, $catIdVo);
        if ($entity === null) {
            return;
        }

        $entity->permalink = null;
        $this->em->flush();
    }

    /**
     * `$permalink` wraps via `Permalink::from()` -- this method's only
     * real caller ({@see \Piwigo\Permalink\PermalinkService::
     * setCatPermalink()}) reaches it only after its own manual charset/
     * numeric validation (mirrored by Permalink's own constraints) has
     * already passed for this exact value, same reasoning as this
     * class's other `from()`-using methods below.
     */
    public function setCategoryPermalink(int $catId, string $permalink): void
    {
        $catIdVo = CategoryId::tryFrom($catId);
        if (! $catIdVo instanceof CategoryId) {
            return;
        }

        $entity = $this->em->find(CategoryEntity::class, $catIdVo);
        if ($entity === null) {
            return;
        }

        $entity->permalink = Permalink::from($permalink);
        $this->em->flush();
    }

    /**
     * Marks an existing old-permalink row (cat_id, permalink) as deleted
     * now. The timestamp is PHP-computed via {@see Env::now()} (stays
     * PIWIGO_TEST_NOW-aware for deterministic tests) rather than a raw
     * SQL NOW().
     *
     * `$permalink` wraps via `Permalink::from()`, not `tryFrom()` --
     * unlike findOldCategoryId(), this method's only real caller
     * ({@see \Piwigo\Permalink\PermalinkService::deleteCatPermalink()})
     * always reaches it with a value it just read back out of
     * `categories.permalink` itself (findPermalinkByCategoryId()), so
     * it's guaranteed already-valid, same reasoning as
     * {@see \Piwigo\Category\CategoryRepository::touchOldPermalinkHit()}.
     */
    public function markOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->em->createQueryBuilder()
            ->update(OldPermalinkEntity::class, 'op')
            ->set('op.dateDeleted', ':deleted')
            ->where('op.catId = :catId')
            ->andWhere('op.permalink = :permalink')
            ->setParameter('deleted', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('catId', CategoryId::from($catId))
            ->setParameter('permalink', Permalink::from($permalink))
            ->getQuery()
            ->execute();
        $this->em->clear();
    }

    /**
     * Inserts a new old-permalink row already marked deleted now (the
     * category never actually used this permalink live -- it's being
     * recorded purely so the name can't be reused without going through
     * the permalink-history deletion flow first).
     *
     * `$permalink` wraps via `Permalink::from()` -- same "already read
     * back from categories.permalink" guaranteed-valid context as
     * markOldPermalinkDeleted()'s own (this method's only real caller is
     * the sibling branch of the same
     * {@see \Piwigo\Permalink\PermalinkService::deleteCatPermalink()}
     * call site) -- and constructing OldPermalinkEntity directly
     * requires the VOs regardless, since its properties are typed
     * `Permalink`/`CategoryId`, not `string`/`int`.
     */
    public function insertOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->em->persist(new OldPermalinkEntity(
            permalink: Permalink::from($permalink),
            catId: CategoryId::from($catId),
            dateDeleted: SqlDateTime::from(Env::now()->format('Y-m-d H:i:s')),
            lastHit: null,
            hit: 0,
        ));
        $this->em->flush();
    }

    /**
     * `$permalink` wraps via `Permalink::from()` -- this method's only
     * real caller ({@see \Piwigo\Permalink\PermalinkService::
     * setCatPermalink()}) reaches it only after its own manual charset/
     * numeric validation (mirrored by Permalink's own constraints) has
     * already passed for this exact value.
     */
    public function deleteOldPermalink(int $catId, string $permalink): void
    {
        $this->em->createQueryBuilder()
            ->delete(OldPermalinkEntity::class, 'op')
            ->where('op.catId = :catId')
            ->andWhere('op.permalink = :permalink')
            ->setParameter('catId', CategoryId::from($catId))
            ->setParameter('permalink', Permalink::from($permalink))
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
     *
     * `tryFrom()`, not `from()` -- unlike the other 4 methods in this
     * class, this one's only real caller
     * ({@see \Piwigo\Controller\Admin\PermalinksSubController}) reaches it
     * with a raw admin-submitted POST field value
     * ({@see \Piwigo\Controller\Admin\Request\PermalinksRequest::
     * $deletePermanent}), not a value already read back from a real row --
     * a malformed value can never match a real permalink anyway, so it's
     * treated the same as any other no-match, matching this method's own
     * existing `bool` not-found contract instead of throwing.
     */
    public function deleteOldPermalinkByValue(string $permalink): bool
    {
        $permalinkVo = Permalink::tryFrom($permalink);
        if (! $permalinkVo instanceof Permalink) {
            return false;
        }

        $affected = $this->em->createQueryBuilder()
            ->delete(OldPermalinkEntity::class, 'op')
            ->where('op.permalink = :permalink')
            ->setParameter('permalink', $permalinkVo)
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

        if ($sortField instanceof OldPermalinkSortField) {
            $qb->orderBy($sortField->dqlProperty());
        }

        $entities = $qb->getQuery()
            ->getResult();

        return array_map(OldPermalink::fromEntity(...), $entities);
    }

    /**
     * {@see OldPermalinkLookupInterface} implementation. Category is
     * L2aCoreDomain, this file's own OldPermalinkEntity is
     * L2bExtendedDomain, so a direct dependency the other way would
     * violate the layering ruleset. `op.catId`/`c.id`/`c.permalink` all
     * map through their own custom Doctrine Types, so `getArrayResult()`
     * hydrates each as a real VO (Gotcha #1 shape) -- read via
     * `instanceof` below, already written defensively enough (both
     * branches of the merged `$oldRows`/`$categoryRows` result set) that
     * `CategoryEntity::$id`/`$permalink` becoming Type-mapped needed no
     * further change here.
     */
    #[Override]
    public function findPermalinkMatches(array $permalinks): array
    {
        if ($permalinks === []) {
            return [];
        }

        $em = $this->em;

        $oldRows = $em->createQueryBuilder()
            ->select('op.catId AS id', 'op.permalink AS permalink', '1 AS is_old')
            ->from(OldPermalinkEntity::class, 'op')
            ->where('op.permalink IN (:permalinks)')
            ->setParameter('permalinks', $permalinks, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $categoryRows = $em->getRepository(CategoryEntity::class)->createQueryBuilder('c')
            ->select('c.id AS id', 'c.permalink AS permalink', '0 AS is_old')
            ->where('c.permalink IN (:permalinks)')
            ->setParameter('permalinks', $permalinks, ArrayParameterType::STRING)
            ->getQuery()
            ->getArrayResult();

        $byPermalink = [];
        foreach ([...$oldRows, ...$categoryRows] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $idValue = $id instanceof CategoryId ? $id->value : (is_numeric($id) ? (int) $id : null);
            $permalink = $row['permalink'] ?? null;
            $permalink = $permalink instanceof Permalink ? $permalink->value : $permalink;
            $isOld = $row['is_old'] ?? null;

            if ($idValue === null || ! is_string($permalink) || ! is_numeric($isOld)) {
                continue;
            }

            $byPermalink[$permalink] = [
                'id' => $idValue,
                'permalink' => $permalink,
                'is_old' => (int) $isOld,
            ];
        }

        return $byPermalink;
    }

    /**
     * {@see OldPermalinkLookupInterface} implementation. No `LIMIT`
     * clause -- ORM's QueryBuilder rejects `setMaxResults()` on an
     * UPDATE/DELETE DQL statement outright, but `permalink` is
     * OldPermalinkEntity's own single-column PK, so `WHERE op.permalink =
     * :permalink` alone is already at most one row; `cat_id` stays as a
     * defensive extra condition.
     */
    #[Override]
    public function touchOldPermalinkHit(string $permalink, int $catId): void
    {
        $this->em
            ->createQueryBuilder()
            ->update(OldPermalinkEntity::class, 'op')
            ->set('op.lastHit', 'CURRENT_TIMESTAMP()')
            ->set('op.hit', 'op.hit + 1')
            ->where('op.permalink = :permalink')
            ->andWhere('op.catId = :catId')
            ->setParameter('permalink', Permalink::from($permalink))
            ->setParameter('catId', CategoryId::from($catId))
            ->getQuery()
            ->execute();
    }

    /**
     * {@see OldPermalinkLookupInterface} implementation, carved out of
     * {@see \Piwigo\Category\CategoryRepository::findOrphanedColumnValues()}'s
     * old generic dispatcher -- `old_permalinks` is owned here
     * (`Permalink`, L2bExtendedDomain), not in `Category`
     * (L2aCoreDomain), which cannot depend on it directly. `getSingleColumnResult()`
     * never applies custom Doctrine Type conversion (`op.catId` comes
     * back as a plain scalar despite being `CategoryId`-typed), same
     * gotcha every other `getSingleColumnResult()` caller in this
     * codebase already accounts for.
     *
     * @return list<string>
     */
    #[Override]
    public function findOrphanedCatIds(): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('DISTINCT op.catId')
            ->from(OldPermalinkEntity::class, 'op')
            ->leftJoin(CategoryEntity::class, 'c', Join::WITH, 'op.catId = c.id')
            ->where('c.id IS NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_map(
            static fn (mixed $id): string => is_scalar($id) ? (string) $id : '',
            $ids
        ));
    }

    /**
     * {@see OldPermalinkLookupInterface} implementation -- sibling of
     * {@see findOrphanedCatIds()} above.
     *
     * @param list<string> $catIds
     */
    #[Override]
    public function deleteOldPermalinksForCatIds(array $catIds): void
    {
        if ($catIds === []) {
            return;
        }

        $this->em->createQueryBuilder()
            ->delete(OldPermalinkEntity::class, 'op')
            ->where('op.catId IN (:catIds)')
            ->setParameter('catIds', array_map(intval(...), $catIds), ArrayParameterType::INTEGER)
            ->getQuery()
            ->execute();
        $this->em->clear();
    }
}
