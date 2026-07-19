<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Piwigo\Db\AbstractRepository;
use Piwigo\Db\Tables;

/**
 * Persistence layer for the image domain's own data-touching function from
 * `include/functions_picture.inc.php` -- the other 5 ported functions
 * (slideshow param encode/decode, PDF page counting) are pure computation
 * with no DB access of their own and live on {@see ImageService} instead,
 * same Repository=DB-only/Service=business-logic split as every other P19
 * domain.
 */
final class ImageRepository extends AbstractRepository
{
    /**
     * Deliberately avoids bumping `lastmodified` (the original's own SQL
     * comment, preserved) -- an image's "last modified" timestamp should
     * reflect real edits, not visit counting.
     */
    public function incrementVisitCounter(int $imageId): void
    {
        $this->conn->executeStatement(
            'UPDATE ' . Tables::images() . ' SET hit = hit + 1, lastmodified = lastmodified WHERE id = ?',
            [$imageId]
        );
    }

    /**
     * Sets (or clears, when $coi is null) an image's crop-of-interest
     * 4-character code (admin/picture_coi.php, the only caller).
     */
    public function updateCoi(int $imageId, ?string $coi): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::images())
            ->set('coi', ':coi')
            ->where('id = :id')
            ->setParameter('coi', $coi)
            ->setParameter('id', $imageId)
            ->executeStatement();
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findFormatsByImageIds(array $imageIds): array
    {
        return $this->conn->executeQuery('
SELECT
    image_id,
    ext
  FROM ' . Tables::imageFormat() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
;')->fetchAllAssociative();
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findPathsForFileDeletion(array $imageIds): array
    {
        return $this->conn->executeQuery('
SELECT
    id,
    path,
    representative_ext
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $imageIds) . ')
;')->fetchAllAssociative();
    }

    /**
     * Deletes every row referencing $ids across the 7 dependent tables
     * `delete_elements()` originally cleaned one at a time (comments,
     * image_category, image_format, image_tag, favorites keyed on
     * image_id; rate, caddie keyed on element_id) -- grouped by column
     * name here since the delete order between them has no FK dependency
     * (only the `images` row itself, deleted separately by
     * {@see deleteImages()}, must come last).
     *
     * @param array<int, int|string> $ids
     */
    public function deleteElementReferences(array $ids): void
    {
        $idsStr = wordwrap(implode(', ', $ids), 80, "\n");

        foreach ([Tables::comments(), Tables::imageCategory(), Tables::imageFormat(), Tables::imageTag(), Tables::favorites()] as $table) {
            $this->conn->executeStatement('
DELETE FROM ' . $table . '
  WHERE image_id IN (' . $idsStr . ')
;');
        }

        foreach ([Tables::rate(), Tables::caddie()] as $table) {
            $this->conn->executeStatement('
DELETE FROM ' . $table . '
  WHERE element_id IN (' . $idsStr . ')
;');
        }
    }

    /**
     * @param array<int, int|string> $ids
     */
    public function deleteImages(array $ids): void
    {
        $this->conn->executeStatement('
DELETE FROM ' . Tables::images() . '
  WHERE id IN (' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;');
    }

    /**
     * Category ids for which one of $ids is the representative picture.
     *
     * @param array<int, int|string> $ids
     * @return list<int>
     */
    public function findRepresentedCategoryIds(array $ids): array
    {
        $idsStr = wordwrap(implode(', ', $ids), 80, "\n");

        return array_map(intval(...), $this->conn->executeQuery('
SELECT
    id
  FROM ' . Tables::categories() . '
  WHERE representative_picture_id IN (' . $idsStr . ')
;')->fetchFirstColumn());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findLoungeRows(): array
    {
        return $this->conn->executeQuery('
SELECT
    image_id,
    category_id
  FROM ' . Tables::lounge() . '
  ORDER BY category_id ASC, image_id ASC
;')->fetchAllAssociative();
    }

    public function deleteLoungeUpTo(int $maxImageId): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::lounge() . '
  WHERE image_id <= ' . $maxImageId . '
;');
    }

    /**
     * Atomically claims the lounge-emptying lock via `INSERT IGNORE` --
     * a no-op if another process already holds it.
     */
    public function tryAcquireLoungeLock(string $lockValue): void
    {
        $this->conn->executeStatement('
INSERT IGNORE
  INTO ' . Tables::config() . '
  SET param="empty_lounge_running"
    , value="' . $lockValue . '"
;');
    }

    public function findLoungeLockValue(): ?string
    {
        $value = $this->conn->executeQuery(
            'SELECT value FROM ' . Tables::config() . ' WHERE param = "empty_lounge_running"'
        )->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<int|string> $images real callers don't guarantee a list
     * @param array<int|string> $categories
     * @return array<int, int[]> category_id => list of already-associated image ids
     */
    public function findExistingAssociations(array $images, array $categories): array
    {
        $existing = [];
        foreach ($this->conn->executeQuery('
SELECT
    image_id,
    category_id
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $images) . ')
    AND category_id IN (' . implode(',', $categories) . ')
;')->fetchAllAssociative() as $row) {
            $categoryId = $row['category_id'];
            $imageId = $row['image_id'];
            assert(is_numeric($categoryId) && is_numeric($imageId));
            $existing[(int) $categoryId][] = (int) $imageId;
        }

        return $existing;
    }

    /**
     * @param array<int|string> $categories real callers don't guarantee a list
     * @return array<int|string, int> category_id => max rank
     */
    public function findMaxRanksByCategory(array $categories): array
    {
        $rows = $this->conn->executeQuery('
SELECT
    category_id,
    MAX(`rank`) AS max_rank
  FROM ' . Tables::imageCategory() . '
  WHERE `rank` IS NOT NULL
    AND category_id IN (' . implode(',', $categories) . ')
  GROUP BY category_id
;')->fetchAllKeyValue();

        return array_map(static fn (mixed $rank): int => is_numeric($rank) ? (int) $rank : 0, $rows);
    }

    /**
     * The original's own `array_filter(..., is_string(...))` relied on
     * mysqli's legacy fetch mode returning every column as a string; DBAL's
     * `fetchFirstColumn()` returns native ints for this `id` column, so
     * that same filter would silently discard every row instead of being a
     * no-op -- dropped here since the query itself already guarantees
     * numeric ids.
     *
     * @param array<int, int|string> $images
     * @return list<int>
     */
    public function findDissociableImageIds(array $images, int|string $category): array
    {
        return array_map(intval(...), $this->conn->executeQuery('
SELECT id
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id =' . $category . '
    AND id IN (' . implode(',', $images) . ')
    AND (
      category_id != storage_category_id
      OR storage_category_id IS NULL
    )
;')->fetchFirstColumn());
    }

    /**
     * @param array<int, int> $imageIds
     */
    public function deleteImageCategoryLinks(array $imageIds, int|string $category): void
    {
        $this->conn->executeStatement('
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $category . '
    AND image_id IN (' . implode(',', $imageIds) . ')
');
    }

    /**
     * Breaks image_category links for $images to every album but their
     * storage album, optionally excluding $categories (an empty array
     * excludes nothing, matching the original's own conditional `AND
     * category_id NOT IN (...)` clause).
     *
     * @param array<int, int|string> $images
     * @param array<int, int|string> $categories
     */
    public function deleteNonStorageCategoryLinks(array $images, array $categories): void
    {
        $query = '
DELETE ' . Tables::imageCategory() . '.*
  FROM ' . Tables::imageCategory() . '
    JOIN ' . Tables::images() . ' ON image_id=id
  WHERE id IN (' . implode(',', $images) . ')
';

        if ($categories !== []) {
            $query .= '
    AND category_id NOT IN (' . implode(',', $categories) . ')
';
        }

        $query .= '
    AND (storage_category_id IS NULL OR storage_category_id != category_id)
;';

        $this->conn->executeStatement($query);
    }

    /**
     * @return list<int>
     */
    public function findImageIdsWithoutMd5sum(): array
    {
        return array_map(intval(...), $this->conn->executeQuery('
SELECT id
  FROM ' . Tables::images() . '
  WHERE md5sum is null
;')->fetchFirstColumn());
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int|string, string> id => path
     */
    public function findPathsForMd5sum(array $ids): array
    {
        $paths = $this->conn->executeQuery('
SELECT
    id,
    path
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(', ', $ids) . ')
;')->fetchAllKeyValue();

        return array_map(strval(...), $paths);
    }

    public function countAllImages(): int
    {
        $count = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . Tables::images() . ';')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function countImagesInCategories(): int
    {
        $count = $this->conn->executeQuery('SELECT COUNT(DISTINCT(image_id)) FROM ' . Tables::imageCategory() . ';')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<mixed>
     */
    public function findLoungedImageIds(): array
    {
        return $this->conn->executeQuery('SELECT image_id FROM ' . Tables::lounge() . ';')->fetchFirstColumn();
    }

    /**
     * @param list<mixed> $loungedIds
     * @return list<int>
     */
    public function findOrphanImageIds(array $loungedIds): array
    {
        $query = '
SELECT
    id
  FROM ' . Tables::images() . '
    LEFT JOIN ' . Tables::imageCategory() . ' ON id = image_id
  WHERE category_id is null';

        if (count($loungedIds) > 0) {
            $query .= '
    AND id NOT IN (' . implode(',', array_map(strval(...), $loungedIds)) . ')';
        }

        $query .= '
  ORDER BY id ASC
;';

        return array_map(intval(...), $this->conn->executeQuery($query)->fetchFirstColumn());
    }

    /**
     * @param array<int, int|string> $imageIds
     */
    public function touchLastmodified(array $imageIds): void
    {
        $this->conn->executeStatement('
UPDATE ' . Tables::images() . '
  SET lastmodified = NOW()
  WHERE id IN (' . implode(',', $imageIds) . ')
;');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int|string $imageId): ?array
    {
        $rows = $this->conn->executeQuery('
SELECT *
  FROM ' . Tables::images() . '
  WHERE id = ' . $imageId . '
;')->fetchAllAssociative();

        return $rows[0] ?? null;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<string, array<string, mixed>> keyed by image id
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->conn->executeQuery('
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (:ids)
;', [
            'ids' => $ids,
        ], [
            'ids' => ArrayParameterType::STRING,
        ])->fetchAllAssociative();

        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (is_scalar($id)) {
                $byId[(string) $id] = $row;
            }
        }

        return $byId;
    }

    /**
     * @param  list<array{image_id: int|string, category_id: int|string}>  $inserts
     */
    public function massInsertLounge(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $this->batchWriter()
            ->massInsert(Tables::lounge(), array_keys($inserts[0]), $inserts, [
                'ignore' => true,
            ]);
    }

    /**
     * @param  list<array{image_id: int|string, category_id: int|string, rank: int}>  $inserts
     */
    public function massInsertImageCategory(array $inserts): void
    {
        if ($inserts === []) {
            return;
        }

        $this->batchWriter()
            ->massInsert(Tables::imageCategory(), array_keys($inserts[0]), $inserts);
    }

    /**
     * @param  list<array{id: int|string, md5sum: string}>  $updates
     */
    public function massUpdateMd5sums(array $updates): void
    {
        $this->batchWriter()
            ->massUpdate(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => ['md5sum'],
                ],
                $updates
            );
    }

    public function updateDimensions(int $imageId, int $width, int $height): void
    {
        $this->conn->createQueryBuilder()
            ->update(Tables::images())
            ->set('width', ':width')
            ->set('height', ':height')
            ->where('id = :id')
            ->setParameter('width', $width)
            ->setParameter('height', $height)
            ->setParameter('id', $imageId)
            ->executeStatement();
    }

    /**
     * @param  list<array{category_id: int|string, image_id: int|string, rank: int}>  $datas
     */
    public function massUpdateImageCategoryRanks(array $datas): void
    {
        $this->batchWriter()
            ->massUpdate(
                Tables::imageCategory(),
                [
                    'primary' => ['image_id', 'category_id'],
                    'update' => ['rank'],
                ],
                $datas
            );
    }
}
