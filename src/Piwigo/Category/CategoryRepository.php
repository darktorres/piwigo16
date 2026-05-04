<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Db\AbstractRepository;

/** Persistence layer for the category domain. */
final class CategoryRepository extends AbstractRepository
{
    /**
     * Remove all image→category links for the given image ids.
     * Called when images are permanently deleted.
     *
     * @param int[] $imageIds
     */
    public function deleteImageCategoryByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_category'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Clear representative_picture_id for the given category ids.
     * Used by categories_integrity() to remove stale representants.
     *
     * @param int[] $categoryIds
     */
    public function clearRepresentatives(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->set('representative_picture_id', 'NULL');
        $qb->where($qb->expr()->in('id', ':categoryIds'))
           ->setParameter('categoryIds', $categoryIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Remove image→category links for the given category ids.
     *
     * @param int[] $categoryIds
     */
    public function deleteImageCategoryByCategoryIds(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_category'));
        $qb->where($qb->expr()->in('category_id', ':ids'))
           ->setParameter('ids', $categoryIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete categories by id.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete permalink history for the given category ids.
     *
     * @param int[] $categoryIds
     */
    public function deletePermalinksByCategoryIds(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('old_permalinks'));
        $qb->where($qb->expr()->in('cat_id', ':ids'))
           ->setParameter('ids', $categoryIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete group→category access records for the given category ids.
     *
     * @param int[] $categoryIds
     */
    public function deleteGroupAccessByCategoryIds(array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('group_access'));
        $qb->where($qb->expr()->in('cat_id', ':ids'))
           ->setParameter('ids', $categoryIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return existing (image_id, category_id) pairs for the given images and categories.
     * Used by associate_images_to_categories to skip already-linked pairs.
     *
     * @param int[] $imageIds
     * @param int[] $categoryIds
     * @return list<array<string, mixed>>
     */
    public function findExistingImageCategoryLinks(array $imageIds, array $categoryIds): array
    {
        if ($imageIds === [] || $categoryIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id', 'category_id')
            ->from($this->table('image_category'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->andWhere($qb->expr()->in('category_id', ':categoryIds'))
           ->setParameter('imageIds', $imageIds, \Doctrine\DBAL\ArrayParameterType::INTEGER)
           ->setParameter('categoryIds', $categoryIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Delete image→category links for a specific category and image set.
     * Used by dissociate_images_from_category.
     *
     * @param int[] $imageIds
     */
    public function deleteImageCategoryByCategoryAndImageIds(int $categoryId, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_category'))
            ->where('category_id = :categoryId')
            ->setParameter('categoryId', $categoryId);
        $qb->andWhere($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /** Delete a site record by id. */
    public function deleteSiteById(int $siteId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('sites'))
            ->where('id = :id')
            ->setParameter('id', $siteId)
            ->executeStatement();
    }

    /** Total number of albums (categories). */
    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('categories'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Return a single category row by id, or null if not found. */
    /** @return array<string, mixed>|null */
    public function findCategoryById(int $id): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('categories'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /** Return category ids whose site_id matches the given value. */
    /** @return int[] */
    public function findIdsBySiteId(int $siteId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Return distinct image_ids linked to the given category ids via image_category.
     *
     * @param int[] $categoryIds
     * @return int[]
     */
    public function findLinkedImageIdsByCategoryIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT image_id')
            ->from($this->table('image_category'));
        $qb->where($qb->expr()->in('category_id', ':ids'))
           ->setParameter('ids', $categoryIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return distinct image_ids that are in $imageIds but belong to categories
     * NOT in $excludedCategoryIds.  Used to find non-orphan images when deleting categories.
     *
     * @param int[] $imageIds
     * @param int[] $excludedCategoryIds
     * @return int[]
     */
    public function findLinkedImageIdsNotIn(array $imageIds, array $excludedCategoryIds): array
    {
        if ($imageIds === [] || $excludedCategoryIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT image_id')
            ->from($this->table('image_category'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->andWhere($qb->expr()->notIn('category_id', ':catIds'))
           ->setParameter('imageIds', $imageIds, \Doctrine\DBAL\ArrayParameterType::INTEGER)
           ->setParameter('catIds', $excludedCategoryIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return category ids that use one of the given image ids as their representative.
     *
     * @param int[] $imageIds
     * @return int[]
     */
    public function findIdsByRepresentativePicture(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('representative_picture_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return image_ids present in image_category that have no corresponding row in images.
     *
     * @return int[]
     */
    public function findOrphanImageCategoryLinks(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT ic.image_id FROM ' . $this->table('image_category') . ' ic
             LEFT JOIN ' . $this->table('images') . ' i ON i.id = ic.image_id
             WHERE i.id IS NULL'
        )->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Delete image_category rows whose image_id is in the given list.
     *
     * @param int[] $orphanImageIds
     */
    public function deleteOrphanImageCategoryLinks(array $orphanImageIds): void
    {
        if ($orphanImageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_category'));
        $qb->where($qb->expr()->in('image_id', ':ids'))
           ->setParameter('ids', $orphanImageIds, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return all categories ordered for global-rank recomputation.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllForRankUpdate(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('id', 'id_uppercat', 'uppercats', '`rank`', 'global_rank')
            ->from($this->table('categories'))
            ->orderBy('id_uppercat')
            ->addOrderBy('`rank`')
            ->addOrderBy('name')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Set the visible flag on the given category ids.
     *
     * @param int[] $ids
     */
    public function setVisible(array $ids, bool $visible): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->set('visible', ':visible')
            ->setParameter('visible', $visible ? 'true' : 'false');
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Set the status ('public' or 'private') on the given category ids.
     *
     * @param int[] $ids
     */
    public function setStatus(array $ids, string $status): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->set('status', ':status')
            ->setParameter('status', $status);
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return (id, name, id_uppercat, uppercats, global_rank) for the given ids.
     *
     * @param array<int|string> $ids
     * @return list<array<string, mixed>>
     */
    public function findDetailsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_map('intval', $ids);
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'id_uppercat', 'uppercats', 'global_rank')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return (id, status) rows for the given category ids, indexed by id.
     *
     * @param array<int|string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function findStatusByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_map('intval', $ids);
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'status')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        $result = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $result[(string) $row['id']] = $row;
        }
        return $result;
    }

    /**
     * Return the uppercats strings for the given category ids.
     * Used by get_uppercat_ids() to collect all ancestor ids.
     *
     * @param array<int|string> $ids
     * @return string[]
     */
    public function findUppercatsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_map('intval', $ids);
        $qb = $this->conn->createQueryBuilder()
            ->select('uppercats')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, \Doctrine\DBAL\ArrayParameterType::INTEGER);
        return array_map('strval', $qb->executeQuery()->fetchFirstColumn());
    }

    /** Record a hit on an old permalink entry. */
    public function updatePermalinkHit(int $catId, string $permalink): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('old_permalinks'))
            ->set('last_hit', 'NOW()')
            ->set('hit', 'hit + 1')
            ->where('permalink = :permalink')
            ->andWhere('cat_id = :catId')
            ->setParameter('permalink', $permalink)
            ->setParameter('catId', $catId)
            ->executeStatement();
    }
}
