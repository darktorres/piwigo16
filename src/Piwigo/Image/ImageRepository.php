<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\Tables;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\ImageFormat;

/**
 * Persistence layer for the image domain's own data-touching function from
 * `include/functions_picture.inc.php` -- the other 5 ported functions
 * (slideshow param encode/decode, PDF page counting) are pure computation
 * with no DB access of their own and live on {@see ImageService} instead,
 * same Repository=DB-only/Service=business-logic split as every other P19
 * domain.
 *
 * Owns `images` ({@see ImageEntity}) and `image_format`
 * ({@see ImageFormatEntity}) -- only the single-row/simple-list methods
 * against those two tables go through DQL; every other method here is a
 * bulk id-list operation (raw string-concatenated `IN (...)`, matching
 * the original) or a cross-domain read/write into a table another
 * repository owns (`categories`, `image_category`, `image_tag`,
 * `comments`, `favorites`, `rate`, `caddie`) -- those stay plain DBAL via
 * $this->getEntityManager()->getConnection(), same "mixed repository"
 * shape Tag/Category's own conversions established, skewed further
 * toward "stays raw" given how few of this repository's real methods are
 * single-entity CRUD. `lounge`/`config` (the empty-lounge lock flag) are
 * read/written raw for the same reason -- `config` specifically can't
 * delegate to Config\ConfigRepository::upsert() the way
 * Admin\MenubarPageRenderer's own config write can (that one is a plain
 * upsert; the now-deleted Menu\MenubarLayoutRepository::saveLayout() this
 * mirrors was the same shape): tryAcquireLoungeLock() needs real atomic
 * INSERT-IGNORE claim semantics no find()+persist()+flush() sequence can
 * provide (a race would let two processes both believe they won the
 * lock), so it keeps the raw statement.
 *
 * @extends EntityRepository<ImageEntity>
 */
final class ImageRepository extends EntityRepository
{
    /**
     * Deliberately avoids bumping `lastmodified` (the original's own SQL
     * comment, preserved) -- an image's "last modified" timestamp should
     * reflect real edits, not visit counting. A raw SQL fragment (not a
     * bound parameter) is required for the self-assignment, which a mapped
     * entity property write can't express -- stays raw DBAL, same
     * reasoning as Auth\AuthRepository::saveLastVisitFromHistory(). Clears
     * the identity map afterward since this bypasses the ORM for a row
     * {@see ImageEntity} may already have cached.
     */
    public function incrementVisitCounter(int $imageId): void
    {
        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement(
                'UPDATE ' . Tables::images() . ' SET hit = hit + 1, lastmodified = lastmodified WHERE id = ?',
                [$imageId]
            );
        $em->clear();
    }

