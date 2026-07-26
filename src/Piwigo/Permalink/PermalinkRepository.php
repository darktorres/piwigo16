<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Category\CategoryEntity;
use Piwigo\Db\Tables;
use Piwigo\Permalink\Projection\OldPermalink;

/**
 * Persistence layer for the category-permalink domain. Owns no table
 * itself -- `categories.permalink` is Category\CategoryEntity's own
 * column (writes here go through it, find+set+flush, rather than raw
 * DBAL, since a raw write would leave any CategoryEntity already in this
 * EntityManager's identity map stale); `old_permalinks` is deliberately
 * never entity-mapped anywhere in this migration (see
 * Category\CategoryEntity's own docblock). Holds EntityManagerInterface
 * directly, same shape as Auth\AuthRepository.
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
        $value = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('id')
            ->from(Tables::categories())
            ->where('permalink = :permalink')
            ->setParameter('permalink', $permalink)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Return the category id a permalink was historically used by, or null.
     */
    public function findOldCategoryId(string $permalink): ?int
    {
        $value = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('c.id')
            ->from(Tables::oldPermalinks(), 'op')
            ->innerJoin('op', Tables::categories(), 'c', 'op.cat_id = c.id')
            ->where('op.permalink = :permalink')
            ->setMaxResults(1)
            ->setParameter('permalink', $permalink)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($value) ? (int) $value : null;
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
     * now. The timestamp is computed in PHP and bound as a parameter --
     * cross-provider safe, not SQL's NOW() (same reasoning as
     * SessionRepository).
     */
    public function markOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
            ->update(Tables::oldPermalinks())
            ->set('date_deleted', ':deleted')
            ->where('cat_id = :cat_id')
            ->andWhere('permalink = :permalink')
            ->setParameter('deleted', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('cat_id', $catId)
            ->setParameter('permalink', $permalink)
            ->executeStatement();
    }

    /**
     * Inserts a new old-permalink row already marked deleted now (the
     * category never actually used this permalink live -- it's being
     * recorded purely so the name can't be reused without going through
     * the permalink-history deletion flow first).
     */
    public function insertOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->em->getConnection()
            ->executeStatement(
                'INSERT INTO ' . Tables::oldPermalinks() . ' (permalink, cat_id, date_deleted) VALUES (?, ?, ?)',
                [$permalink, $catId, new \DateTimeImmutable()],
                [ParameterType::STRING, ParameterType::INTEGER, Types::DATETIME_IMMUTABLE],
            );
    }

    public function deleteOldPermalink(int $catId, string $permalink): void
    {
        $this->em->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::oldPermalinks())
            ->where('cat_id = :cat_id')
            ->andWhere('permalink = :permalink')
            ->setParameter('cat_id', $catId)
            ->setParameter('permalink', $permalink)
            ->executeStatement();
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
        $affected = $this->em->getConnection()
            ->createQueryBuilder()
            ->delete(Tables::oldPermalinks())
            ->where('permalink = :permalink')
            ->setParameter('permalink', $permalink)
            ->executeStatement();

        return $affected > 0;
    }

    /**
     * Every deleted-permalink row, optionally ordered by a caller-validated
     * column name (PermalinksSubController's own $sort_by allowlist --
     * never raw user input, same "trusted pre-validated fragment"
     * precedent as GroupRepository::findWithMemberCounts()'s own $order).
     *
     * @return list<OldPermalink>
     */
    public function findAllOrderedBy(?string $orderByColumn): array
    {
        $qb = $this->em->getConnection()
            ->createQueryBuilder()
            ->select('*')
            ->from(Tables::oldPermalinks());

        if ($orderByColumn !== null) {
            $qb->orderBy($orderByColumn);
        }

        return array_map(OldPermalink::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
    }
}
