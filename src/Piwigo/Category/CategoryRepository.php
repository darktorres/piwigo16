<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
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
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
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
           ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
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
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
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
           ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER);
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
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
           ->setParameter('categoryIds', $categoryIds, ArrayParameterType::INTEGER);
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
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
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

    /**
     * Update the image_order column for a single category.
     * $imageOrder = null clears the custom order (resets to default).
     */
    public function updateImageOrder(int $catId, ?string $imageOrder): void
    {
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->where('id = :id')
            ->setParameter('id', $catId);

        if ($imageOrder === null) {
            $qb->set('image_order', 'NULL');
        } else {
            $qb->set('image_order', ':order')
               ->setParameter('order', $imageOrder);
        }

        $qb->executeStatement();
    }

    /**
     * Update image_order for all sub-categories of $uppercats
     * (i.e. whose uppercats starts with the given prefix).
     */
    public function updateImageOrderForSubcats(string $uppercats, ?string $imageOrder): void
    {
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->where('uppercats LIKE :pattern')
            ->setParameter('pattern', $uppercats . ',%');

        if ($imageOrder === null) {
            $qb->set('image_order', 'NULL');
        } else {
            $qb->set('image_order', ':order')
               ->setParameter('order', $imageOrder);
        }

        $qb->executeStatement();
    }

    /**
     * Set representative_picture_id for the given category ids.
     *
     * @param int[] $ids
     */
    public function setRepresentativePicture(array $ids, int $imageId): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->set('representative_picture_id', ':imageId')
            ->setParameter('imageId', $imageId);
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return (category_id, uppercats, dir) for all categories linked to the given image.
     *
     * @return list<array<string, mixed>>
     */
    public function findCategoryInfosByImageId(int $imageId): array
    {
        return $this->conn->executeQuery(
            'SELECT ic.category_id, c.uppercats, c.dir
             FROM ' . $this->table('image_category') . ' ic
             INNER JOIN ' . $this->table('categories') . ' c ON c.id = ic.category_id
             WHERE ic.image_id = ?',
            [$imageId]
        )->fetchAllAssociative();
    }

    /**
     * Return all columns for the given category ids.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /** Count distinct image_ids linked to any category (i.e. non-orphan images). */
    public function countLinkedImages(): int
    {
        $value = $this->conn->executeQuery(
            'SELECT COUNT(DISTINCT image_id) FROM ' . $this->table('image_category')
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count images linked to the given category. */
    public function countImagesByCategoryId(int $catId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('image_category'))
            ->where('category_id = :catId')
            ->setParameter('catId', $catId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return true if the given image is associated with the given category.
     */
    public function hasImageInCategory(int $imageId, int $catId): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('image_category'))
            ->where('image_id = :imageId')
            ->andWhere('category_id = :catId')
            ->setParameter('imageId', $imageId)
            ->setParameter('catId', $catId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /**
     * Update id_uppercat for the given category ids.
     * $parentId = null means root-level (id_uppercat IS NULL).
     *
     * @param int[] $ids
     */
    public function updateParent(array $ids, ?int $parentId): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'));

        if ($parentId === null) {
            $qb->set('id_uppercat', 'NULL');
        } else {
            $qb->set('id_uppercat', ':parentId')->setParameter('parentId', $parentId);
        }

        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /** Return the status of a single category, or null if not found. */
    public function findStatusById(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('status')
            ->from($this->table('categories'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return the maximum rank for siblings of a given parent.
     * $parentId = null means top-level categories (id_uppercat IS NULL).
     */
    public function findMaxRankForParent(?int $parentId): ?int
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('MAX(`rank`)')
            ->from($this->table('categories'));

        if ($parentId === null) {
            $qb->where('id_uppercat IS NULL');
        } else {
            $qb->where('id_uppercat = :parentId')->setParameter('parentId', $parentId);
        }

        $value = $qb->executeQuery()->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Return the maximum rank in the given category, or null if empty.
     */
    public function findMaxRankInCategory(int $catId): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('MAX(`rank`)')
            ->from($this->table('image_category'))
            ->where('category_id = :catId')
            ->setParameter('catId', $catId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Increment rank for all images in $catId with rank >= $fromRank.
     * Used when inserting a photo at a specific position.
     */
    public function incrementRanksFrom(int $catId, int $fromRank): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . $this->table('image_category') .
            ' SET `rank` = `rank` + 1 WHERE category_id = ? AND `rank` IS NOT NULL AND `rank` >= ?',
            [$catId, $fromRank]
        );
    }

    /**
     * Set the rank for a specific image-category association.
     */
    public function setImageRank(int $imageId, int $catId, int $rank): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('image_category'))
            ->set('`rank`', ':rank')
            ->where('image_id = :imageId')
            ->andWhere('category_id = :catId')
            ->setParameter('rank', $rank)
            ->setParameter('imageId', $imageId)
            ->setParameter('catId', $catId)
            ->executeStatement();
    }

    /**
     * Delete image_category links for a single image and specific category ids.
     * Used when removing an image from certain categories in replace mode.
     *
     * @param int[] $catIds
     */
    public function removeImageFromCategories(int $imageId, array $catIds): void
    {
        if ($catIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_category'))
            ->where('image_id = :imageId')
            ->setParameter('imageId', $imageId);
        $qb->andWhere($qb->expr()->in('category_id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Set the commentable flag on the given category ids.
     *
     * @param int[] $ids
     */
    public function setCommentable(array $ids, bool $commentable): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('categories'))
            ->set('commentable', ':val')
            ->setParameter('val', $commentable ? 1 : 0);
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return ids from the given list that belong to private categories.
     *
     * @param int[] $ids
     * @return int[]
     */
    public function findPrivateByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->where("status = 'private'");
        $qb->andWhere($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /** Count hidden (locked) albums (visible = 0). */
    public function countHidden(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('categories'))
            ->where('visible = 0')
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Return true if a category with the given id exists. */
    public function existsById(int $id): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('categories'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /**
     * Return the uppercats string for a single category, or null if not found.
     */
    public function findUppercatsStringById(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('uppercats')
            ->from($this->table('categories'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return a map of category id → dir for the given ids.
     *
     * @param int[] $ids
     * @return array<int, string|null>
     */
    public function findIdDirMap(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'dir')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $result = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $result[is_numeric($row['id']) ? (int) $row['id'] : 0] = is_string($row['dir']) ? $row['dir'] : null;
        }
        return $result;
    }

    /**
     * Return the galleries_url from the site linked to the given category.
     * Returns null if the category has no site association.
     */
    public function findGalleriesUrlByCategoryId(int $catId): ?string
    {
        $value = $this->conn->executeQuery(
            'SELECT s.galleries_url FROM ' . $this->table('sites') . ' AS s
             JOIN ' . $this->table('categories') . ' AS c ON s.id = c.site_id
             WHERE c.id = ?',
            [$catId]
        )->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return true if the given category has at least one image linked to it.
     */
    public function hasCategoryImages(int $catId): bool
    {
        $value = $this->conn->createQueryBuilder()
            ->select('DISTINCT category_id')
            ->from($this->table('image_category'))
            ->where('category_id = :catId')
            ->setParameter('catId', $catId)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $value !== false;
    }

    /**
     * Return (image_count, min_date, max_date) for images in the given category.
     *
     * @return array{0: int, 1: string|null, 2: string|null}
     */
    public function findImageStats(int $catId): array
    {
        $row = $this->conn->executeQuery(
            'SELECT COUNT(image_id), MIN(DATE(date_available)), MAX(DATE(date_available))
             FROM ' . $this->table('images') . '
             JOIN ' . $this->table('image_category') . ' ON image_id = id
             WHERE category_id = ?',
            [$catId]
        )->fetchNumeric();

        $row0 = ($row !== false) ? ($row[0] ?? null) : null;
        $row1 = ($row !== false) ? ($row[1] ?? null) : null;
        $row2 = ($row !== false) ? ($row[2] ?? null) : null;
        return [
            is_numeric($row0) ? (int) $row0 : 0,
            is_string($row1) ? $row1 : null,
            is_string($row2) ? $row2 : null,
        ];
    }

    /** Count virtual albums (dir IS NULL). */
    public function countVirtual(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('categories'))
            ->where('dir IS NULL')
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count physical albums (dir IS NOT NULL). */
    public function countPhysical(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('categories'))
            ->where('dir IS NOT NULL')
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Total number of image–category association rows. */
    public function countImageCategoryLinks(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('image_category'))
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
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
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
           ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER);
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
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
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
           ->setParameter('catIds', $excludedCategoryIds, ArrayParameterType::INTEGER);
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
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
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
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
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
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
           ->setParameter('ids', $orphanImageIds, ArrayParameterType::INTEGER);
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
            ->setParameter('visible', $visible ? 1 : 0);
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
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
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
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
        $ids = array_map(intval(...), $ids);
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'id_uppercat', 'uppercats', 'global_rank')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
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
        $ids = array_map(intval(...), $ids);
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'status')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $result = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $result[is_scalar($row['id'] ?? null) ? (string) $row['id'] : ''] = $row;
        }
        return $result;
    }

    /**
     * Return the uppercats strings for the given category ids.
     * Used by CategoryAdminService::getUppercatIds() to collect all ancestor ids.
     *
     * @param array<int|string> $ids
     * @return string[]
     */
    public function findUppercatsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $ids);
        $qb = $this->conn->createQueryBuilder()
            ->select('uppercats')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $qb->executeQuery()->fetchFirstColumn());
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
