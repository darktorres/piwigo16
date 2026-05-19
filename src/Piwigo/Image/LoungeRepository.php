<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence for the `lounge` table — the staging area where freshly
 * uploaded images sit before they get formally associated with a
 * category. Extracted from `ImageRepository` in F5-d/12: lounge is a
 * distinct aggregate with its own lifecycle (insert on upload, drain on
 * association/take-off).
 */
final class LoungeRepository extends AbstractRepository
{
    /** Count images currently sitting in the upload lounge. */
    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('lounge'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return all image_ids that currently live in the lounge.
     *
     * @return list<int>
     */
    public function findImageIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('lounge'))
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return the oldest lounge entry (smallest image_id) joined with the
     * images table to surface its date_available, plus the DB's current
     * timestamp. Used by the lounge take-off scheduler.
     *
     * @return array{image_id: int, date_available: string, dbnow: string}|null
     */
    public function findOldestEntry(): ?array
    {
        $row = $this->conn->executeQuery(
            'SELECT image_id, date_available, NOW() AS dbnow FROM ' . $this->table('lounge')
            . ' JOIN ' . $this->table('images') . ' ON image_id = id'
            . ' ORDER BY image_id ASC LIMIT 1'
        )->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return [
            'image_id'       => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
            'date_available' => is_string($row['date_available'] ?? null) ? $row['date_available'] : '',
            'dbnow'          => is_string($row['dbnow'] ?? null) ? $row['dbnow'] : '',
        ];
    }

    /**
     * Return all (image_id, category_id) pairs in lounge order.
     *
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findAllEntries(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT image_id, category_id FROM ' . $this->table('lounge') . ' ORDER BY category_id ASC, image_id ASC'
        )->fetchAllAssociative();
        return array_map(static fn (array $row): array => [
            'image_id'    => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
            'category_id' => is_numeric($row['category_id']) ? (int) $row['category_id'] : 0,
        ], $rows);
    }

    /** Drop all lounge entries with image_id <= $maxId. */
    public function deleteBeforeId(int $maxId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('lounge'))
            ->where('image_id <= :maxId')
            ->setParameter('maxId', $maxId)
            ->executeStatement();
    }

    /**
     * Insert lounge rows atomically with INSERT IGNORE so duplicates of the
     * (image_id, category_id) PK are silently skipped.
     *
     * @param list<array{image_id: int, category_id: int}> $rows
     */
    public function insertIgnoreDuplicates(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->executeStatement(
                    'INSERT IGNORE INTO ' . $this->table('lounge') . ' (image_id, category_id) VALUES (?, ?)',
                    [$row['image_id'], $row['category_id']],
                );
            }
        });
    }

    /**
     * Count lounge entries for a category whose image_id isn't yet present
     * in image_category. Used by addUploadedFile() to expose the "pending
     * association" count in the WS response.
     */
    public function countInCategoryNotAssociated(int $categoryId): int
    {
        $value = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . $this->table('lounge') . ' WHERE category_id = ?'
            . ' AND image_id NOT IN (SELECT image_id FROM ' . $this->table('image_category') . ')',
            [$categoryId],
            [ParameterType::INTEGER],
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }
}
