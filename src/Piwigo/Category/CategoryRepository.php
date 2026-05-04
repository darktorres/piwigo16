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
}
