<?php

declare(strict_types=1);

namespace Piwigo\Caddie;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;

/**
 * Persistence layer for the caddie (per-user image bookmark list).
 */
final class CaddieRepository extends AbstractRepository
{
    /**
     * Of the supplied image ids, return those that exist in the images table
     * AND are not yet in $userId's caddie. Used by caddie.add to compute the
     * "new" subset that needs an insert.
     *
     * @param  list<int> $imageIds
     * @return list<int>
     */
    public function findImagesNotInCaddie(array $imageIds, int $userId): array
    {
        if ($imageIds === []) {
            return [];
        }
        $sql = 'SELECT id FROM ' . $this->table('images')
            . ' LEFT JOIN ' . $this->table('caddie') . ' ON id = element_id AND user_id = ?'
            . ' WHERE id IN (?) AND element_id IS NULL';
        $rows = $this->conn->executeQuery(
            $sql,
            [$userId, $imageIds],
            [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /**
     * Bulk-add (element_id, user_id) caddie rows atomically.
     *
     * @param list<int> $imageIds
     */
    public function insertImageIdsBatch(int $userId, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $this->conn->transactional(function () use ($userId, $imageIds): void {
            foreach ($imageIds as $imageId) {
                $this->conn->insert($this->table('caddie'), ['element_id' => $imageId, 'user_id' => $userId]);
            }
        });
    }
}
