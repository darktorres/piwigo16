<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the category-permalink domain. */
final class PermalinkRepository extends AbstractRepository
{
    /** Return the category id whose current permalink matches, or null. */
    public function findCategoryIdByPermalink(string $permalink): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->where('permalink = :permalink')
            ->setParameter('permalink', $permalink)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Find the category id that previously used $permalink (via old_permalinks).
     * Returns null if not found.
     */
    public function findOldCategoryId(string $permalink): ?int
    {
        $value = $this->conn->executeQuery(
            'SELECT c.id FROM ' . $this->table('old_permalinks') . ' op
             INNER JOIN ' . $this->table('categories') . ' c ON op.cat_id = c.id
             WHERE op.permalink = ? LIMIT 1',
            [$permalink]
        )->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /** Return the current permalink of a category, or null if none set. */
    public function findPermalinkByCategoryId(int $catId): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('permalink')
            ->from($this->table('categories'))
            ->where('id = :id')
            ->setParameter('id', $catId)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /** Set permalink to NULL for the given category (remove permalink). */
    public function clearCategoryPermalink(int $catId): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->set('permalink', 'NULL')
            ->where('id = :id')
            ->setParameter('id', $catId)
            ->executeStatement();
    }

    /** Set permalink to the new value for the given category. */
    public function setCategoryPermalink(int $catId, string $permalink): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->set('permalink', ':permalink')
            ->where('id = :id')
            ->setParameter('permalink', $permalink)
            ->setParameter('id', $catId)
            ->executeStatement();
    }

    /** Mark an existing old_permalinks row as deleted (set date_deleted = NOW()). */
    public function markOldPermalinkDeleted(int $catId, string $permalink): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('old_permalinks'))
            ->set('date_deleted', 'NOW()')
            ->where('cat_id = :catId')
            ->andWhere('permalink = :permalink')
            ->setParameter('catId', $catId)
            ->setParameter('permalink', $permalink)
            ->executeStatement();
    }

    /** Insert a new old_permalink row with date_deleted set to NOW(). */
    public function insertOldPermalinkDeleted(string $permalink, int $catId): void
    {
        $now = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $this->conn->insert($this->table('old_permalinks'), [
            'permalink'    => $permalink,
            'cat_id'       => $catId,
            'date_deleted' => $now,
        ]);
    }

    /**
     * Delete an old_permalink entry by permalink value only.
     * Returns true if a row was deleted.
     */
    public function deleteOldPermalinkByValue(string $permalink): bool
    {
        // DBAL doesn't support LIMIT on DELETE in a platform-agnostic way;
        // use raw SQL since the project is MySQL-only.
        $affected = $this->conn->executeStatement(
            'DELETE FROM ' . $this->table('old_permalinks') . ' WHERE permalink = ? LIMIT 1',
            [$permalink]
        );
        return $affected > 0;
    }

    /** Delete an old_permalink entry (when reactivating that permalink). */
    public function deleteOldPermalink(int $catId, string $permalink): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('old_permalinks'))
            ->where('cat_id = :catId')
            ->andWhere('permalink = :permalink')
            ->setParameter('catId', $catId)
            ->setParameter('permalink', $permalink)
            ->executeStatement();
    }
}
