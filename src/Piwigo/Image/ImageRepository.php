<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Image\Entity\Image;
use Piwigo\Image\Entity\ImageIdFilename;
use Piwigo\Image\Entity\ImageIdPathRepresentative;
use Piwigo\Image\Entity\PathRepresentative;

/** Persistence layer for the image domain. */
final class ImageRepository extends AbstractRepository
{
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
     * Return (id, file) projection for every image. Used by
     * `pwg.images.searchFormat` to build a unique-filenames map and by
     * the regenerate-all-derivatives job to enumerate every image.
     *
     * @return list<ImageIdFilename>
     */
    public function findAllIdFilename(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id', 'file')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(ImageIdFilename::fromRow(...), $rows);
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
    /**
     * Return (path, representative_ext) for the given image ids.
     * Used by batch_manager_global to delete specific derivatives.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    /**
     * @param  int[] $ids
     * @return list<PathRepresentative>
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
        return array_map(PathRepresentative::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
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
     * @return list<Image>
     */
    public function findByCategoryIdOrdered(int $categoryId): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT i.*
             FROM ' . $this->table('images') . ' i
             JOIN ' . $this->table('image_category') . ' ic ON ic.image_id = i.id
             WHERE ic.category_id = ?
             ORDER BY ic.`rank`',
            [$categoryId]
        )->fetchAllAssociative();
        return array_map(Image::fromRow(...), $rows);
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
     * Return image entities for the given ids.
     *
     * @param  int[] $ids
     * @return list<Image>
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
        return array_map(Image::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
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
     * Return (id, path, representative_ext) rows for the given ids.
     * Used by delete_element_files to find physical files before deletion.
     *
     * @param int[] $ids
     * @return list<array<string, mixed>>
     */
    /**
     * @param  int[] $ids
     * @return list<ImageIdPathRepresentative>
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
        return array_map(ImageIdPathRepresentative::fromRow(...), $qb->executeQuery()->fetchAllAssociative());
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
     * Return true when at least one image with non-null author exists under
     * the supplied permission filter. Used by the search form to decide
     * whether to expose an "author" filter input.
     *
     * @param list<mixed>                                  $permParams
     * @param list<\Doctrine\DBAL\ArrayParameterType|\Doctrine\DBAL\ParameterType> $permTypes
     */
    public function existsAuthorUnderPermissions(string $permWhere, array $permParams, array $permTypes): bool
    {
        $sql = 'SELECT id'
            . ' FROM ' . $this->table('images') . ' AS i'
            . ' JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' ' . $permWhere
            . ' AND author IS NOT NULL'
            . ' LIMIT 1';
        $rows = $this->conn->executeQuery($sql, $permParams, $permTypes)->fetchAllAssociative();
        return $rows !== [];
    }

    /**
     * Return (id, path, representative_ext) for images stored in the given
     * category ids, optionally restricted to rows that have never been
     * metadata-synced. Result keyed by id, used by the metadata sync filelist.
     */
    /**
     * @param  list<int> $categoryIds
     * @return array<int, ImageIdPathRepresentative>
     */
    public function findFilelistByStorageCategoryIds(array $categoryIds, bool $onlyNew): array
    {
        if ($categoryIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'path', 'representative_ext')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('storage_category_id', ':ids'))
           ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER);
        if ($onlyNew) {
            $qb->andWhere('date_metadata_update IS NULL');
        }
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $proj           = ImageIdPathRepresentative::fromRow($row);
            $out[$proj->id->value] = $proj;
        }
        return $out;
    }

    /**
     * Return distinct image ids for "recent photos" — images whose
     * date_available is within $recentPeriod days, optionally restricted to
     * a category id allow-list. Used by the recent-photos filter middleware.
     *
     * @param  list<int> $visibleCategories  empty = no category filter
     * @return list<int>
     */
    public function findRecentImageIdsByCategories(array $visibleCategories, int $recentPeriod): array
    {
        $params = [];
        $types  = [];
        $catClause = '';
        if ($visibleCategories !== []) {
            $catClause = ' category_id IN (?) AND';
            $params[]  = $visibleCategories;
            $types[]   = ArrayParameterType::INTEGER;
        }
        $sql = 'SELECT DISTINCT image_id'
            . ' FROM ' . $this->table('image_category')
            . ' INNER JOIN ' . $this->table('images') . ' ON image_id = id'
            . ' WHERE' . $catClause
            . ' date_available >= ' . \Piwigo\Db\SqlExpr::recentPeriodExpr((string) $recentPeriod);
        $rows = $this->conn->executeQuery($sql, $params, $types)->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'image_id'));
    }

    /**
     * Return summary image rows used by the activity-feed endpoint: id,
     * label (= COALESCE(name, file)), filesize, file, path, representative_ext.
     * Result keyed by id.
     *
     * @param  list<int> $ids
     * @return array<int|string, array<string, mixed>>
     */
    /**
     * @param  int[] $ids
     * @return array<int, array{id: int, label: string, filesize: ?int, file: string, path: string, representative_ext: ?string}>
     */
    public function findActivityFeedSummaryByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'IF(name IS NULL, file, name) AS label', 'filesize', 'file', 'path', 'representative_ext')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $rowId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $out[$rowId] = [
                'id'                 => $rowId,
                'label'              => is_string($row['label'] ?? null) ? $row['label'] : '',
                'filesize'           => is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
                'file'               => is_string($row['file'] ?? null) ? $row['file'] : '',
                'path'               => is_string($row['path'] ?? null) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            ];
        }
        return $out;
    }

    /**
     * True when at least one image_category row for $imageId lives in a
     * category that passes the supplied permission filter — i.e. the image
     * is visible to the current user.
     *
     * @param list<mixed>                                  $permParams
     * @param list<ArrayParameterType|ParameterType>       $permTypes
     */
    public function existsImageInVisibleCategory(int $imageId, string $permWhere, array $permParams, array $permTypes): bool
    {
        $sql = 'SELECT id FROM ' . $this->table('categories')
            . ' INNER JOIN ' . $this->table('image_category') . ' ON category_id = id'
            . ' WHERE image_id = ?' . $permWhere
            . ' LIMIT 1';
        $value = $this->conn->executeQuery(
            $sql,
            [$imageId, ...$permParams],
            [ParameterType::INTEGER, ...$permTypes],
        )->fetchOne();
        return $value !== false;
    }

    /**
     * Return a window of "older than $startId" image rows used by the
     * missing-derivatives generator. Pulls (id, path, representative_ext,
     * width, height, rotation) ordered by id DESC, with caller-supplied
     * extra WHERE clauses joined in.
     *
     * @param  list<string> $extraWhereClauses
     * @return list<array<string, mixed>>
     */
    /**
     * @param  list<string> $extraWhereClauses
     * @return list<array{id: int, path: string, representative_ext: ?string, width: ?int, height: ?int, rotation: ?int}>
     */
    public function findDerivativeCandidatesBeforeId(int $startId, array $extraWhereClauses, int $limit): array
    {
        $clauses = $extraWhereClauses;
        $clauses[] = 'id < ?';
        $sql = 'SELECT id, path, representative_ext, width, height, rotation'
            . ' FROM ' . $this->table('images')
            . ' WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY id DESC LIMIT ' . $limit;
        $rows = $this->conn->executeQuery(
            $sql,
            [$startId],
            [\Doctrine\DBAL\ParameterType::INTEGER],
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'                 => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
                'path'               => is_string($row['path'] ?? null) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                'width'              => is_numeric($row['width'] ?? null) ? (int) $row['width'] : null,
                'height'             => is_numeric($row['height'] ?? null) ? (int) $row['height'] : null,
                'rotation'           => is_numeric($row['rotation'] ?? null) ? (int) $row['rotation'] : null,
            ];
        }
        return $out;
    }

    /**
     * Return (ext_counter, filesize) totals across all images, keyed by ext.
     * Used by the admin maintenance page's storage breakdown.
     *
     * @return array<string, array{ext_counter: int, filesize: int}>
     */
    public function findFileExtensionTotals(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT COUNT(*) AS ext_counter, SUBSTRING_INDEX(path, ".", -1) AS ext, SUM(filesize) AS filesize'
            . ' FROM ' . $this->table('images') . ' GROUP BY ext',
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $ext = is_string($row['ext'] ?? null) ? $row['ext'] : '';
            $out[$ext] = [
                'ext_counter' => is_numeric($row['ext_counter'] ?? null) ? (int) $row['ext_counter'] : 0,
                'filesize'    => is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : 0,
            ];
        }
        return $out;
    }

    /**
     * Return the image ids with the top $limit rating_score (DESC). Used by
     * the admin "consensus deviation" tool.
     *
     * @return list<int>
     */
    public function findTopRatedIds(int $limit): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('images') . ' ORDER BY rating_score DESC LIMIT ' . $limit,
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /** Return MAX(id)+1 (the next inferred id) over the images table; 1 when empty. */
    public function findNextAvailableId(): int
    {
        $value = $this->conn->executeQuery(
            'SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1) FROM ' . $this->table('images'),
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 1;
    }

    /**
     * Return id → path map for images stored in the given category ids.
     * Used by site_update to find existing images in the synced category tree.
     *
     * @param  list<int> $catIds
     * @return array<int, string>
     */
    public function findIdPathByStorageCategoryIds(array $catIds): array
    {
        if ($catIds === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'path')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('storage_category_id', ':ids'))
           ->setParameter('ids', $catIds, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            if (is_numeric($row['id'] ?? null) && is_string($row['path'] ?? null)) {
                $out[(int) $row['id']] = $row['path'];
            }
        }
        return $out;
    }

    /**
     * Insert a batch of (image, image_category link) tuples atomically.
     *
     * @param list<array<string, mixed>> $imageRows
     * @param list<array<string, mixed>> $linkRows
     */
    public function insertImageRowsBatch(array $imageRows, array $linkRows): void
    {
        if ($imageRows === [] && $linkRows === []) {
            return;
        }
        $this->conn->transactional(function () use ($imageRows, $linkRows): void {
            foreach ($imageRows as $row) {
                $this->conn->insert($this->table('images'), $row);
            }
            foreach ($linkRows as $row) {
                $this->conn->insert($this->table('image_category'), $row);
            }
        });
    }

    /** Count images whose storage_category_id is NOT NULL (filesystem-synced). */
    public function countWithStorageCategorySet(): int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from($this->table('images'))
            ->where('storage_category_id IS NOT NULL')
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Group images by add_method (sync vs api, derived from
     * storage_category_id IS NULL) and return aggregated per-method stats.
     * Result keyed by add_method.
     *
     * @return array<string, array{nb_files: int, last_added_on: string}>
     */
    public function findFilesAddedByMethod(): array
    {
        $rows = $this->conn->executeQuery(
            "SELECT IF(storage_category_id IS NULL, 'api', 'sync') AS add_method,"
            . ' MAX(date_available) AS last_added_on, COUNT(*) AS nb_files'
            . ' FROM ' . $this->table('images')
            . ' GROUP BY add_method',
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $method = is_string($row['add_method'] ?? null) ? $row['add_method'] : '';
            $out[$method] = [
                'nb_files'      => is_numeric($row['nb_files'] ?? null) ? (int) $row['nb_files'] : 0,
                'last_added_on' => is_scalar($row['last_added_on'] ?? null) ? (string) $row['last_added_on'] : '',
            ];
        }
        return $out;
    }

    /** Return the date_available of the earliest-inserted image, or null. */
    public function findEarliestDateAvailable(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('date_available')
            ->from($this->table('images'))
            ->orderBy('id', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return is_scalar($value) ? (string) $value : null;
    }

    /** Return the date_available of the most recently inserted image, or null. */
    public function findLatestDateAvailable(): ?string
    {
        $value = $this->conn->createQueryBuilder()
            ->select('date_available')
            ->from($this->table('images'))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Group all images by file extension (last token after the final dot in
     * path) and return per-extension counter + total filesize.
     *
     * @return array<string, array{counter: int, filesize: int}>
     */
    public function findFileExtensionUsage(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT SUBSTRING_INDEX(path, ".", -1) AS ext,'
            . ' COUNT(*) AS counter, SUM(filesize) AS filesize'
            . ' FROM ' . $this->table('images')
            . ' GROUP BY ext',
        )->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $ext        = is_string($row['ext'] ?? null) ? $row['ext'] : '';
            $out[$ext]  = [
                'counter'  => is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
                'filesize' => is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : 0,
            ];
        }
        return $out;
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

    /** Return the image entity for the given id, or null if not found. */
    public function findById(int $id): ?Image
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('images'))
            ->where('id = :id')
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? Image::fromRow($row) : null;
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

    /**
     * Return image ids matching `file LIKE $pattern` (no ESCAPE). Used by
     * the history admin filter form, where the user types a free-form pattern
     * with `%` / `_` semantics.
     *
     * @return list<int>
     */
    public function findIdsByFileLike(string $pattern): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('images') . ' WHERE file LIKE ?',
            [$pattern],
            [\Doctrine\DBAL\ParameterType::STRING],
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Find a single image where file LIKE $pattern ESCAPE '/'.
     * Caller must pre-escape '_' and '%' in the base name, then append '.%'.
     */
    public function findByFilePattern(string $pattern): ?Image
    {
        $row = $this->conn->executeQuery(
            "SELECT * FROM {$this->table('images')} WHERE file LIKE ? ESCAPE '/' LIMIT 1",
            [$pattern]
        )->fetchAssociative();
        return $row !== false ? Image::fromRow($row) : null;
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
     * Return image ids whose category_id is NOT in $forbiddenCatIds AND
     * whose level > $maxLevel. Used by the user-cache rebuild to compute
     * the per-user forbidden-images list.
     *
     * @param  list<int> $forbiddenCatIds
     * @return list<int>
     */
    public function findForbiddenImageIdsForUser(array $forbiddenCatIds, int $maxLevel): array
    {
        if ($forbiddenCatIds === []) {
            $forbiddenCatIds = [0];
        }
        $rows = $this->conn->executeQuery(
            'SELECT DISTINCT(id) FROM ' . $this->table('images')
            . ' INNER JOIN ' . $this->table('image_category')
            . ' ON id = image_id WHERE category_id NOT IN (?) AND level > ?',
            [$forbiddenCatIds, $maxLevel],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER],
        )->fetchAllAssociative();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, array_column($rows, 'id'));
    }

    /**
     * Count distinct image_ids whose category_id NOT IN $forbiddenCatIds
     * and whose image_id NOT IN $forbiddenImageIds. Used for nb_total_images
     * in the user_cache rebuild.
     *
     * @param list<int> $forbiddenCatIds
     * @param list<int> $forbiddenImageIds
     */
    public function countVisibleDistinctImageIds(array $forbiddenCatIds, array $forbiddenImageIds): int
    {
        if ($forbiddenCatIds === []) {
            $forbiddenCatIds = [0];
        }
        if ($forbiddenImageIds === []) {
            $forbiddenImageIds = [0];
        }
        $value = $this->conn->executeQuery(
            'SELECT COUNT(DISTINCT(image_id)) AS total FROM ' . $this->table('image_category')
            . ' WHERE category_id NOT IN (?) AND image_id NOT IN (?)',
            [$forbiddenCatIds, $forbiddenImageIds],
            [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER],
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Among the given image ids, return those that exist in the images table.
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
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Check whether an image with the given id exists subject to the caller's
     * permission filter.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     */
    public function existsByIdWithPermissions(int $id, string $permWhere, array $permParams, array $permTypes): bool
    {
        $query  = 'SELECT id FROM ' . $this->table('images') . ' WHERE id = ? ' . $permWhere . ' LIMIT 1';
        $params = [$id, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        return $this->conn->executeQuery($query, $params, $types)->fetchOne() !== false;
    }

    /**
     * Find a single image by id subject to the caller's permission filter.
     *
     * @param list<mixed>                            $permParams
     * @param list<ArrayParameterType|ParameterType> $permTypes
     */
    public function findByIdWithPermissions(int $id, string $permWhere, array $permParams, array $permTypes): ?Image
    {
        $query  = 'SELECT * FROM ' . $this->table('images') . ' WHERE id = ? ' . $permWhere . ' LIMIT 1';
        $params = [$id, ...$permParams];
        $types  = [ParameterType::INTEGER, ...$permTypes];
        $row = $this->conn->executeQuery($query, $params, $types)->fetchAssociative();
        return $row !== false ? Image::fromRow($row) : null;
    }

    /**
     * Return image ids in the given category ordered by `rank` ASC.
     *
     * @return list<int>
     */
    public function findIdsByCategoryIdOrderedByRank(int $categoryId): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('image_id')
            ->from($this->table('image_category'))
            ->where('category_id = :catId')
            ->setParameter('catId', $categoryId)
            ->orderBy('`rank`', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Count images matching the caller-built WHERE fragment.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     */
    public function countByWhereFragment(string $whereFragment, array $params = [], array $types = []): int
    {
        $value = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . $this->table('images') . ' WHERE ' . $whereFragment,
            $params,
            $types,
        )->fetchOne();
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Find an image id by its (file, category_id) pair.
     */
    public function findIdInCategoryByFile(int $categoryId, string $file): ?int
    {
        $value = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('images') . ' AS i'
            . ' INNER JOIN ' . $this->table('image_category') . ' AS ic ON ic.image_id = i.id'
            . ' WHERE i.file = ? AND ic.category_id = ?',
            [$file, $categoryId],
            [ParameterType::STRING, ParameterType::INTEGER],
        )->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Return md5sum → id map for each existing image among $md5sums.
     *
     * @param  list<string> $md5sums
     * @return array<string, int>
     */
    public function findIdByMd5sumMap(array $md5sums): array
    {
        if ($md5sums === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'md5sum')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('md5sum', ':md5s'))
           ->setParameter('md5s', $md5sums, ArrayParameterType::STRING);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key       = is_string($row['md5sum']) ? $row['md5sum'] : '';
            $out[$key] = is_numeric($row['id']) ? (int) $row['id'] : 0;
        }
        return $out;
    }

    /**
     * Return file → id map for each existing image among $filenames.
     *
     * @param  list<string> $filenames
     * @return array<string, int>
     */
    public function findIdByFilenameMap(array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'file')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('file', ':files'))
           ->setParameter('files', $filenames, ArrayParameterType::STRING);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key       = is_string($row['file']) ? $row['file'] : '';
            $out[$key] = is_numeric($row['id']) ? (int) $row['id'] : 0;
        }
        return $out;
    }

    /**
     * Return the id of the image whose md5sum matches, or null if none.
     * Used by the upload pipeline's duplicate-detection.
     */
    public function findIdByMd5sum(string $md5sum): ?int
    {
        $value = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('images'))
            ->where('md5sum = :md5')
            ->setParameter('md5', $md5sum)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Update arbitrary column-set on a single image.
     *
     * @param array<string, mixed> $fields
     */
    public function updateById(int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $this->conn->update($this->table('images'), $fields, ['id' => $id]);
    }

    /**
     * Insert a new image row and return the inserted id.
     *
     * @param array<string, mixed> $fields
     */
    public function insertNew(array $fields): int
    {
        $this->conn->insert($this->table('images'), $fields);
        return (int) $this->conn->lastInsertId();
    }

    /** Update the `rotation` code (0..7) for a single image. */
    public function updateRotation(int $id, int $rotationCode): void
    {
        $this->conn->createQueryBuilder()
            ->update($this->table('images'))
            ->set('rotation', ':rot')
            ->where('id = :id')
            ->setParameter('rot', $rotationCode)
            ->setParameter('id', $id)
            ->executeStatement();
    }

    /** Find a single image by its path column (exact match), or null if not found. */
    public function findByPath(string $path): ?Image
    {
        $row = $this->conn->createQueryBuilder()
            ->select('*')
            ->from($this->table('images'))
            ->where('path = :path')
            ->setParameter('path', $path)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? Image::fromRow($row) : null;
    }

    /**
     * Return element ids in the given user's caddie.
     *
     * @return list<int>
     */
    /**
     * Return image ids whose date_available falls inside the given SQL date
     * range fragment (built by SqlExpr::recentPeriodExpr — a server-built
     * fragment, not user data).
     *
     * @return list<int>
     */
    public function findIdsByDateAvailableBetween(string $sqlStart, string $endLiteral): array
    {
        $query = 'SELECT id FROM ' . $this->table('images')
            . ' WHERE date_available BETWEEN ' . $sqlStart . ' AND ?';
        $rows  = $this->conn->executeQuery($query, [$endLiteral], [ParameterType::STRING])->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return every image id (no filter).
     *
     * @return list<int>
     */
    public function findAllIds(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('images'))
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return every image id in the given ORDER BY clause.
     *
     * @return list<int>
     */
    public function findAllIdsWithOrderSuffix(string $orderBySuffix): array
    {
        $rows = $this->conn->executeQuery('SELECT id FROM ' . $this->table('images') . ' ' . $orderBySuffix)
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return image ids that have no row in image_tag (no tags assigned).
     *
     * @return list<int>
     */
    public function findUntaggedIds(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('images')
            . ' LEFT JOIN ' . $this->table('image_tag') . ' ON id = image_id'
            . ' WHERE tag_id IS NULL'
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return image ids grouped by the given duplicate-detection fields.
     * For each group with >1 row, all member ids are returned. Optionally
     * filter to rows with non-null md5sum.
     *
     * @param list<string> $duplicateOnFields
     * @return list<int>
     */
    public function findIdsInDuplicateGroups(array $duplicateOnFields, bool $requireMd5sum): array
    {
        if ($duplicateOnFields === []) {
            return [];
        }
        // Field names are caller-controlled but limited to a closed set by the
        // BatchManager UI (file, md5sum, date_creation, width, height).
        $allowed = ['file', 'md5sum', 'date_creation', 'width', 'height'];
        $safeFields = array_values(array_intersect($duplicateOnFields, $allowed));
        if ($safeFields === []) {
            return [];
        }
        $query = 'SELECT GROUP_CONCAT(id) AS ids FROM ' . $this->table('images');
        if ($requireMd5sum) {
            $query .= ' WHERE md5sum IS NOT NULL';
        }
        $query .= ' GROUP BY ' . implode(',', $safeFields) . ' HAVING COUNT(*) > 1';
        $out = [];
        foreach ($this->conn->executeQuery($query)->fetchFirstColumn() as $groupStr) {
            $s = is_string($groupStr) ? rtrim($groupStr, ',') : '';
            if ($s === '') {
                continue;
            }
            foreach (explode(',', $s) as $idStr) {
                $out[] = (int) $idStr;
            }
        }
        return $out;
    }

    /**
     * Return image ids matching `level` and the caller's operator
     * (= or <=) with the given level, ordered by the caller's suffix.
     *
     * @return list<int>
     */
    public function findIdsByLevelComparison(string $operator, int $level, string $orderBySuffix): array
    {
        $op = $operator === '<=' ? '<=' : '=';
        $rows = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('images') . ' WHERE level ' . $op . ' ? ' . $orderBySuffix,
            [$level],
            [ParameterType::INTEGER],
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return image ids matching the caller-built WHERE fragment with the
     * given ORDER BY suffix. The WHERE fragment is composed by the
     * BatchManager from numeric/string inputs validated by InputValidator;
     * the caller pre-binds them into the fragment via parameterized clauses.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<int>
     */
    public function findIdsByWhereFragment(string $whereFragment, string $orderBySuffix, array $params, array $types): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT id FROM ' . $this->table('images') . ' WHERE ' . $whereFragment . ' ' . $orderBySuffix,
            $params,
            $types,
        )->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Update `author` for many images atomically.
     *
     * @param list<array{id: int|string, author: string|null}> $rows
     */
    public function setAuthorBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $this->conn->update($this->table('images'), ['author' => $row['author']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Update `name` for many images atomically.
     *
     * @param list<array{id: int|string, name: string|null}> $rows
     */
    public function setNameBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $this->conn->update($this->table('images'), ['name' => $row['name']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Update `date_creation` for many images atomically.
     *
     * @param list<array{id: int|string, date_creation: string|null}> $rows
     */
    public function setDateCreationBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $this->conn->update($this->table('images'), ['date_creation' => $row['date_creation']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Update `level` for many images atomically.
     *
     * @param list<array{id: int|string, level: int}> $rows
     */
    public function setLevelBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $this->conn->update($this->table('images'), ['level' => $row['level']], ['id' => $idInt]);
            }
        });
    }

    /**
     * Update arbitrary same-shaped column-set on many images atomically.
     *
     * @param list<array{id: int|string, fields: array<string, mixed>}> $rows
     */
    public function updateBatchByIds(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $this->conn->update($this->table('images'), $row['fields'], ['id' => $idInt]);
            }
        });
    }

    /**
     * Run a caller-built images query (typically from BatchManager's free-form
     * filter composition) and return the rows. Caller composes the FROM/JOIN/
     * WHERE/ORDER BY fragments; this method just executes with the bound
     * params.
     *
     * @param list<mixed>                            $params
     * @param list<ArrayParameterType|ParameterType> $types
     * @return list<array<string, mixed>>
     */
    public function findRowsByRawQuery(string $query, array $params, array $types): array
    {
        return $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative();
    }

    /**
     * Delete images by id inside a transaction. FK CASCADE clears child rows
     * in comments, image_category, image_format, image_tag, favorites, rate,
     * caddie, lounge.
     *
     * @param int[] $ids
     */
    public function deleteAtomicallyByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->conn->transactional(function () use ($ids): void {
            $this->deleteByIds($ids);
        });
    }

    /**
     * Return image ids that do not have md5sum populated (NULL).
     *
     * @return list<int>
     */
    public function findIdsWithoutMd5sum(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('images'))
            ->where('md5sum IS NULL')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return id → path map for the given image ids.
     *
     * @param  int[] $ids
     * @return array<int, string>
     */
    public function findIdToPathMapByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = $this->conn->createQueryBuilder()
            ->select('id', 'path')
            ->from($this->table('images'));
        $qb->where($qb->expr()->in('id', ':ids'))
           ->setParameter('ids', $ids, ArrayParameterType::INTEGER);
        $out = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            if (is_numeric($row['id'] ?? null) && is_string($row['path'] ?? null)) {
                $out[(int) $row['id']] = $row['path'];
            }
        }
        return $out;
    }

    /**
     * Update md5sum for many images atomically.
     *
     * @param list<array{id: int|string, md5sum: string|false|null}> $rows
     */
    public function setMd5sumBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->conn->transactional(function () use ($rows): void {
            foreach ($rows as $row) {
                $idInt = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $md5   = ($row['md5sum'] === false) ? null : $row['md5sum'];
                $this->conn->update($this->table('images'), ['md5sum' => $md5], ['id' => $idInt]);
            }
        });
    }

    /**
     * Return ids of images that are not linked to any category (orphans),
     * excluding any image_ids in $excludeIds (typically lounge entries).
     *
     * @param int[] $excludeIds
     * @return list<int>
     */
    public function findOrphanIdsExcluding(array $excludeIds): array
    {
        $qb = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('images'))
            ->leftJoin($this->table('images'), $this->table('image_category'), 'ic', 'id = ic.image_id')
            ->where('ic.category_id IS NULL')
            ->orderBy('id', 'ASC');
        if ($excludeIds !== []) {
            $qb->andWhere($qb->expr()->notIn('id', ':excludeIds'))
               ->setParameter('excludeIds', $excludeIds, ArrayParameterType::INTEGER);
        }
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $qb->executeQuery()->fetchFirstColumn());
    }

    /**
     * Return up to 5000 image ids where date_available is older than the
     * given cutoff date and path starts with './upload/' — sample pool for
     * the fs-quick-check (issue #1827) integrity routine.
     *
     * @return list<int>
     */
    public function findUploadIdsBefore(string $cutoffDate, int $limit): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('images'))
            ->where('date_available < :cutoff')
            ->andWhere("path LIKE './upload/%'")
            ->setParameter('cutoff', $cutoffDate)
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return up to $limit image ids (no filter). Used by fs-quick-check for
     * a random sampling pool.
     *
     * @return list<int>
     */
    public function findIdsCapped(int $limit): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('id')
            ->from($this->table('images'))
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rows);
    }

    /**
     * Return image `path` values that occur more than once (i.e. duplicate
     * filesystem paths from a bad sync state).
     *
     * @return list<string>
     */
    public function findDuplicatePaths(): array
    {
        $rows = $this->conn->createQueryBuilder()
            ->select('path')
            ->from($this->table('images'))
            ->groupBy('path')
            ->having('COUNT(*) > 1')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $rows);
    }

    /**
     * Run the categories-getImages paginated query (the SQL_CALC_FOUND_ROWS
     * pattern used by the REST endpoint), returning the image rows and the
     * total FOUND_ROWS() count.
     *
     * @param list<string>                           $whereClauses
     * @param list<mixed>                            $params
     * @param  list<ArrayParameterType|ParameterType> $types
     * @return array{rows: list<Image>, total: int}
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
        $rows     = array_map(Image::fromRow(...), $this->conn->executeQuery($query, $params, $types)->fetchAllAssociative());
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
}
