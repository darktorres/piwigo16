<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Piwigo\Db\AbstractRepository;

use Doctrine\DBAL\ArrayParameterType;

/** Persistence layer for the image domain. */
final class ImageRepository extends AbstractRepository
{
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
            fn (array $r): array => ['width' => (int) $r['width'], 'height' => (int) $r['height']],
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
        return (int) $count > 0;
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
        return $row !== false ? $row : null;
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
        return [
            is_numeric($row[0] ?? null) ? (int) $row[0] : 0,
            is_numeric($row[1] ?? null) ? (int) $row[1] : 0,
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
     * Delete alternate format records for the given image ids.
     *
     * @param int[] $imageIds
     */
    public function deleteFormatsByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('image_format'));
        $qb->where($qb->expr()->in('image_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Delete ratings for the given image ids (rate.element_id).
     *
     * @param int[] $imageIds
     */
    public function deleteRatingsByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('rate'));
        $qb->where($qb->expr()->in('element_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
    }

    /**
     * Remove caddie entries for the given image ids (caddie.element_id).
     *
     * @param int[] $imageIds
     */
    public function deleteCaddieByImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }
        $qb = $this->conn->createQueryBuilder()
            ->delete($this->table('caddie'));
        $qb->where($qb->expr()->in('element_id', ':imageIds'))
           ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER);
        $qb->executeStatement();
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
        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
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
}
