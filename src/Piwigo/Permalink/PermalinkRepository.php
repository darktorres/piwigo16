<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the category-permalink domain.
 */
final class PermalinkRepository extends AbstractRepository
{
    /**
     * Return the category id whose current permalink matches, or null.
     */
    public function findCategoryIdByPermalink(string $permalink): ?int
    {
        $value = $this->conn->createQueryBuilder()
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
        $value = $this->conn->createQueryBuilder()
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
        $value = $this->conn->createQueryBuilder()
            ->select('permalink')
            ->from(Tables::categories())
            ->where('id = :id')
            ->setParameter('id', $catId)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function clearCategoryPermalink(int $catId): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::categories())
            ->set('permalink', ':permalink')
            ->where('id = :id')
            ->setParameter('permalink', null)
            ->setParameter('id', $catId)
            ->executeStatement();
    }

    public function setCategoryPermalink(int $catId, string $permalink): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::categories())
            ->set('permalink', ':permalink')
            ->where('id = :id')
            ->setParameter('permalink', $permalink)
            ->setParameter('id', $catId)
            ->executeStatement();
    }

    /**
     * Marks an existing old-permalink row (cat_id, permalink) as deleted
     * now. The timestamp is computed in PHP and bound as a parameter --
     * cross-provider safe, not SQL's NOW() (same reasoning as
     * SessionRepository).
     */
    public function markOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->conn->createQueryBuilder()
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
        $this->conn->executeStatement(
            'INSERT INTO ' . Tables::oldPermalinks() . ' (permalink, cat_id, date_deleted) VALUES (?, ?, ?)',
            [$permalink, $catId, new \DateTimeImmutable()],
            [ParameterType::STRING, ParameterType::INTEGER, Types::DATETIME_IMMUTABLE],
        );
    }

    public function deleteOldPermalink(int $catId, string $permalink): void
    {
        $this->conn->createQueryBuilder()
            ->delete(Tables::oldPermalinks())
            ->where('cat_id = :cat_id')
            ->andWhere('permalink = :permalink')
            ->setParameter('cat_id', $catId)
            ->setParameter('permalink', $permalink)
            ->executeStatement();
    }
}
