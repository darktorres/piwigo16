<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Category\Entity\Category;
use Piwigo\Category\Projection\CategoryDetail;
use Piwigo\Category\Projection\CategoryNamePermalink;
use Piwigo\Category\Projection\CategoryNamePermalinkUppercats;
use Piwigo\Category\Projection\CategoryParentInfo;
use Piwigo\Category\Projection\CategoryRankInfo;
use Piwigo\Category\Projection\CategoryUppercatsSite;
use Piwigo\Category\Projection\ImageCategoryInfo;
use Piwigo\Category\Projection\ImageCategoryLink;
use Piwigo\Category\Projection\MenuCategoryRow;
use Piwigo\Category\Projection\PictureNavCategoryRow;
use Piwigo\Category\Projection\RankUpdateRow;
use Piwigo\Category\Projection\RelatedCategoryRow;
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
     * Delete categories and their permalink history atomically. FK CASCADE
     * clears child rows in image_category, user_access, group_access,
     * user_cache_categories; FK SET NULL nulls images.storage_category_id and
     * self-ref categories.id_uppercat (subtree promotion). old_permalinks has
     * no FK, hence the explicit cleanup.
     *
     * @param int[] $ids
     */
    public function deleteCategoriesAndPermalinksAtomically(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->conn->transactional(function () use ($ids): void {
            $this->deleteByIds($ids);
            $this->deletePermalinksByCategoryIds($ids);
        });
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
     * @param  int[] $imageIds
     * @param  int[] $categoryIds
     * @return list<ImageCategoryLink>
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
        return array_map(ImageCategoryLink::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
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
     * Return the album-association info for all categories the image is in
     * (category_id, uppercats, dir).
     *
     * @return list<ImageCategoryInfo>
     */
    public function findCategoryInfosByImageId(int $imageId): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT ic.category_id, c.uppercats, c.dir
             FROM ' . $this->table('image_category') . ' ic
             INNER JOIN ' . $this->table('categories') . ' c ON c.id = ic.category_id
             WHERE ic.image_id = ?',
            [$imageId]
        )->fetchAllAssociative();
        return array_map(ImageCategoryInfo::fromRow(...), $rows);
    }

    /**
     * Return every category as a (id, name, permalink) projection, keyed by
     * id. Used by HtmlService::getCatDisplayNameCache to build a per-request
     * lookup.
     *
     * @return array<int, CategoryNamePermalink>
     */
    public function findIdNamePermalinkAll(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink')
            ->from($this->table('categories'))
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $entity                  = CategoryNamePermalink::fromRow($row);
            $out[$entity->id->value] = $entity;
        }
        return $out;
    }

    /**
     * Return the server-side threshold date (YYYY-MM-DD) for "recent" images,
     * i.e. CURRENT_DATE − $days. Used by HtmlService::getIconHelper to flag
     * images uploaded within the recent_period window.
     */
    public function findRecentDateThreshold(int $days): string
    {
        $value = $this->conn->executeQuery(
            'SELECT ' . \Piwigo\Db\SqlExpr::recentPeriodExpr((string) $days),
        )->fetchOne();
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Return (id, name, permalink, uppercats) for the given ids, keyed by id.
     * Used by the admin comments page and the recent-cats listing.
     *
     * @param  int[] $ids
     * @return array<int, CategoryNamePermalinkUppercats>
     */
    public function findNamePermalinkUppercatsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink', 'uppercats')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $entity                  = CategoryNamePermalinkUppercats::fromRow($row);
            $out[$entity->id->value] = $entity;
        }
        return $out;
    }

    /**
     * Return all columns for the given category ids.
     *
     * @param  int[] $ids
     * @return list<Category>
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
        return array_map(Category::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
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

    /** Return a single category by id, or null if not found. */
    public function findCategoryById(int $id): ?Category
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('categories'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? Category::fromRow($row) : null;
    }

    /**
     * Return category ids visible to the metadata-sync filelist scan: physical
     * (dir IS NOT NULL) and on the given site, optionally restricted to a
     * single uppercats subtree.
     *
     * @return int[]
     */
    public function findFilelistCategoryIds(int $siteId, ?int $categoryId, bool $recursive): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->where('site_id = :siteId')
            ->andWhere('dir IS NOT NULL')
            ->setParameter('siteId', $siteId);
        if ($categoryId !== null) {
            if ($recursive) {
                $qb->andWhere('uppercats REGEXP :uppercatsPattern')
                   ->setParameter('uppercatsPattern', '(^|,)' . $categoryId . '(,|$)');
            } else {
                $qb->andWhere('id = :catId')
                   ->setParameter('catId', $categoryId);
            }
        }
        $rows = $qb->executeQuery()->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return category ids matching a caller-built OR of field-LIKE clauses
     * (each clause references a controlled column from $catFieldsDictionary
     * and a free-form $word wrapped in % wildcards). Used by SearchService
     * allwords decomposition.
     *
     * @param  list<string> $orClauses
     * @return list<int>
     */
    public function findIdsByOrClauses(array $orClauses): array
    {
        if ($orClauses === []) {
            return [];
        }
        $rows = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('categories') . ' WHERE ' . implode(' OR ', $orClauses),
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Search-text token query for categories: id/name/permalink projection
     * joined with user_cache_categories so categories not visible to $userId
     * are excluded. Used by qsearch to surface "matching categories" hints.
     *
     * @param  list<string> $clauses
     * @return list<CategoryNamePermalink>
     */
    public function findCategoriesByTextClausesForUser(int $userId, array $clauses): array
    {
        if ($clauses === []) {
            return [];
        }
        $rows = $this->conn->executeQuery(
            'SELECT id, name, permalink FROM ' . $this->table('categories')
            . ' INNER JOIN ' . $this->table('user_cache_categories')
            . ' ON id = cat_id AND user_id = ?'
            . ' WHERE (' . implode("\n OR ", $clauses) . ')',
            [$userId],
            [ParameterType::INTEGER],
        )->fetchAllAssociative();
        return array_map(CategoryNamePermalink::fromRow(...), $rows);
    }

    /**
     * Return (id, permalink, uppercats, global_rank) for every category that
     * has a permalink set. $orderBy is restricted to 'id' / 'permalink' by
     * the caller (admin permalinks page sort).
     *
     * @return list<array{id: int, permalink: string, uppercats: string, global_rank: string|null}>
     */
    public function findCategoriesWithPermalink(string $orderBy): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'permalink', 'uppercats', 'global_rank')
            ->from($this->table('categories'))
            ->where('permalink IS NOT NULL');
        if ($orderBy === 'id' || $orderBy === 'permalink') {
            $qb->orderBy($orderBy);
        }
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $idRaw = $row['id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[] = [
                'id'          => (int) $idRaw,
                'permalink'   => is_string($row['permalink'] ?? null) ? $row['permalink'] : '',
                'uppercats'   => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            ];
        }
        return $out;
    }

    /**
     * Return every row from old_permalinks, ordered by a caller-validated column.
     * $orderBy whitelist: cat_id / permalink / date_deleted / last_hit / hit.
     *
     * @return list<array{cat_id: int, permalink: string, date_deleted: string, last_hit: string|null, hit: int}>
     */
    public function findOldPermalinks(string $orderBy): array
    {
        $whitelist = ['cat_id', 'permalink', 'date_deleted', 'last_hit', 'hit'];
        $qb = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('old_permalinks'));
        if (in_array($orderBy, $whitelist, true)) {
            $qb->orderBy($orderBy);
        }
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $catIdRaw = $row['cat_id'] ?? null;
            if (!is_numeric($catIdRaw)) {
                continue;
            }
            $out[] = [
                'cat_id'       => (int) $catIdRaw,
                'permalink'    => is_string($row['permalink'] ?? null) ? $row['permalink'] : '',
                'date_deleted' => is_string($row['date_deleted'] ?? null) ? $row['date_deleted'] : '',
                'last_hit'     => is_string($row['last_hit'] ?? null) ? $row['last_hit'] : null,
                'hit'          => is_numeric($row['hit'] ?? null) ? (int) $row['hit'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Return every non-null permalink string assigned to a category.
     * Used by the admin extensions page to populate the URL-pattern picker.
     *
     * @return list<string>
     */
    public function findAllPermalinks(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT permalink FROM ' . $this->table('categories') . ' WHERE permalink IS NOT NULL',
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $rows);
    }

    /**
     * Per-site (categories, images) counts: result keyed by site_id with
     * {nb_categories, nb_images}. Used by the admin sites listing.
     *
     * @return array<int, array{nb_categories: int, nb_images: int}>
     */
    public function findSiteStorageStats(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT c.site_id, COUNT(DISTINCT c.id) AS nb_categories, COUNT(i.id) AS nb_images'
            . ' FROM ' . $this->table('categories') . ' AS c'
            . ' LEFT JOIN ' . $this->table('images') . ' AS i ON c.id = i.storage_category_id'
            . ' WHERE c.site_id IS NOT NULL'
            . ' GROUP BY c.site_id',
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $siteIdRaw = $row['site_id'] ?? null;
            if (!is_numeric($siteIdRaw)) {
                continue;
            }
            $out[(int) $siteIdRaw] = [
                'nb_categories' => is_numeric($row['nb_categories'] ?? null) ? (int) $row['nb_categories'] : 0,
                'nb_images'     => is_numeric($row['nb_images'] ?? null) ? (int) $row['nb_images'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Return all category ids in the table, in insertion order. Used by the
     * site_update sync to seed the next-rank-per-parent map.
     *
     * @return list<int>
     */
    public function findAllIds(): array
    {
        $rows = $this->conn->executeQuery('SELECT id FROM ' . $this->table('categories'))->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return id_uppercat → MAX(rank)+1 map. Rows with NULL id_uppercat are
     * keyed under 'NULL' in the result. Used by site_update to compute the
     * next rank when inserting freshly-discovered categories.
     *
     * @return array<int|string, int>
     */
    public function findNextRankByParent(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT id_uppercat, MAX(`rank`)+1 AS next_rank FROM ' . $this->table('categories') . ' GROUP BY id_uppercat',
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = (isset($row['id_uppercat']) && is_scalar($row['id_uppercat']) && $row['id_uppercat'] !== '') ? (string) $row['id_uppercat'] : 'NULL';
            $out[$key] = is_numeric($row['next_rank'] ?? null) ? (int) $row['next_rank'] : 1;
        }
        return $out;
    }

    /** Return MAX(id)+1 (the next inferred id) over the categories table; 1 when empty. */
    public function findNextAvailableId(): int
    {
        $value = $this->conn->executeQuery(
            'SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1) FROM ' . $this->table('categories'),
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * Physical-syncable category rows for $siteId — only rows whose dir is
     * not null. Optional $catFilter restricts to a single id (subcats included
     * via uppercats REGEXP when $subcatsIncluded is true). Result keyed by id.
     *
     * @return array<int, array{id: int, id_uppercat: int|null, uppercats: string, global_rank: string|null, status: string, visible: bool}>
     */
    public function findPhysicalSyncableForSite(int $siteId, ?int $catFilter, bool $subcatsIncluded): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'id_uppercat', 'uppercats', 'global_rank', 'status', 'visible')
            ->from($this->table('categories'))
            ->where('dir IS NOT NULL')
            ->andWhere('site_id = :siteId')
            ->setParameter('siteId', $siteId);
        if ($catFilter !== null) {
            if ($subcatsIncluded) {
                $qb->andWhere('uppercats REGEXP :uppercatsPattern')
                   ->setParameter('uppercatsPattern', '(^|,)' . $catFilter . '(,|$)');
            } else {
                $qb->andWhere('id = :catId')
                   ->setParameter('catId', $catFilter);
            }
        }
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $idRaw      = $row['id'] ?? null;
            $idUpRaw    = $row['id_uppercat'] ?? null;
            $visibleRaw = $row['visible'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[(int) $idRaw] = [
                'id'          => (int) $idRaw,
                'id_uppercat' => is_numeric($idUpRaw) ? (int) $idUpRaw : null,
                'uppercats'   => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
                'status'      => is_string($row['status'] ?? null) ? $row['status'] : 'public',
                'visible'     => is_bool($visibleRaw) ? $visibleRaw : (is_numeric($visibleRaw) ? (int) $visibleRaw !== 0 : true),
            ];
        }
        return $out;
    }

    /**
     * Insert a batch of new category rows atomically. The 'rank' column on
     * each row is renamed to backticked '`rank`' inside the method since
     * MySQL 8.0 reserves the bare identifier.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertCategoryRowsBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                if (array_key_exists('rank', $row)) {
                    $row['`rank`'] = $row['rank'];
                    unset($row['rank']);
                }
                $this->conn->insert($this->table('categories'), $row);
            }
        });
    }

    /**
     * Return id → uppercats map for the given category ids, regardless of
     * visibility. Used by the admin activity feed to show category paths.
     *
     * @param  list<int> $catIds
     * @return array<int|string, mixed>
     */
    public function findUppercatsMapByIds(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'uppercats')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $catIds, ArrayParameterType::INTEGER);
        return array_column($qb->executeQuery()->fetchAllAssociative(), 'uppercats', 'id');
    }

    /**
     * Return (id, uppercats) for categories in $catIds that are also visible
     * to $userId (i.e. present in user_cache_categories).
     *
     * @param  list<int> $catIds
     * @return list<array<string, mixed>>
     */
    public function findIdUppercatsForVisibleIds(int $userId, array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('c.id', 'c.uppercats')
            ->from($this->table('categories'), 'c')
            ->innerJoin('c', $this->table('user_cache_categories'), 'ucc', 'c.id = ucc.cat_id AND ucc.user_id = :userId')
            ->setParameter('userId', $userId);
        $qb->where($qb->expr()->in('c.id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return c.* rows for categories in $catIds that are visible to $userId,
     * keyed numerically.
     *
     * @param  list<int> $catIds
     * @return list<array<string, mixed>>
     */
    public function findAllColumnsForVisibleIds(int $userId, array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('c.*')
            ->from($this->table('categories'), 'c')
            ->innerJoin('c', $this->table('user_cache_categories'), 'ucc', 'c.id = ucc.cat_id AND ucc.user_id = :userId')
            ->setParameter('userId', $userId);
        $qb->where($qb->expr()->in('c.id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Filter the given category ids down to those visible to $userId
     * (i.e. present in user_cache_categories). Used by SearchService qsearch.
     *
     * @param list<int> $catIds
     * @return list<int>
     */
    public function filterVisibleCategoryIdsForUser(int $userId, array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->innerJoin($this->table('categories'), $this->table('user_cache_categories'), 'ucc', 'id = ucc.cat_id AND ucc.user_id = :userId')
            ->setParameter('userId', $userId);
        $qb->where($qb->expr()->in('id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
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
     * Return image_ids linked to the given category ids, GROUPed BY image_id
     * (deduped). Used by SearchService qsearch.
     *
     * @param  list<int> $catIds
     * @return list<int>
     */
    public function findDistinctImageIdsGroupedByCategoryIds(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('image_category'))
            ->groupBy('image_id');
        $qb->where($qb->expr()->in('category_id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($qb->executeQuery()->fetchAllAssociative(), 'image_id'));
    }

    /** Return every distinct image_id present in image_category. */
    /** @return list<int> */
    public function findAllDistinctImageIds(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT DISTINCT image_id FROM ' . $this->table('image_category'),
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /** Return image ids that have no image_category association (uncategorized). */
    /** @return list<int> */
    public function findUncategorizedImageIds(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('images')
            . ' LEFT JOIN ' . $this->table('image_category') . ' ON id = image_id'
            . ' WHERE image_id IS NULL',
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
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
     * @return list<RankUpdateRow>
     */
    public function getAllForRankUpdate(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'id_uppercat', 'uppercats', '`rank`', 'global_rank')
            ->from($this->table('categories'))
            ->orderBy('id_uppercat')
            ->addOrderBy('`rank`')
            ->addOrderBy('name')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(RankUpdateRow::fromRow(...), $rows);
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
     * @param  array<int|string> $ids
     * @return list<CategoryDetail>
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
        return array_map(CategoryDetail::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Return the status (`public` | `private`) for each given category id,
     * keyed by id. Missing ids are omitted (no DB row → no key in the result).
     *
     * @param  array<int|string> $ids
     * @return array<int, \Piwigo\Common\Enum\Privacy>
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
            $idRaw = $row['id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $statusRaw           = $row['status'] ?? null;
            $result[(int) $idRaw] = is_string($statusRaw)
                ? \Piwigo\Common\Enum\Privacy::from($statusRaw)
                : \Piwigo\Common\Enum\Privacy::Public;
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

    /**
     * Find subcategory ids whose `uppercats` column starts with the given
     * comma-separated prefix (i.e. all descendants of the matching ancestor),
     * subject to the caller's permission filter.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return list<int>
     */
    public function findSubcategoryIdsByUppercatsPrefix(
        string $catUppercats,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $query = 'SELECT id FROM ' . $this->table('categories')
            . ' WHERE uppercats LIKE ? ' . $permWhere;
        $params = [$catUppercats . ',%', ...$permParams];
        $types  = [ParameterType::STRING, ...$permTypes];
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /**
     * Find ids of categories whose `representative_picture_id` points at an
     * image that no longer exists. Pass null to scan all categories, an int
     * for a single category, or a list of ints to limit the scan.
     *
     * @param int|list<int>|null $scope
     * @return list<int>
     */
    public function findIdsWithDeadRepresentative(int|array|null $scope = null): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT c.id')
            ->from($this->table('categories'), 'c')
            ->leftJoin('c', $this->table('images'), 'i', 'c.representative_picture_id = i.id')
            ->where('c.representative_picture_id IS NOT NULL')
            ->andWhere('i.id IS NULL');
        if (is_int($scope)) {
            $qb->andWhere('c.id = :scopeId')->setParameter('scopeId', $scope);
        } elseif (is_array($scope) && $scope !== []) {
            $qb->andWhere($qb->expr()->in('c.id', ':scopeIds'))
               ->setParameter('scopeIds', $scope, ArrayParameterType::INTEGER);
        }
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Find ids of categories that have linked images but no
     * representative_picture_id set. Used by updateCategory() when
     * allow_random_representative is off, to surface candidates for
     * random-rep assignment. Scope follows {@see findIdsWithDeadRepresentative}.
     *
     * @param int|list<int>|null $scope
     * @return list<int>
     */
    public function findIdsMissingRepresentativeAmong(int|array|null $scope = null): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT c.id')
            ->from($this->table('categories'), 'c')
            ->innerJoin('c', $this->table('image_category'), 'ic', 'c.id = ic.category_id')
            ->where('c.representative_picture_id IS NULL');
        if (is_int($scope)) {
            $qb->andWhere('ic.category_id = :scopeId')->setParameter('scopeId', $scope);
        } elseif (is_array($scope) && $scope !== []) {
            $qb->andWhere($qb->expr()->in('ic.category_id', ':scopeIds'))
               ->setParameter('scopeIds', $scope, ArrayParameterType::INTEGER);
        }
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Prune rows in tables that point at a non-existent category id (FK
     * orphans). With v17's FK CASCADE/SET NULL infrastructure most of these
     * cannot actually occur, but `old_permalinks.cat_id` has no FK so the
     * sweep remains useful there; the rest are harmless no-ops kept as a
     * defensive safety net during the schema-upgrade transition.
     */
    public function pruneOrphanRelations(): void
    {
        $relations = [
            $this->table('image_category')       => 'category_id',
            $this->table('user_access')          => 'cat_id',
            $this->table('group_access')         => 'cat_id',
            $this->table('old_permalinks')       => 'cat_id',
            $this->table('user_cache_categories') => 'cat_id',
        ];
        $catTable = $this->table('categories');
        foreach ($relations as $table => $column) {
            $orphans = $this->conn->executeQuery(
                'SELECT DISTINCT ' . $column . ' FROM ' . $table . ' LEFT JOIN ' . $catTable . ' ON id = ' . $column . ' WHERE id IS NULL'
            )->fetchFirstColumn();
            $orphanIds = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $orphans);
            if ($orphanIds === []) {
                continue;
            }
            $this->conn->executeStatement(
                'DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (?)',
                [$orphanIds],
                [ArrayParameterType::INTEGER],
            );
        }
    }

    /**
     * Persist new `rank` values for the given category ids atomically.
     *
     * @param list<array{id: int|string, rank: int}> $rows
     */
    public function setRanks(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                // `rank` is a MySQL 8.0 reserved word — backtick the set-array key.
                $this->conn->update($this->table('categories'), ['`rank`' => $row['rank']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Persist new `rank` and `global_rank` values atomically.
     *
     * @param list<array{id: int|string, rank: int, global_rank: string}> $rows
     */
    public function setRanksAndGlobalRanks(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                // `rank` is a MySQL 8.0 reserved word — backtick the set-array key.
                $this->conn->update($this->table('categories'), ['`rank`' => $row['rank'], 'global_rank' => $row['global_rank']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Update representative_picture_id for many categories atomically.
     *
     * @param list<array{id: int|string, representative_picture_id: int|null}> $rows
     */
    public function setRepresentatives(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $this->conn->update($this->table('categories'), ['representative_picture_id' => $row['representative_picture_id']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Return id → dir for every physical category (dir IS NOT NULL).
     *
     * @return array<int, string>
     */
    public function findAllIdToDirMap(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'dir')
            ->from($this->table('categories'))
            ->where('dir IS NOT NULL')
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $out[$idInt] = is_string($row['dir']) ? $row['dir'] : '';
        }
        return $out;
    }

    /**
     * Return (id, uppercats, site_id) for physical categories in the given list.
     *
     * @param  list<int> $ids
     * @return list<CategoryUppercatsSite>
     */
    public function findUppercatsAndSiteByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'uppercats', 'site_id')
            ->from($this->table('categories'))
            ->where('dir IS NOT NULL');
        $qb->andWhere($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return array_map(CategoryUppercatsSite::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Return every category's (id, id_uppercat, uppercats) projection, keyed
     * by id. Used by the uppercats-rebuild walk.
     *
     * @return array<int, CategoryParentInfo>
     */
    public function findAllIdUppercatRowsKeyedById(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'id_uppercat', 'uppercats')
            ->from($this->table('categories'))
            ->executeQuery()
            ->fetchAllAssociative();
        $keyed = [];
        foreach ($rows as $row) {
            $entity                  = CategoryParentInfo::fromRow($row);
            $keyed[$entity->id->value] = $entity;
        }
        return $keyed;
    }

    /**
     * Update `uppercats` for many categories atomically.
     *
     * @param list<array{id: int|string, uppercats: string}> $rows
     */
    public function setUppercatsBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $this->conn->update($this->table('categories'), ['uppercats' => $row['uppercats']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Insert a virtual category with the given column values then fix up its
     * `uppercats` column to include the new id. Returns the inserted id.
     *
     * @param array<string, mixed> $insert
     */
    public function insertVirtualAndFixUppercats(array $insert, string $uppercatsPrefix): int
    {
        $this->conn->insert($this->table('categories'), $insert);
        $insertedId = (int) $this->conn->lastInsertId();
        $this->conn->update($this->table('categories'), ['uppercats' => $uppercatsPrefix . $insertedId], ['id' => $insertedId]);
        return $insertedId;
    }

    /**
     * For each category in $catIds, return its current max(`rank`) in the
     * image_category join, or omit it from the map if no ranked images exist.
     *
     * @param list<int> $catIds
     * @return array<int, int>
     */
    public function findMaxImageRankPerCategoryIn(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('category_id', 'MAX(`rank`) AS max_rank')
            ->from($this->table('image_category'))
            ->where('`rank` IS NOT NULL');
        $qb->andWhere($qb->expr()->in('category_id', ':catIds'))
           ->setParameter('catIds', $catIds, ArrayParameterType::INTEGER)
           ->groupBy('category_id');
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $catId = is_numeric($row['category_id']) ? (int) $row['category_id'] : 0;
            $out[$catId] = is_numeric($row['max_rank']) ? (int) $row['max_rank'] : 0;
        }
        return $out;
    }

    /**
     * Insert image_category links atomically. Each row {image_id, category_id, rank}.
     * Caller is responsible for ensuring no duplicate (image_id, category_id) pairs.
     *
     * @param list<array{image_id: int, category_id: int, rank: int}> $rows
     */
    public function insertImageCategoryLinks(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                // `rank` is a MySQL 8.0 reserved word — backtick the set-array key.
                $this->conn->insert($this->table('image_category'), [
                    'image_id'    => $row['image_id'],
                    'category_id' => $row['category_id'],
                    '`rank`'      => $row['rank'],
                ]);
            }
        });
    }

    /**
     * Among $imageIds, return those that can be safely dissociated from
     * $categoryId: image must exist AND its row in image_category for that
     * category must not be the canonical "storage" link.
     *
     * @param list<int> $imageIds
     * @return list<int>
     */
    public function findDissociableImageIdsForCategory(int $categoryId, array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('image_category'))
            ->innerJoin($this->table('image_category'), $this->table('images'), 'i', 'image_id = i.id')
            ->where('category_id = :catId')
            ->setParameter('catId', $categoryId)
            ->andWhere('(category_id != storage_category_id OR storage_category_id IS NULL)');
        $qb->andWhere($qb->expr()->in('id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Delete image_category links for $imageIds, but only the non-storage
     * (virtual) ones. If $keepCategoryIds is non-empty, skip links that point
     * at any of those categories (used by moveImagesToCategories so the
     * targets keep their existing virtual associations).
     *
     * @param list<int> $imageIds
     * @param list<int> $keepCategoryIds
     */
    public function deleteVirtualImageCategoryLinksExcept(array $imageIds, array $keepCategoryIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $sql = 'DELETE ic.* FROM ' . $this->table('image_category') . ' ic'
            . ' JOIN ' . $this->table('images') . ' i ON ic.image_id = i.id'
            . ' WHERE i.id IN (?)'
            . ' AND (i.storage_category_id IS NULL OR i.storage_category_id != ic.category_id)';
        $params = [$imageIds];
        $types  = [ArrayParameterType::INTEGER];
        if ($keepCategoryIds !== []) {
            $sql      = str_replace(
                'WHERE i.id IN (?)',
                'WHERE i.id IN (?) AND ic.category_id NOT IN (?)',
                $sql,
            );
            $params[] = $keepCategoryIds;
            $types[]  = ArrayParameterType::INTEGER;
        }
        $this->conn->executeStatement($sql, $params, $types);
    }

    /**
     * Return image ids linked to any category in the given list.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function findImageIdsLinkedToCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('image_category'));
        $qb->where($qb->expr()->in('category_id', ':catIds'))
           ->setParameter('catIds', $categoryIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return categories-menu rows: category meta joined with the user's
     * user_cache_categories denormalized counts, subject to the caller's
     * permission filter (or a custom WHERE built by the caller).
     *
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return list<MenuCategoryRow>
     */
    public function findCategoriesMenuRows(int $userId, string $whereClause, array $permParams, array $permTypes): array
    {
        $query = '
SELECT
  id, name, permalink, nb_images, global_rank,
  date_last, max_date_last, count_images, count_categories
FROM ' . $this->table('categories') . ' INNER JOIN ' . $this->table('user_cache_categories') . '
  ON id = cat_id AND user_id = ?
WHERE ' . $whereClause;
        $params = [$userId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return array_map(MenuCategoryRow::fromRow(...), $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative());
    }

    /**
     * Return id-keyed (id, name, permalink) rows for the given category ids.
     *
     * @param  list<int> $ids
     * @return array<int, CategoryNamePermalink>
     */
    public function findNamePermalinkByIdsKeyedById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $entity            = CategoryNamePermalink::fromRow($row);
            $out[$entity->id->value] = $entity;
        }
        return $out;
    }

    /**
     * Execute the caller-built category-listing query and return its rows.
     * Transitional shim feeding CategoryService::displaySelectCatWrapper —
     * every in-tree caller composes a `SELECT id, name, uppercats, global_rank`
     * statement, so we coerce that shape here at the boundary.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<array{id: int, name: string, uppercats: string, global_rank: string|null}>
     */
    public function executeListingQuery(string $query, array $params, array $types): array
    {
        $out = [];
        foreach ($this->conn->executeQuery($query, $params, $types)->fetchAllAssociative() as $row) {
            $idRaw = $row['id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[] = [
                'id'          => (int) $idRaw,
                'name'        => is_string($row['name'] ?? null) ? $row['name'] : '',
                'uppercats'   => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            ];
        }
        return $out;
    }

    /**
     * Return DISTINCT category ids whose `uppercats` REGEXP-matches any of the
     * given category ids (i.e. the union of subcategory subtrees rooted at
     * each id).
     *
     * @param list<int> $rootIds
     * @return list<int>
     */
    public function findSubcatIdsByRootIds(array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }
        $clauses = [];
        $params  = [];
        $types   = [];
        foreach ($rootIds as $rootId) {
            $clauses[] = 'uppercats REGEXP ?';
            $params[]  = '(^|,)' . $rootId . '(,|$)';
            $types[]   = ParameterType::STRING;
        }
        $query = 'SELECT DISTINCT(id) FROM ' . $this->table('categories') . ' WHERE ' . implode(' OR ', $clauses);
        $rows  = $this->conn->executeQuery($query, $params, $types)->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Resolve permalinks to category ids by checking both old_permalinks
     * (historical mappings) and categories.permalink (current values).
     * Each result row carries an `is_old` flag (1 if from old_permalinks).
     *
     * @param  list<string> $permalinks
     * @return array<string, array{id: int, permalink: string, is_old: bool}>  Keyed by `permalink`
     */
    public function findCategoryIdsByPermalinksKeyedByPermalink(array $permalinks): array
    {
        if ($permalinks === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($permalinks), '?'));
        $query = '
SELECT cat_id AS id, permalink, 1 AS is_old
  FROM ' . $this->table('old_permalinks') . '
  WHERE permalink IN (' . $placeholders . ')
UNION
SELECT id, permalink, 0 AS is_old
  FROM ' . $this->table('categories') . '
  WHERE permalink IN (' . $placeholders . ')';
        $params = [...$permalinks, ...$permalinks];
        $types  = array_fill(0, count($params), ParameterType::STRING);
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            $pk = is_string($row['permalink']) ? $row['permalink'] : '';
            if ($pk === '') {
                continue;
            }
            $out[$pk] = [
                'id'        => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'permalink' => $pk,
                'is_old'    => is_numeric($row['is_old']) ? (int) $row['is_old'] !== 0 : false,
            ];
        }
        return $out;
    }

    /**
     * Return a random image_id in the given category (or in its subtree if
     * $recursive is true), subject to the caller's permission filter.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     */
    public function findRandomImageIdInCategoryWithPermissions(
        int $categoryId,
        string $uppercats,
        bool $recursive,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): ?int {
        if ($recursive) {
            $catClause = '(c.id = ? OR uppercats LIKE ?)';
            $catParams = [$categoryId, $uppercats . ',%'];
            $catTypes  = [ParameterType::INTEGER, ParameterType::STRING];
        } else {
            $catClause = 'c.id = ?';
            $catParams = [$categoryId];
            $catTypes  = [ParameterType::INTEGER];
        }
        $query = '
SELECT image_id
  FROM ' . $this->table('categories') . ' AS c
    INNER JOIN ' . $this->table('image_category') . ' AS ic ON ic.category_id = c.id
  WHERE ' . $catClause . ' ' . $permWhere . '
  ORDER BY RAND()
  LIMIT 1';
        $params = [...$catParams, ...$permParams];
        $types  = [...$catTypes, ...$permTypes];
        $value  = $this->conn->executeQuery($query, $params, $types)->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Computed-category aggregation row used by getComputedCategories():
     * cat_id, id_uppercat, global_rank, max(date_available) → date_last,
     * count(date_available) → nb_images. Filters image rows by visibility
     * level and optional date_available cutoff; optionally excludes forbidden
     * category ids.
     *
     * @param  list<int> $forbiddenCategoryIds
     * @return list<array{cat_id: int, id_uppercat: int|null, global_rank: string|null, date_last: string|null, nb_images: int}>
     */
    public function findComputedCategoryAggregates(
        int $userLevel,
        ?string $recentDateCutoffSql,
        array $forbiddenCategoryIds,
    ): array {
        $query  = 'SELECT c.id AS cat_id, id_uppercat';
        $query .= ', global_rank';
        $query .= ',
  MAX(date_available) AS date_last, COUNT(date_available) AS nb_images
FROM ' . $this->table('categories') . ' as c
  LEFT JOIN ' . $this->table('image_category') . ' AS ic ON ic.category_id = c.id
  LEFT JOIN ' . $this->table('images') . ' AS i
    ON ic.image_id = i.id
      AND i.level <= ?';
        $params = [$userLevel];
        $types  = [ParameterType::INTEGER];

        if ($recentDateCutoffSql !== null) {
            // recentDateCutoffSql comes from SqlExpr::recentPeriodExpr() and is
            // a server-built fragment (no user data), spliced as-is.
            $query .= ' AND i.date_available > ' . $recentDateCutoffSql;
        }

        if ($forbiddenCategoryIds !== []) {
            $query .= '
  WHERE c.id NOT IN (?)';
            $params[] = $forbiddenCategoryIds;
            $types[]  = ArrayParameterType::INTEGER;
        }

        $query .= '
  GROUP BY c.id';

        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            $catIdRaw = $row['cat_id'] ?? null;
            if (!is_numeric($catIdRaw)) {
                continue;
            }
            $out[] = [
                'cat_id'      => (int) $catIdRaw,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
                'date_last'   => is_string($row['date_last'] ?? null) ? $row['date_last'] : null,
                'nb_images'   => is_numeric($row['nb_images'] ?? null) ? (int) $row['nb_images'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Return image ids associated with any of the given categories, subject
     * to the caller's permission filter, an optional extra WHERE fragment,
     * and an ORDER BY. With $mode === 'AND' and >1 category id, only images
     * present in ALL given categories are returned.
     *
     * @param list<int>                              $catIds
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return list<int>
     */
    public function findImageIdsForCategoriesWithPermissions(
        array $catIds,
        string $mode,
        ?string $extraImagesWhereSql,
        string $orderBySuffix,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        if ($catIds === []) {
            return [];
        }
        $query = '
SELECT id
  FROM ' . $this->table('images') . ' i
    INNER JOIN ' . $this->table('image_category') . ' ic ON id = ic.image_id
  WHERE category_id IN (?)' . $permWhere;
        $params = [$catIds, ...$permParams];
        $types  = [ArrayParameterType::INTEGER, ...$permTypes];

        if ($extraImagesWhereSql !== null && $extraImagesWhereSql !== '') {
            $query .= " \nAND (" . $extraImagesWhereSql . ')';
        }
        $query .= '
  GROUP BY id';

        if ($mode === 'AND' && count($catIds) > 1) {
            $query .= '
  HAVING COUNT(DISTINCT category_id) = ' . count($catIds);
        }
        $query .= "\n" . $orderBySuffix;

        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /**
     * Categories that appear in image_category for the given images,
     * with their count of matching images, subject to permission filter.
     * Optional cap on result rows and category-id exclusion.
     *
     * @param  list<int>                              $imageIds
     * @param  list<int>                              $excludedCatIds
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return list<array{id: int, uppercats: string, counter: int}>
     */
    public function findCommonCategoriesWithPermissions(
        array $imageIds,
        ?int $max,
        array $excludedCatIds,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        if ($imageIds === []) {
            return [];
        }
        $query = '
SELECT
    c.id,
    c.uppercats,
    count(*) AS counter
  FROM ' . $this->table('image_category') . '
    INNER JOIN ' . $this->table('categories') . ' c ON category_id = id
  WHERE image_id IN (?)' . $permWhere;
        $params = [$imageIds, ...$permParams];
        $types  = [ArrayParameterType::INTEGER, ...$permTypes];
        if ($excludedCatIds !== []) {
            $query .= '
    AND category_id NOT IN (?)';
            $params[] = $excludedCatIds;
            $types[]  = ArrayParameterType::INTEGER;
        }
        $query .= '
  GROUP BY c.id
  ORDER BY ';
        if ($max !== null) {
            $query .= 'counter DESC
  LIMIT ' . $max;
        } else {
            $query .= 'NULL';
        }
        $rows = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            $idRaw = $row['id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[] = [
                'id'        => (int) $idRaw,
                'uppercats' => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'counter'   => is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Return (id, name, permalink, id_uppercat, uppercats, global_rank) for
     * the given category ids. Used by getRelatedCategoriesMenu to render the
     * "related albums" navigation strip.
     *
     * @param  list<int> $ids
     * @return list<array{id: int, name: string, permalink: string|null, id_uppercat: int|null, uppercats: string, global_rank: string|null}>
     */
    public function findRelatedNavRowsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink', 'id_uppercat', 'uppercats', 'global_rank')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        $out  = [];
        foreach ($rows as $row) {
            $idRaw = $row['id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[] = [
                'id'          => (int) $idRaw,
                'name'        => is_string($row['name'] ?? null) ? $row['name'] : '',
                'permalink'   => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
                'id_uppercat' => is_numeric($row['id_uppercat'] ?? null) ? (int) $row['id_uppercat'] : null,
                'uppercats'   => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'global_rank' => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
            ];
        }
        return $out;
    }

    /**
     * Check whether the given image_id is in any category visible to the
     * caller. Used by rate / view permission gates.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     */
    public function isImageInVisibleCategory(
        int $imageId,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): bool {
        $query  = 'SELECT DISTINCT id FROM ' . $this->table('images')
            . ' INNER JOIN ' . $this->table('image_category') . ' ON id = image_id'
            . ' WHERE id = ? ' . $permWhere . ' LIMIT 1';
        $params = [$imageId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return $this->conn->executeQuery($query, $params, $types)->fetchOne() !== false;
    }

    /**
     * Check whether the given image_id is in any commentable category visible
     * to the caller. Used by addComment validation.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     */
    public function isImageInVisibleCommentableCategory(
        int $imageId,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): bool {
        $query  = 'SELECT DISTINCT image_id FROM ' . $this->table('image_category')
            . ' INNER JOIN ' . $this->table('categories') . ' ON category_id = id'
            . ' WHERE commentable = 1 AND image_id = ? ' . $permWhere;
        $params = [$imageId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return $this->conn->executeQuery($query, $params, $types)->fetchOne() !== false;
    }

    /**
     * Return (id, uppercats, commentable, visible, status, global_rank) for
     * categories linked to the given image, subject to permission filter.
     * Used by the picture page to compute navigation/commentable state.
     *
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return list<PictureNavCategoryRow>
     */
    public function findPictureNavCategoriesForImage(
        int $imageId,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $query  = 'SELECT id, uppercats, commentable, visible, status, global_rank'
            . ' FROM ' . $this->table('image_category') . ' INNER JOIN ' . $this->table('categories')
            . ' ON category_id = id WHERE image_id = ? ' . $permWhere;
        $params = [$imageId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return array_map(PictureNavCategoryRow::fromRow(...), $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative());
    }

    /**
     * Return related category info for the given image — id, name, permalink,
     * uppercats, global_rank, commentable. Subject to permission filter.
     *
     * @param list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return list<RelatedCategoryRow>
     */
    public function findRelatedCategoriesForImage(
        int $imageId,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $query  = 'SELECT id, name, permalink, uppercats, global_rank, commentable'
            . ' FROM ' . $this->table('image_category') . ' INNER JOIN ' . $this->table('categories')
            . ' ON category_id = id WHERE image_id = ? ' . $permWhere;
        $params = [$imageId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return array_map(RelatedCategoryRow::fromRow(...), $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative());
    }

    /**
     * Return distinct virtual-association category ids for the given images
     * (links where category_id is NOT the image's storage category). Used by
     * the batch-manager filter pane to show "associated to" options that are
     * actually dissociable.
     *
     * @param  list<int> $imageIds
     * @return array<int|string, int>
     */
    public function findDistinctVirtualAssociatedCategoryIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT(ic.category_id) AS id')
            ->from($this->table('image_category'), 'ic')
            ->innerJoin('ic', $this->table('images'), 'i', 'i.id = ic.image_id')
            ->where('ic.category_id != i.storage_category_id OR i.storage_category_id IS NULL');
        $qb->andWhere($qb->expr()->in('ic.image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchFirstColumn() as $id) {
            $idInt = is_numeric($id) ? (int) $id : 0;
            $out[$idInt] = $idInt;
        }
        return $out;
    }

    /**
     * Return ids of private categories.
     *
     * @return list<int>
     */
    public function findPrivateIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->where("status = 'private'")
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return ids of locked categories (visible = 0).
     *
     * @return list<int>
     */
    public function findLockedIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->where('visible = 0')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return ids of virtual categories (dir IS NULL — categories without
     * a filesystem directory).
     *
     * @return list<int>
     */
    public function findVirtualCategoryIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->where('dir IS NULL')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return category ids that the given image is linked to via image_category.
     *
     * @return list<int>
     */
    public function findCategoryIdsByImageId(int $imageId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('category_id')
            ->from($this->table('image_category'))
            ->where('image_id = :imageId')
            ->setParameter('imageId', $imageId)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return (id, name, permalink) rows for every category keyed by id —
     * the catalog used by admin URLs that need every category's metadata
     * at once.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function findAllIdNamePermalinkMap(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink')
            ->from($this->table('categories'))
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['id']) ? (string) $row['id'] : '';
            $out[$key] = $row;
        }
        return $out;
    }

    /**
     * Return ids of categories whose `id_uppercat` matches the given parent
     * (null = root-level categories).
     *
     * @return list<int>
     */
    public function findIdsByParent(?int $parentId): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'));
        if ($parentId === null) {
            $qb->where('id_uppercat IS NULL');
        } else {
            $qb->where('id_uppercat = :parent')->setParameter('parent', $parentId);
        }
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return (id, name, rank, status, visible, uppercats, lastmodified) for
     * every category — used by the admin album-tree overview.
     *
     * @return list<array{id: int, name: string, rank: int|null, status: string, visible: bool, uppercats: string, lastmodified: string}>
     */
    public function findAllForAdminTreeOverview(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'name', '`rank`', 'status', 'visible', 'uppercats', 'lastmodified')
            ->from($this->table('categories'))
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $idRaw      = $row['id'] ?? null;
            $rankRaw    = $row['rank'] ?? null;
            $visibleRaw = $row['visible'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[] = [
                'id'           => (int) $idRaw,
                'name'         => is_string($row['name'] ?? null) ? $row['name'] : '',
                'rank'         => is_numeric($rankRaw) ? (int) $rankRaw : null,
                'status'       => is_string($row['status'] ?? null) ? $row['status'] : 'public',
                'visible'      => is_bool($visibleRaw) ? $visibleRaw : (is_numeric($visibleRaw) ? (int) $visibleRaw !== 0 : true),
                'uppercats'    => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'lastmodified' => is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
            ];
        }
        return $out;
    }

    /**
     * For every category, return its number of associated images (zero-count
     * categories are omitted). Keyed by category_id.
     *
     * @return array<int|string, int>
     */
    public function findNbPhotosPerCategoryKeyedById(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('category_id', 'COUNT(*) AS nb_photos')
            ->from($this->table('image_category'))
            ->groupBy('category_id')
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['category_id']) ? (string) $row['category_id'] : '';
            $out[$key] = is_numeric($row['nb_photos']) ? (int) $row['nb_photos'] : 0;
        }
        return $out;
    }

    /**
     * Return id → uppercats map for every category.
     *
     * @return array<int|string, string>
     */
    public function findAllIdToUppercatsMap(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'uppercats')
            ->from($this->table('categories'))
            ->executeQuery()
            ->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['id']) ? (string) $row['id'] : '';
            $out[$key] = is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '';
        }
        return $out;
    }

    /**
     * Return id → uppercats map for the given category ids.
     *
     * @param  list<int> $ids
     * @return array<int|string, string>
     */
    public function findIdToUppercatsMapByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'uppercats')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key       = is_scalar($row['id']) ? (string) $row['id'] : '';
            $out[$key] = is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '';
        }
        return $out;
    }

    /**
     * For each given category id, return MIN(field) or MAX(field) of its
     * associated images' date column. $minmax must be 'min' or 'max',
     * $field must be a literal `date_creation` or `date_available` from a
     * trusted source (validated by caller — typed enum eventually).
     *
     * @param  list<int>  $catIds
     * @return array<int|string, string>
     */
    public function findRefDatesForCategoriesKeyedById(string $minmax, string $field, array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $minmaxUp = strtoupper($minmax) === 'MIN' ? 'MIN' : 'MAX';
        $fieldSafe = in_array($field, ['date_creation', 'date_available'], true) ? $field : 'date_available';
        $query = 'SELECT category_id, ' . $minmaxUp . '(' . $fieldSafe . ') AS ref_date FROM ' . $this->table('image_category')
            . ' JOIN ' . $this->table('images') . ' ON image_id = id'
            . ' WHERE category_id IN (?) GROUP BY category_id';
        $rows = $this->conn->executeQuery($query, [$catIds], [ArrayParameterType::INTEGER])->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $key       = is_scalar($row['category_id']) ? (string) $row['category_id'] : '';
            $out[$key] = is_scalar($row['ref_date']) ? (string) $row['ref_date'] : '';
        }
        return $out;
    }

    /** Count direct sub-categories of the given parent (id_uppercat = $parentId). */
    public function countByParent(int $parentId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('categories'))
            ->where('id_uppercat = :parent')
            ->setParameter('parent', $parentId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return (id, name, permalink, dir, rank, status) rows for the listing
     * pane, optionally restricted to direct children of $parentId (null = root).
     *
     * @return list<array{id: int, name: string, permalink: string|null, dir: string|null, rank: int|null, status: string}>
     */
    public function findCategoryListing(?int $parentId): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'name', 'permalink', 'dir', '`rank`', 'status')
            ->from($this->table('categories'))
            ->orderBy('`rank`', 'ASC');
        if ($parentId === null) {
            $qb->where('id_uppercat IS NULL');
        } else {
            $qb->where('id_uppercat = :parent')->setParameter('parent', $parentId);
        }
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $idRaw   = $row['id'] ?? null;
            $rankRaw = $row['rank'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[] = [
                'id'        => (int) $idRaw,
                'name'      => is_string($row['name'] ?? null) ? $row['name'] : '',
                'permalink' => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
                'dir'       => is_string($row['dir'] ?? null) ? $row['dir'] : null,
                'rank'      => is_numeric($rankRaw) ? (int) $rankRaw : null,
                'status'    => is_string($row['status'] ?? null) ? $row['status'] : 'public',
            ];
        }
        return $out;
    }

    /**
     * Run the categories-for-thumbnails query (the SQL_CALC_FOUND_ROWS pattern
     * used by both the category-grid and recent-cats pages) with the caller's
     * pre-composed WHERE fragment and permission filter, plus the LIMIT/OFFSET
     * page window. Returns rows and the total row count for pagination.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findCatsForThumbnailsWithFoundRows(
        int $userId,
        string $whereExtraSql,
        string $orderBySuffix,
        int $limit,
        int $offset,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        $query = '
SELECT SQL_CALC_FOUND_ROWS
    c.*,
    user_representative_picture_id,
    nb_images,
    date_last,
    max_date_last,
    count_images,
    nb_categories,
    count_categories
  FROM ' . $this->table('categories') . ' c
    INNER JOIN ' . $this->table('user_cache_categories') . ' ucc
    ON id = cat_id
    AND user_id = ?
  WHERE count_images > 0
    AND ' . $whereExtraSql . '
    ' . $permWhere . '
  ' . $orderBySuffix . '
  LIMIT ' . $limit . ' OFFSET ' . $offset;
        $params = [$userId, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        $rows   = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        $totalRaw = $this->conn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
        return [
            'rows'  => $rows,
            'total' => is_numeric($totalRaw) ? (int) $totalRaw : 0,
        ];
    }

    /**
     * Find a random representative_picture_id from the descendant subtree of
     * the category whose `uppercats` matches the given prefix, restricted to
     * categories with a non-null representative and the caller's permission
     * filter. Returns null if no candidate.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     */
    public function findRandomSubcatRepresentativeForUser(
        int $userId,
        string $uppercatsPrefix,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): ?int {
        $query = '
SELECT representative_picture_id
  FROM ' . $this->table('categories') . ' INNER JOIN ' . $this->table('user_cache_categories') . '
  ON id = cat_id AND user_id = ?
  WHERE uppercats LIKE ?
    AND representative_picture_id IS NOT NULL
    ' . $permWhere . '
  ORDER BY RAND()
  LIMIT 1';
        $params = [$userId, $uppercatsPrefix . ',%', ...$permParams];
        $types  = [ParameterType::INTEGER, ParameterType::STRING, ...$permTypes];
        $value  = $this->conn->executeQuery($query, $params, $types)->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * For each category id, return its MIN(date_creation) and MAX(date_creation)
     * for the images it contains, subject to permissions. Result keyed by
     * category_id.
     *
     * @param  list<int>                              $catIds
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return array<int, array{from: string|null, to: string|null}>
     */
    public function findDateRangesForCategoriesKeyedById(
        array $catIds,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        if ($catIds === []) {
            return [];
        }
        $query = '
SELECT
    category_id,
    MIN(date_creation) AS `from`,
    MAX(date_creation) AS `to`
  FROM ' . $this->table('image_category') . '
    INNER JOIN ' . $this->table('images') . ' ON image_id = id
  WHERE category_id IN (?)
    ' . $permWhere . '
  GROUP BY category_id';
        $params = [$catIds, ...$permParams];
        $types  = [ArrayParameterType::INTEGER, ...$permTypes];
        $out    = [];
        foreach ($this->conn->executeQuery($query, $params, $types)->fetchAllAssociative() as $row) {
            $idRaw = $row['category_id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[(int) $idRaw] = [
                'from' => is_string($row['from'] ?? null) ? $row['from'] : null,
                'to'   => is_string($row['to'] ?? null) ? $row['to'] : null,
            ];
        }
        return $out;
    }

    /**
     * Set user_representative_picture_id on user_cache_categories rows
     * atomically for the given user and (cat_id, image_id) pairs.
     *
     * @param list<array{cat_id: int|string, image_id: int|null}> $rows
     */
    public function setUserRepresentativeBatch(int $userId, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($userId, $rows): void {
            foreach ($rows as $row) {
                $catIdInt = is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0;
                $this->conn->update(
                    $this->table('user_cache_categories'),
                    ['user_representative_picture_id' => $row['image_id']],
                    ['user_id' => $userId, 'cat_id' => $catIdInt],
                );
            }
        });
    }

    /**
     * Among the given category ids, return those that exist in the
     * categories table. Used to validate user-supplied id lists.
     *
     * @param int[] $ids
     * @return list<int>
     */
    public function findExistingIdsAmong(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * For each parent id, return the number of categories whose id_uppercat
     * matches it. Keyed by id_uppercat.
     *
     * @param list<int> $parentIds
     * @return array<int|string, int>
     */
    public function countSubcatsByParentIdsKeyedByParent(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id_uppercat', 'COUNT(*) AS nb_subcats')
            ->from($this->table('categories'))
            ->groupBy('id_uppercat');
        $qb->where($qb->expr()->in('id_uppercat', ':ids'))
           ->setParameter('ids', $parentIds, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key       = is_scalar($row['id_uppercat']) ? (string) $row['id_uppercat'] : '';
            $out[$key] = is_numeric($row['nb_subcats']) ? (int) $row['nb_subcats'] : 0;
        }
        return $out;
    }

    /**
     * Return (id, id_uppercat, rank) rows for the given category ids.
     *
     * @param int[] $ids
     * @return list<CategoryRankInfo>
     */
    public function findIdIdUppercatRankByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'id_uppercat', '`rank`')
            ->from($this->table('categories'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return array_map(CategoryRankInfo::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Return ids of categories whose id_uppercat matches $parent
     * (null = root), ordered by id ASC.
     *
     * @return list<int>
     */
    public function findIdsByParentOrderedById(?int $parent): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->orderBy('id', 'ASC');
        if ($parent === null) {
            $qb->where('id_uppercat IS NULL');
        } else {
            $qb->where('id_uppercat = :parent')->setParameter('parent', $parent);
        }
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return ids of categories whose id_uppercat matches $parent (null = root)
     * excluding $excludeId, ordered by `rank` ASC.
     *
     * @return list<int>
     */
    public function findOtherIdsByParentOrderedByRank(?int $parent, int $excludeId): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('categories'))
            ->orderBy('`rank`', 'ASC')
            ->andWhere('id != :excludeId')
            ->setParameter('excludeId', $excludeId);
        if ($parent === null) {
            $qb->andWhere('id_uppercat IS NULL');
        } else {
            $qb->andWhere('id_uppercat = :parent')->setParameter('parent', $parent);
        }
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Among $imageIds, return those that are also linked to at least one
     * category OUTSIDE $excludeCategoryIds. Used by calculateOrphans to find
     * "becoming orphan" images when a subtree is deleted.
     *
     * @param  list<int> $excludeCategoryIds
     * @param  list<int> $imageIds
     * @return list<int>
     */
    public function findImageIdsAssociatedOutsideCategories(array $excludeCategoryIds, array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('DISTINCT image_id')
            ->from($this->table('image_category'));
        if ($excludeCategoryIds !== []) {
            $qb->where($qb->expr()->notIn('category_id', ':excludeCats'))
               ->setParameter('excludeCats', $excludeCategoryIds, ArrayParameterType::INTEGER);
        }
        $qb->andWhere($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return image_ids that appear in image_category rows where category_id
     * is NOT in the given set. Used by the >1000-row calculateOrphans path
     * to find all "outside" image ids without splicing the recursive image
     * list into the SQL.
     *
     * @param  list<int> $excludeCategoryIds
     * @return list<int>
     */
    public function findImageIdsAssociatedOutsideCategoriesAll(array $excludeCategoryIds): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('image_category'));
        if ($excludeCategoryIds !== []) {
            $qb->where($qb->expr()->notIn('category_id', ':excludeCats'))
               ->setParameter('excludeCats', $excludeCategoryIds, ArrayParameterType::INTEGER);
        }
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Update arbitrary columns on a single category by id.
     *
     * @param array<string, mixed> $fields
     */
    public function updateById(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->conn->update($this->table('categories'), $fields, ['id' => $id]);
    }

    /**
     * Return (id, image_order) for the categories matching the WHERE
     * fragment composed by getImages — REGEXP'd uppercats OR id-equality —
     * plus the caller's permission filter. Result keyed by id.
     *
     * @param  int[]                                  $catIds  Ids the WHERE was built from (defines the REGEXP/id-eq alternation)
     * @param  list<mixed>                            $permParams
     * @param  list<ArrayParameterType|ParameterType> $permTypes
     * @return array<int, array{id: int, image_order: string|null}>
     */
    public function findIdAndImageOrderForGetImages(
        array $catIds,
        bool $recursive,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        if ($catIds === []) {
            return [];
        }
        $clauses = [];
        $params  = [];
        $types   = [];
        foreach ($catIds as $cid) {
            if ($recursive) {
                $clauses[] = 'uppercats REGEXP ?';
                $params[]  = '(^|,)' . $cid . '(,|$)';
                $types[]   = ParameterType::STRING;
            } else {
                $clauses[] = 'id = ?';
                $params[]  = $cid;
                $types[]   = ParameterType::INTEGER;
            }
        }
        $where  = '(' . implode("\n    OR ", $clauses) . ') ' . $permWhere;
        $params = [...$params, ...$permParams];
        $types  = [...$types, ...$permTypes];
        $sql    = 'SELECT id, image_order FROM ' . $this->table('categories') . ' WHERE ' . $where;
        $rows   = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $out    = [];
        foreach ($rows as $row) {
            $idRaw = $row['id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $out[(int) $idRaw] = [
                'id'          => (int) $idRaw,
                'image_order' => is_string($row['image_order'] ?? null) ? $row['image_order'] : null,
            ];
        }
        return $out;
    }

    /**
     * Return image_id → list<int> category_id map for the given images,
     * filtered by the caller's permission WHERE.
     *
     * @param list<int>                              $imageIds
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findImageCategoryPairsWithPermissions(
        array $imageIds,
        string $permWhere,
        array $permParams,
        array $permTypes,
    ): array {
        if ($imageIds === []) {
            return [];
        }
        $sql = 'SELECT image_id, category_id FROM ' . $this->table('image_category')
            . ' WHERE image_id IN (?) ' . $permWhere;
        $params = [$imageIds, ...$permParams];
        $types  = [ArrayParameterType::INTEGER, ...$permTypes];
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        return array_map(static fn (array $r): array => [
            'image_id'    => is_numeric($r['image_id']) ? (int) $r['image_id'] : 0,
            'category_id' => is_numeric($r['category_id']) ? (int) $r['category_id'] : 0,
        ], $rows);
    }

    /**
     * Run the categories-getList query (configurable INNER/LEFT join with
     * user_cache_categories, configurable WHERE composed by the caller),
     * returning the rows and the FOUND_ROWS() total when LIMIT was applied.
     * The SELECT shape is fixed (no caller-controlled column list).
     *
     * @param list<string>                           $whereClauses
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return array{rows: list<array{
     *     id: int,
     *     name: string,
     *     comment: string|null,
     *     permalink: string|null,
     *     status: string,
     *     uppercats: string,
     *     global_rank: string|null,
     *     id_uppercat: int|null,
     *     nb_images: int,
     *     total_nb_images: int,
     *     representative_picture_id: int|null,
     *     user_representative_picture_id: int|null,
     *     count_images: int,
     *     count_categories: int,
     *     date_last: string|null,
     *     max_date_last: string|null,
     *     nb_categories: int,
     *     image_order: string|null,
     * }>, total: int|null}
     */
    public function findGetListPage(
        string $joinType,
        int $joinUserId,
        array $whereClauses,
        string $orderLimit,
        bool $useSqlCalcFoundRows,
        array $params,
        array $types,
    ): array {
        $select = ($useSqlCalcFoundRows ? 'SQL_CALC_FOUND_ROWS ' : '')
            . 'id, name, comment, permalink, status, uppercats, global_rank, id_uppercat, nb_images, count_images AS total_nb_images, representative_picture_id, user_representative_picture_id, count_images, count_categories, date_last, max_date_last, count_categories AS nb_categories, image_order';
        $sql = 'SELECT ' . $select
            . ' FROM ' . $this->table('categories')
            . ' ' . $joinType . ' JOIN ' . $this->table('user_cache_categories')
            . ' ON id = cat_id AND user_id = ' . $joinUserId
            . ' WHERE ' . implode("\n    AND ", $whereClauses)
            . ' ' . $orderLimit;
        $rawRows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $rows = [];
        foreach ($rawRows as $row) {
            $idRaw = $row['id'] ?? null;
            if (!is_numeric($idRaw)) {
                continue;
            }
            $idUppercatRaw  = $row['id_uppercat'] ?? null;
            $repPicIdRaw    = $row['representative_picture_id'] ?? null;
            $userRepPicRaw  = $row['user_representative_picture_id'] ?? null;
            $rows[] = [
                'id'                              => (int) $idRaw,
                'name'                            => is_string($row['name'] ?? null) ? $row['name'] : '',
                'comment'                         => is_string($row['comment'] ?? null) ? $row['comment'] : null,
                'permalink'                       => is_string($row['permalink'] ?? null) ? $row['permalink'] : null,
                'status'                          => is_string($row['status'] ?? null) ? $row['status'] : 'public',
                'uppercats'                       => is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
                'global_rank'                     => is_string($row['global_rank'] ?? null) ? $row['global_rank'] : null,
                'id_uppercat'                     => is_numeric($idUppercatRaw) ? (int) $idUppercatRaw : null,
                'nb_images'                       => is_numeric($row['nb_images'] ?? null) ? (int) $row['nb_images'] : 0,
                'total_nb_images'                 => is_numeric($row['total_nb_images'] ?? null) ? (int) $row['total_nb_images'] : 0,
                'representative_picture_id'       => is_numeric($repPicIdRaw) ? (int) $repPicIdRaw : null,
                'user_representative_picture_id'  => is_numeric($userRepPicRaw) ? (int) $userRepPicRaw : null,
                'count_images'                    => is_numeric($row['count_images'] ?? null) ? (int) $row['count_images'] : 0,
                'count_categories'                => is_numeric($row['count_categories'] ?? null) ? (int) $row['count_categories'] : 0,
                'date_last'                       => is_string($row['date_last'] ?? null) ? $row['date_last'] : null,
                'max_date_last'                   => is_string($row['max_date_last'] ?? null) ? $row['max_date_last'] : null,
                'nb_categories'                   => is_numeric($row['nb_categories'] ?? null) ? (int) $row['nb_categories'] : 0,
                'image_order'                     => is_string($row['image_order'] ?? null) ? $row['image_order'] : null,
            ];
        }
        $total = null;
        if ($useSqlCalcFoundRows) {
            $totalRaw = $this->conn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
            $total = is_numeric($totalRaw) ? (int) $totalRaw : 0;
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Run the admin-list query (no join, just the core category columns,
     * configurable WHERE composed by the caller, SQL_CALC_FOUND_ROWS).
     *
     * @param list<string>                           $whereClauses
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findAdminListPage(
        array $whereClauses,
        string $tailFragment,
        array $params,
        array $types,
    ): array {
        $sql = 'SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order'
            . ' FROM ' . $this->table('categories')
            . ' WHERE ' . implode("\n    AND ", $whereClauses)
            . ' ' . $tailFragment;
        $rows     = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        $totalRaw = $this->conn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
        return ['rows' => $rows, 'total' => is_numeric($totalRaw) ? (int) $totalRaw : 0];
    }

    /**
     * Update image_category `rank` for many image/category pairs atomically.
     *
     * @param list<array{image_id: int|string, category_id: int, rank: int}> $rows
     */
    public function setImageRanksInCategory(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $imageIdInt = is_numeric($row['image_id']) ? (int) $row['image_id'] : 0;
                // `rank` is a MySQL 8.0 reserved word — backtick the set-array key.
                $this->conn->update(
                    $this->table('image_category'),
                    ['`rank`' => $row['rank']],
                    ['image_id' => $imageIdInt, 'category_id' => $row['category_id']],
                );
            }
        });
    }
}
