<?php

declare(strict_types=1);

namespace Piwigo\Users;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Image\Entity\Image;

/**
 * Persistence for the `favorites` table — per-user image bookmarks.
 *
 * Extracted in F5-d/13 from `ImageRepository` (favorite-related reads
 * lived under the image domain) and from `UserRepository` (favorite
 * writes lived under the user domain — now consolidated). Favorites
 * are keyed by (user_id, image_id) with no first-class id.
 */
final class UserFavoriteRepository extends AbstractRepository
{
    /** Insert a (user_id, image_id) pair (errors on duplicate). */
    public function add(int $userId, int $imageId): void
    {
        $this->conn->executeStatement(
            'INSERT INTO ' . $this->table('favorites') . ' (image_id, user_id) VALUES (?, ?)',
            [$imageId, $userId]
        );
    }

    /** Insert ignoring (user_id, image_id) PK conflicts. */
    public function addIgnore(int $userId, int $imageId): void
    {
        $this->conn->executeStatement(
            'INSERT IGNORE INTO ' . $this->table('favorites') . ' (image_id, user_id) VALUES (?, ?)',
            [$imageId, $userId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /** True when the given image is in the user's favorites. */
    public function exists(int $userId, int $imageId): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('favorites'))
            ->where('image_id = :imageId')
            ->andWhere('user_id = :userId')
            ->setParameter('imageId', $imageId)
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /** Remove a single (user_id, image_id) pair. */
    public function delete(int $userId, int $imageId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('favorites'))
            ->where('user_id = :userId')
            ->andWhere('image_id = :imageId')
            ->setParameter('userId', $userId)
            ->setParameter('imageId', $imageId)
            ->executeStatement();
    }

    /** Delete every favorite the user has (used by "remove all from favorites"). */
    public function deleteAllByUserId(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('favorites'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /**
     * Delete favorites entries for the given image ids (used when images
     * are deleted from the gallery — keeps the table consistent).
     *
     * @param int[] $imageIds
     */
    public function deleteByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('favorites'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return image_ids in the user's favorites (no permission filter).
     *
     * @return list<int>
     */
    public function findImageIdsByUserId(int $userId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('favorites'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return image_ids in the user's favorites that are still in a
     * permission-visible category.
     *
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return list<int>
     */
    public function findAuthorizedImageIds(
        int $userId,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $query  = 'SELECT DISTINCT f.image_id FROM ' . $this->table('favorites') . ' AS f'
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON f.image_id = ic.image_id'
            . ' WHERE f.user_id = ? ' . $permWhere;
        $params = [$userId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->conn->executeQuery($query, $params, $types)->fetchFirstColumn());
    }

    /**
     * Same as findImageIdsByUserId() but with an extra permission filter
     * (joins images) and a caller-supplied ORDER BY suffix.
     *
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return list<int>
     */
    public function findImageIdsForUserAuth(
        int $userId,
        string $permWhere,
        array $permParams,
        array $permTypes,
        string $orderBySuffix,
    ): array {
        $query = 'SELECT image_id FROM ' . $this->table('favorites')
            . ' INNER JOIN ' . $this->table('images') . ' ON image_id = id'
            . ' WHERE user_id = ? ' . $permWhere . ' ' . $orderBySuffix;
        $params = [$userId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'image_id'));
    }

    /**
     * Return full image entities for the user's favorites, subject to the
     * caller's permission filter and ORDER BY suffix.
     *
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return list<Image>
     */
    public function findImagesWithDetails(
        int $userId,
        string $permWhere,
        array $permParams,
        array $permTypes,
        string $orderBySuffix,
    ): array {
        $query  = 'SELECT i.* FROM ' . $this->table('favorites') . ' INNER JOIN ' . $this->table('images') . ' i'
            . ' ON image_id = i.id WHERE user_id = ? ' . $permWhere . ' ' . $orderBySuffix;
        $params = [$userId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return array_map(Image::fromRow(...), $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative());
    }
}