    /**
     * Sets (or clears, when $coi is null) an image's crop-of-interest
     * 4-character code (admin/picture_coi.php, the only caller).
     */
    public function updateCoi(int $imageId, ?string $coi): void
    {
        $entity = $this->find($imageId);
        if ($entity === null) {
            return;
        }

        $entity->coi = $coi;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<array{image_id: int, ext: string}>
     */
    public function findFormatsByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.imageId AS image_id', 'f.ext AS ext')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId IN (:imageIds)')
            ->setParameter('imageIds', $imageIds)
            ->getQuery()
            ->getResult();
    }

    public function findFormatById(int $formatId): ?ImageFormat
    {
        $entity = $this->getEntityManager()
            ->find(ImageFormatEntity::class, $formatId);

        return $entity === null ? null : ImageFormat::fromEntity($entity);
    }

    /**
     * @return list<ImageFormat>
     */
    public function findFormatsForImage(int $imageId): array
    {
        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId = :imageId')
            ->setParameter('imageId', $imageId)
            ->getQuery()
            ->getResult();

        return array_map(ImageFormat::fromEntity(...), $entities);
    }

    /**
     * @param array<int, int> $imageIds
     * @return list<ImageFormat>
     */
    public function findFullFormatsByImageIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $entities = $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.imageId IN (:imageIds)')
            ->setParameter('imageIds', $imageIds, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getResult();

        return array_map(ImageFormat::fromEntity(...), $entities);
    }

    /**
     * @param array<int, int|string> $imageIds
     * @return list<array{id: int, path: string, representative_ext: ?string}>
     */
    public function findPathsForFileDeletion(array $imageIds): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
     * {@see deleteImages()}, must come last). `image_format` is one of
     * this repository's own entity-mapped tables ({@see ImageFormatEntity})
     * but this bulk cross-table sweep stays raw DBAL for all 7 uniformly
     * -- clears the identity map once at the end rather than converting
     * just the one table this repository happens to own.
     *
     * @param array<int, int|string> $ids
     */
    public function deleteElementReferences(array $ids): void
    {
        $idsStr = wordwrap(implode(', ', $ids), 80, "\n");
        $conn = $this->getEntityManager()
            ->getConnection();

        foreach ([Tables::comments(), Tables::imageCategory(), Tables::imageFormat(), Tables::imageTag(), Tables::favorites()] as $table) {
            $conn->executeStatement('
DELETE FROM ' . $table . '
  WHERE image_id IN (' . $idsStr . ')
;');
        }

        foreach ([Tables::rate(), Tables::caddie()] as $table) {
            $conn->executeStatement('
DELETE FROM ' . $table . '
  WHERE element_id IN (' . $idsStr . ')
;');
        }

        $this->getEntityManager()
            ->clear();
    }

    /**
     * @param array<int, int|string> $ids
     */
    public function deleteImages(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageEntity::class, 'i')
            ->where('i.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
        $em->clear();
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

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
SELECT
    id
  FROM ' . Tables::categories() . '
  WHERE representative_picture_id IN (' . $idsStr . ')
;')->fetchFirstColumn());
    }

    /**
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findLoungeRows(): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT
    image_id,
    category_id
  FROM ' . Tables::lounge() . '
  ORDER BY category_id ASC, image_id ASC
;')->fetchAllAssociative();
    }

    public function deleteLoungeUpTo(int $maxImageId): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                'DELETE FROM ' . Tables::lounge() . ' WHERE image_id <= ?',
                [$maxImageId]
            );
    }

    /**
     * Atomically claims the lounge-emptying lock via `INSERT IGNORE` --
     * a no-op if another process already holds it. json_encode()s
     * $lockValue (gap-closure Stage 1a-bis item 5: config.value is JSON
     * now) so it round-trips through CurrentConfig::emptyLoungeRunning()'s
     * own ConfigService::hydrate() read path too, not just
     * findLoungeLockValue() below -- a bare unquoted value would also
     * break this INSERT's own double-quote-delimited SQL literal.
     *
     * Stays raw DBAL rather than delegating to Config\ConfigRepository::
     * upsert() -- that method always finds-then-writes, which can't
     * reproduce this INSERT IGNORE's real atomicity (two concurrent
     * emptyLounge() runs could otherwise both believe they'd won the
     * lock). Clears the identity map afterward since this bypasses the
     * ORM for a row Config\ConfigEntry may already have cached.
     */
    public function tryAcquireLoungeLock(string $lockValue): void
    {
        $encodedLockValue = json_encode($lockValue);
        assert($encodedLockValue !== false);

        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement(
                'INSERT IGNORE INTO ' . Tables::config() . ' SET param = ?, value = ?',
                ['empty_lounge_running', $encodedLockValue]
            );
        $em->clear();
    }

    public function findLoungeLockValue(): ?string
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(
                'SELECT value FROM ' . Tables::config() . ' WHERE param = "empty_lounge_running"'
            )->fetchOne();

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @param array<int|string> $images real callers don't guarantee a list
     * @param array<int|string> $categories
     * @return array<int, int[]> category_id => list of already-associated image ids
     */
    public function findExistingAssociations(array $images, array $categories): array
    {
        $existing = [];
        foreach ($this->getEntityManager()->getConnection()->executeQuery('
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
        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
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
        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement('
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $category . '
    AND image_id IN (' . implode(',', $imageIds) . ')
');
        $em->clear();
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

        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement($query);
        $em->clear();
    }

    /**
     * @return list<int>
     */
    public function findImageIdsWithoutMd5sum(): array
    {
        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery('
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
        $paths = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT
    id,
    path
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(', ', $ids) . ')
;')->fetchAllKeyValue();

        return array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $paths);
    }

    public function countAllImages(): int
    {
        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM ' . Tables::images() . ';')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    public function countImagesInCategories(): int
    {
        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('SELECT COUNT(DISTINCT(image_id)) FROM ' . Tables::imageCategory() . ';')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @return list<int>
     */
    public function findLoungedImageIds(): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery('SELECT image_id FROM ' . Tables::lounge() . ';')->fetchFirstColumn()
        );
    }

    /**
     * @param list<int> $loungedIds
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
    AND id NOT IN (' . implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $loungedIds)) . ')';
        }

        $query .= '
  ORDER BY id ASC
;';

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery($query)->fetchFirstColumn());
    }

    /**
     * @param array<int, int|string> $imageIds
     */
    public function touchLastmodified(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->update(ImageEntity::class, 'i')
            ->set('i.lastmodified', ':now')
            ->where('i.id IN (:imageIds)')
            ->setParameter('now', Env::now()->format('Y-m-d H:i:s'))
            ->setParameter('imageIds', $imageIds)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    public function findById(int|string $imageId): ?Image
    {
        $entity = $this->find((int) $imageId);

        return $entity === null ? null : Image::fromEntity($entity);
    }

    /**
     * ImageDerivativeController (i.php)'s own source-file-to-image-row
     * lookup.
     */
    public function findByPath(string $path): ?Image
    {
        $entity = $this->findOneBy([
            'path' => $path,
        ]);

        return $entity === null ? null : Image::fromEntity($entity);
    }

    public function updateRotation(int $imageId, int $rotationCode): void
    {
        $entity = $this->find($imageId);
        if ($entity === null) {
            return;
        }

        $entity->rotation = $rotationCode;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int, Image> keyed by image id -- PHP canonicalises a
     *   numeric-string array key back to an int key, so this is always
     *   int-keyed at runtime regardless of `$ids`' own element types.
     */
    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
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
            if (is_numeric($id)) {
                $byId[(int) $id] = \Piwigo\Image\Projection\Image::fromRow($row);
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

        new BatchWriter($this->getEntityManager()->getConnection())
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

        new BatchWriter($this->getEntityManager()->getConnection())
            ->massInsert(Tables::imageCategory(), array_keys($inserts[0]), $inserts);
        $this->getEntityManager()
            ->clear();
    }

    /**
     * @param  list<array{id: int|string, md5sum: string}>  $updates
     */
    public function massUpdateMd5sums(array $updates): void
    {
        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                Tables::images(),
                [
                    'primary' => ['id'],
                    'update' => ['md5sum'],
                ],
                $updates
            );
        $this->getEntityManager()
            ->clear();
    }

    public function updateDimensions(int $imageId, int $width, int $height): void
    {
        $entity = $this->find($imageId);
        if ($entity === null) {
            return;
        }

        $entity->width = $width;
        $entity->height = $height;
        $this->getEntityManager()
            ->flush();
    }

    /**
     * @param  list<array{category_id: int|string, image_id: int|string, rank: int}>  $datas
     */
    public function massUpdateImageCategoryRanks(array $datas): void
    {
        new BatchWriter($this->getEntityManager()->getConnection())
            ->massUpdate(
                Tables::imageCategory(),
                [
                    'primary' => ['image_id', 'category_id'],
                    'update' => ['rank'],
                ],
                $datas
            );
        $this->getEntityManager()
            ->clear();
    }
}
