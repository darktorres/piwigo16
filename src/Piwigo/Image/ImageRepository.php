<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;

/** Persistence layer for the image domain. */
final class ImageRepository extends AbstractRepository
{
    /**
     * Append the given element ids to a user's caddie, skipping any that are
     * already present. Used by Add-to-caddie buttons in the gallery / picture
     * pages and the Batch Manager. Replaces `Util::fillCaddie()` (Phase 5).
     *
     * @param list<int|string> $elementIds
     */
    public function addToUserCaddie(int $userId, array $elementIds): void
    {
        if ($elementIds === []) {
            return;
        }
        $existing = $this->conn->createQueryBuilder()
            ->select('element_id')
            ->from($this->table('caddie'))
            ->where('user_id = :uid')
            ->setParameter('uid', $userId)
            ->executeQuery()
            ->fetchFirstColumn();
        $alreadyIn = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $existing);
        $toInsert  = array_values(array_diff($elementIds, $alreadyIn));
        if ($toInsert === []) {
            return;
        }
        $rows = array_map(
            static fn (int|string $id): array => ['element_id' => $id, 'user_id' => $userId],
            $toInsert
        );
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->conn->insert($this->table('caddie'), $row);
            }
        });
    }

    /**
     * Delete all caddie entries for the given user.
     * The caddie is a temporary selection basket in the admin batch manager.
     */
    public function deleteUserCaddie(int $userId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeStatement();
    }

    /** Return the maximum date_available among all images, or null if none. */
    public function findMaxDateAvailable(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('MAX(date_available)')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return all distinct (width, height) combinations for images with known dimensions.
     *
     * @return list<array{width: int, height: int}>
     */
    public function findDistinctDimensions(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('DISTINCT width', 'height')
            ->from($this->table('images'))
            ->where('width IS NOT NULL')
            ->andWhere('height IS NOT NULL')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(
            fn (array $r): array => [
                'width'  => is_numeric($r['width']) ? (int) $r['width'] : 0,
                'height' => is_numeric($r['height']) ? (int) $r['height'] : 0,
            ],
            $rows
        );
    }

    /**
     * Return all distinct filesize values (in KB) for images with known size.
     *
     * @return list<float|int>
     */
    public function findDistinctFilesizes(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('filesize')
            ->from($this->table('images'))
            ->where('filesize IS NOT NULL')
            ->groupBy('filesize')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0, $rows);
    }

    /**
     * Return (id, file) rows for all images.
     * Used by ws_images_addFormat() to build a unique-filenames map.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllIdFilename(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('id', 'file')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Return (image_id, ext) rows for specific format ids.
     *
     * @param int[] $formatIds
     * @return list<array<string, mixed>>
     */
    public function findFormatsByFormatIds(array $formatIds): array
    {
        if ($formatIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id', 'ext')
            ->from($this->table('image_format'));
        $qb->where($qb->expr()->in('format_id', ':formatIds'))
           ->setParameter('formatIds', $formatIds, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Delete format rows by their format_id values.
     *
     * @param int[] $formatIds
     */
    public function deleteFormatsByFormatIds(array $formatIds): void
    {
        if ($formatIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_format'));
        $qb->where($qb->expr()->in('format_id', ':formatIds'))
           ->setParameter('formatIds', $formatIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Update lastmodified to NOW() for the given image ids.
     *
     * @param int[] $ids
     */
    public function touchLastModified(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('images'))
            ->set('lastmodified', 'NOW()');
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete lounge rows with image_id <= $maxId.
     * Called by empty_lounge() after moving all images to their categories.
     */
    public function deleteLoungeBeforeId(int $maxId): void
    {
        $this->conn->createQueryBuilder()
            ->delete($this->table('lounge'))
            ->where('image_id <= :maxId')
            ->setParameter('maxId', $maxId)
            ->executeStatement();
    }

    /**
     * Return all (image_id, ext) rows from image_format.
     * Used to build a map of alternate format extensions per image.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllFormats(): array
    {
        return $this->conn->createQueryBuilder()
            ->select('image_id', 'ext')
            ->from($this->table('image_format'))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Set the privacy level for the given image ids and return the number of affected rows.
     *
     * @param int[] $ids
     */
    public function setLevelForIds(int $level, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('images'))
            ->set('level', ':level')
            ->setParameter('level', $level);
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return (int) $qb->executeStatement();
    }

    /**
     * Delete caddie rows for the given image ids and user.
     *
     * @param int[] $imageIds
     */
    public function deleteUserCaddieByImageIds(int $userId, array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId);
        $qb->andWhere($qb->expr()->in('element_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return (path, representative_ext) for the given image ids.
     * Used by batch_manager_global to delete specific derivatives.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    public function findPathsAndRepresentativesByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('path', 'representative_ext')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Increment the hit counter for the given image without touching lastmodified.
     * The `lastmodified = lastmodified` trick prevents MySQL auto-update.
     */
    public function incrementHit(int $id): void
    {
        // Raw SQL: `lastmodified = lastmodified` suppresses MySQL's ON UPDATE CURRENT_TIMESTAMP.
        $this->conn->executeStatement(
            'UPDATE ' . $this->table('images') . ' SET hit = hit + 1, lastmodified = lastmodified WHERE id = ?',
            [$id]
        );
    }

    /** Return true if an image with the given id exists. */
    public function existsById(int $id): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('images'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($count) ? (int) $count > 0 : false;
    }

    /**
     * Return the path column for a single image, or null if not found.
     * Used by functions_upload.inc.php to check whether the image file exists.
     */
    public function findPathById(int $id): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('path')
            ->from($this->table('images'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return the category_id and uppercats of the category that holds the most recently uploaded image.
     * Used by photos_add_direct_prepare.inc.php to pre-select the last-used album.
     *
     * @return array{category_id: int, uppercats: string}|null
     */
    public function findLastUploadedCategoryInfo(): ?array
    {
        $row = $this->conn->executeQuery(
            'SELECT ic.category_id, c.uppercats
             FROM ' . $this->table('images') . ' i
             JOIN ' . $this->table('image_category') . ' ic ON ic.image_id = i.id
             JOIN ' . $this->table('categories') . ' c ON c.id = ic.category_id
             ORDER BY i.id DESC
             LIMIT 1'
        )->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return [
            'category_id' => is_numeric($row['category_id']) ? (int) $row['category_id'] : 0,
            'uppercats'   => is_string($row['uppercats']) ? $row['uppercats'] : '',
        ];
    }

    /**
     * Return images for the given category, ordered by rank.
     * Used by element_set_ranks.php for the reorder UI.
     *
     * @return list<array<string, mixed>>
     */
    public function findByCategoryIdOrdered(int $categoryId): array
    {
        return $this->conn->executeQuery(
            'SELECT i.id, i.file, i.path, i.representative_ext,
                    i.width, i.height, i.rotation, i.name, ic.`rank`
             FROM ' . $this->table('images') . ' i
             JOIN ' . $this->table('image_category') . ' ic ON ic.image_id = i.id
             WHERE ic.category_id = ?
             ORDER BY ic.`rank`',
            [$categoryId]
        )->fetchAllAssociative();
    }

    /** Count images currently sitting in the upload lounge. */
    public function countLoungeImages(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('lounge'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return (MAX(id)+1, COUNT(*)) for the images table.
     * Used by ws_getMissingDerivatives() to page through image ids.
     *
     * @return array{0: int, 1: int}
     */
    public function findMaxIdAndCount(): array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('MAX(id) + 1', 'COUNT(*)')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchNumeric();
        $row0 = ($row !== false) ? ($row[0] ?? null) : null;
        $row1 = ($row !== false) ? ($row[1] ?? null) : null;
        return [
            is_numeric($row0) ? (int) $row0 : 0,
            is_numeric($row1) ? (int) $row1 : 0,
        ];
    }

    /**
     * Return the earliest date_available among all images, or null if no images exist.
     */
    public function findEarliestDate(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('MIN(date_available)')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchOne();
        return is_string($value) ? $value : null;
    }

    /**
     * Return image rows for the given ids.
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
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Update the path column for all images stored in a given category.
     * Called by update_path() which rebuilds physical paths after category moves.
     */
    public function updatePathByStorageCategoryId(int $categoryId, string $fulldir): void
    {
        $this->conn->executeStatement(
            "UPDATE {$this->table('images')} SET path = CONCAT(?, '/', file) WHERE storage_category_id = ?",
            [$fulldir, $categoryId]
        );
    }

    /** Total number of images. */
    public function countAll(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return (image_id, ext) rows for formats attached to the given images.
     * Used by delete_element_files to locate physical format files on disk.
     *
     * @param int[] $imageIds
     * @return list<array<string, mixed>>
     */
    public function findFormatsByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('image_id', 'ext')
            ->from($this->table('image_format'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Return (id, path, representative_ext) rows for the given ids.
     * Used by delete_element_files to find physical files before deletion.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    public function findPathsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'path', 'representative_ext')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return $qb->executeQuery()->fetchAllAssociative();
    }


    /**
     * Delete image rows by id.
     *
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /** Total filesize (KB) of all original images. */
    public function sumFilesizeKb(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('SUM(filesize)')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Total number of alternate formats. */
    public function countFormats(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('image_format'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Total filesize (KB) of all alternate format files. */
    public function sumFormatFilesizeKb(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('SUM(filesize)')
            ->from($this->table('image_format'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /** Count photo ratings. */
    public function countRatings(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('rate'))
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Return image ids whose storage_category_id is one of the given values.
     * Used by delete_categories() to find physically-linked images.
     *
     * @param int[] $categoryIds
     * @return int[]
     */
    public function findIdsByStorageCategoryIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('storage_category_id', ':ids'))
           ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER);
        return array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Set rating_score to NULL for the given image ids.
     * Called when all rates for an image have been removed.
     *
     * @param int[] $ids
     */
    public function clearRatingScoreByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('images'))
            ->set('rating_score', 'NULL');
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Return all columns for a single image, or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('images'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Update the crop-on-interest (coi) column for a single image.
     * $coi = null clears the crop box.
     */
    public function updateCoi(int $id, ?string $coi): void
    {
        $qb = $this->conn->createQueryBuilder()
            ->update($this->table('images'))
            ->where('id = :id')
            ->setParameter('id', $id);

        if ($coi === null) {
            $qb->set('coi', 'NULL');
        } else {
            $qb->set('coi', ':coi')->setParameter('coi', $coi);
        }

        $qb->executeStatement();
    }

    /**
     * Return a random image_id from the given category, or null if empty.
     * Uses MySQL RAND() — project is MySQL-only (16.x floor).
     */
    public function findRandomIdByCategoryId(int $categoryId): ?int
    {
        $value = $this->conn->executeQuery(
            'SELECT image_id FROM ' . $this->table('image_category') .
            ' WHERE category_id = ? ORDER BY RAND() LIMIT 1',
            [$categoryId]
        )->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /** Count how many caddie entries the given user has. */
    public function countCaddieByUserId(int $userId): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('caddie'))
            ->where('user_id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Find a single image row where file LIKE $pattern ESCAPE '/'.
     * Caller must pre-escape '_' and '%' in the base name, then append '.%'.
     *
     * @return array<string, mixed>|null
     */
    public function findByFilePattern(string $pattern): ?array
    {
        $row = $this->conn->executeQuery(
            "SELECT * FROM {$this->table('images')} WHERE file LIKE ? ESCAPE '/' LIMIT 1",
            [$pattern]
        )->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /** Update width and height for a single image. */
    public function updateDimensions(int $id, int $width, int $height): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('images'))
            ->set('width', ':width')
            ->set('height', ':height')
            ->where('id = :id')
            ->setParameter('width', $width)
            ->setParameter('height', $height)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /**
     * Find a single image by its path column (exact match), or null if not found.
     *
     * @return array<string, mixed>|null
     */
    public function findByPath(string $path): ?array
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('images'))
            ->where('path = :path')
            ->setParameter('path', $path)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * Run the categories-getImages paginated query (the SQL_CALC_FOUND_ROWS
     * pattern used by the REST endpoint), returning the image rows and the
     * total FOUND_ROWS() count.
     *
     * @param list<string>                           $whereClauses
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findCategoryImagesPaginated(
        array $whereClauses,
        string $orderBySuffix,
        int $perPage,
        int $offset,
        array $params,
        array $types,
    ): array {
        $query = 'SELECT SQL_CALC_FOUND_ROWS i.* FROM ' . $this->table('images') . ' i'
            . ' INNER JOIN ' . $this->table('image_category') . ' ON i.id = image_id'
            . ' WHERE ' . implode("\n    AND ", $whereClauses)
            . ' GROUP BY i.id '
            . $orderBySuffix
            . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
        $rows     = $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
        $totalRaw = $this->conn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
        return ['rows' => $rows, 'total' => is_numeric($totalRaw) ? (int) $totalRaw : 0];
    }

    /**
     * Find image ids subject to a free-form WHERE predicate and the caller's
     * permission filter, with the caller's ORDER BY suffix and optional LIMIT.
     * Used by gallery section branches (recent_pics, most_visited, best_rated,
     * list) where the WHERE predicate varies per branch but the join shape and
     * permission/order are uniform.
     *
     * @param list<mixed>                            $permParams Bound permission params (positional ?)
     * @param list<ArrayParameterType|ParameterType> $permTypes  Permission param types
     * @return list<int>
     */
    public function findSectionImageIdsByPredicate(
        string $wherePredicate,
        string $permWhere,
        array $permParams,
        array $permTypes,
        string $orderBySuffix,
        ?int $limit = null,
    ): array {
        $query = 'SELECT DISTINCT(id) FROM ' . $this->table('images')
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON id = ic.image_id'
            . ' WHERE ' . $wherePredicate . ' ' . $permWhere . ' ' . $orderBySuffix;
        if ($limit !== null) {
            $query .= ' LIMIT ' . $limit;
        }
        $rows = $this->conn->executeQuery($query, $permParams, $permTypes)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /**
     * Find image ids in a set of categories (flat-section variant) — joins
     * image_category → images and applies the caller's permission filter and
     * ORDER BY. The WHERE predicate is the category-id restriction
     * (e.g. 'category_id IN (1,2,3)' or '1=1' for "all categories").
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return list<int>
     */
    public function findImageIdsInCategoriesByWhere(
        string $whereClause,
        string $permWhere,
        array $permParams,
        array $permTypes,
        string $orderBySuffix,
    ): array {
        $query = 'SELECT DISTINCT(image_id) FROM ' . $this->table('image_category')
            . ' INNER JOIN ' . $this->table('images') . ' ON id = image_id'
            . ' WHERE ' . $whereClause . ' ' . $permWhere . ' ' . $orderBySuffix;
        $rows = $this->conn->executeQuery($query, $permParams, $permTypes)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'image_id'));
    }

    /**
     * Return every distinct, non-null `storage_category_id` from the images table.
     *
     * @return list<int>
     */
    public function findDistinctStorageCategoryIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('DISTINCT storage_category_id')
            ->from($this->table('images'))
            ->where('storage_category_id IS NOT NULL')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return the oldest lounge entry joined with its image, including the
     * server's NOW(). Used by lounge age-out cleanup.
     *
     * @return array{image_id: int, date_available: string, dbnow: string}|null
     */
    public function findOldestLoungeEntry(): ?array
    {
        $row = $this->conn->executeQuery(
            'SELECT image_id, date_available, NOW() AS dbnow FROM ' . $this->table('lounge') . ' JOIN ' . $this->table('images') . ' ON image_id = id ORDER BY image_id ASC LIMIT 1'
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
     * Return every (image_id, category_id) row in the lounge, ordered for
     * batch processing by emptyLounge().
     *
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findAllLoungeEntries(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT image_id, category_id FROM ' . $this->table('lounge') . ' ORDER BY category_id ASC, image_id ASC'
        )->fetchAllAssociative();
        return array_map(static fn (array $row): array => [
            'image_id'    => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
            'category_id' => is_numeric($row['category_id']) ? (int) $row['category_id'] : 0,
        ], $rows);
    }

    /**
     * Insert lounge rows atomically with INSERT IGNORE so duplicates of the
     * (image_id, category_id) PK are silently skipped.
     *
     * @param list<array{image_id: int, category_id: int}> $rows
     */
    public function insertLoungeIgnoreDuplicates(array $rows): void
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
     * Find image ids in the given user's favorites, subject to the caller's
     * permission filter and ORDER BY suffix.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     * @return list<int>
     */
    public function findFavoriteImageIdsByUserId(
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
}
