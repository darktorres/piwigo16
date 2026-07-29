<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\Dto\PaginatedResult;
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
 * ({@see ImageFormatEntity}) -- the single-row/simple-list methods
 * against those two tables go through DQL, as do two bulk id-list
 * methods against `images` itself (deleteImages()/touchLastmodified(),
 * both QueryBuilder delete()/update() with a bound `IN (:ids)`); every
 * other method here is a bulk id-list operation (raw string-concatenated
 * `IN (...)`, matching the original) or a cross-domain read/write into a
 * table another repository owns (`categories`, `image_category`,
 * `image_tag`, `comments`, `favorites`, `rate`, `caddie`) -- those stay
 * plain DBAL via $this->getEntityManager()->getConnection(), same "mixed
 * repository" shape Tag/Category's own conversions established, skewed
 * further toward "stays raw" given how few of this repository's real
 * methods are single-entity CRUD. `lounge`/`config` (the empty-lounge
 * lock flag) are read/written raw for the same reason -- `config`
 * specifically can't delegate to Config\ConfigRepository::upsert() the
 * way Admin\MenubarPageRenderer's own config write can (that one is a
 * plain upsert; the now-deleted Menu\MenubarLayoutRepository::saveLayout()
 * this mirrors was the same shape): tryAcquireLoungeLock() needs real
 * atomic INSERT-IGNORE claim semantics no find()+persist()+flush()
 * sequence can provide (a race would let two processes both believe
 * they won the lock), so it keeps the raw statement.
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
     * image_id/ext for format rows matching $formatIds (their own primary
     * key, `format_id`, not `image_id`) -- Ws\PwgImages::formatsDelete()'s
     * own "which images/extensions are these formats for" lookup, before
     * deleting them via deleteFormatsByIds() below.
     *
     * @param list<int> $formatIds
     * @return list<array{image_id: int, ext: string}>
     */
    public function findImageIdsAndExtsByFormatIds(array $formatIds): array
    {
        if ($formatIds === []) {
            return [];
        }

        return $this->getEntityManager()
            ->createQueryBuilder()
            ->select('f.imageId AS image_id', 'f.ext AS ext')
            ->from(ImageFormatEntity::class, 'f')
            ->where('f.formatId IN (:formatIds)')
            ->setParameter('formatIds', $formatIds)
            ->getQuery()
            ->getResult();
    }

    /**
     * Deletes format rows by their own primary key (`format_id`, not
     * `image_id`) -- Controller\Admin\SiteUpdateSubController's own
     * "formats no longer present on disk" cleanup step.
     *
     * @param  list<int>  $formatIds
     */
    public function deleteFormatsByIds(array $formatIds): void
    {
        if ($formatIds === []) {
            return;
        }

        $em = $this->getEntityManager();
        $em->createQueryBuilder()
            ->delete(ImageFormatEntity::class, 'f')
            ->where('f.formatId IN (:formatIds)')
            ->setParameter('formatIds', $formatIds)
            ->getQuery()
            ->execute();
        $em->clear();
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
        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT
    id,
    path,
    representative_ext
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $imageIds) . ')
;')->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'path' => is_string($row['path']) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            ],
            $rows
        );
    }

    /**
     * Same 3 columns as {@see findPathsForFileDeletion()}, plus `level` --
     * Ws\PwgCategories::getList()'s own "does the viewer's privacy level
     * allow this thumbnail" check.
     *
     * @param  list<int>  $imageIds
     * @return list<array{id: int, path: string, representative_ext: ?string, level: int}>
     */
    public function findPathsAndLevelForIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT id, path, representative_ext, level
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $imageIds) . ')
;')->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'path' => is_string($row['path']) ? $row['path'] : '',
                'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
                'level' => is_numeric($row['level'] ?? null) ? (int) $row['level'] : 0,
            ],
            $rows
        );
    }

    /**
     * Bulk-sets `level` for a batch of image ids -- Ws\PwgImages::
     * setPrivacyLevel()'s own WS write. Caller clears the EntityManager
     * afterward (same "caller clears" convention documented elsewhere,
     * e.g. CategoryService::setRepresentativeImage()) since this bypasses
     * the ORM.
     *
     * @param array<int, int> $imageIds
     * @return int affected row count
     */
    public function updateLevelForImages(array $imageIds, int $level): int
    {
        return (int) $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
UPDATE ' . Tables::images() . '
  SET level=' . $level . '
  WHERE id IN (' . implode(',', $imageIds) . ')
;');
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
        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('
SELECT
    image_id,
    category_id
  FROM ' . Tables::lounge() . '
  ORDER BY category_id ASC, image_id ASC
;')->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'image_id' => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
                'category_id' => is_numeric($row['category_id']) ? (int) $row['category_id'] : 0,
            ],
            $rows
        );
    }

    /**
     * Number of lounge rows for $categoryId not yet linked into
     * `image_category` -- Ws\PwgImages::upload()'s own "how many photos
     * are still awaiting validation in this category" response field.
     */
    public function countLoungeImagesPendingForCategory(int $categoryId): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT COUNT(*)
  FROM ' . Tables::lounge() . '
  WHERE category_id = ' . $categoryId . '
  AND image_id NOT IN (SELECT image_id FROM ' . Tables::imageCategory() . ')
;');

        return is_numeric($value) ? (int) $value : 0;
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
     * Breaks image_category links for one $imageId, scoped to a specific
     * set of $categoryIds -- Ws\PwgImages::addImageCategoryRelations()'s
     * own replace-mode cleanup of associations no longer present in the
     * caller's requested category list. Unlike deleteImageCategoryLinks()
     * above (many images, one category), this is one image, many
     * categories.
     *
     * @param list<int|string> $categoryIds
     */
    public function deleteImageCategoryLinksForCategoryIds(int $imageId, array $categoryIds): void
    {
        if ($categoryIds === []) {
            return;
        }

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $imageId . '
    AND category_id IN (' . implode(',', $categoryIds) . ')
;');
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

    /**
     * path/file/md5sum/width/height/filesize for $imageId -- Ws\PwgImages::
     * addFile()'s own "what's the current state of this image, before we
     * merge in a bigger chunked upload" lookup.
     *
     * @return ?array{path: string, file: string, md5sum: ?string, width: ?int, height: ?int, filesize: ?int}
     */
    public function findUploadInfoById(int $imageId): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT
    path, file, md5sum,
    width, height, filesize
  FROM ' . Tables::images() . '
  WHERE id = ' . $imageId . '
;');

        if ($row === false) {
            return null;
        }

        return [
            'path' => is_string($row['path']) ? $row['path'] : '',
            'file' => is_string($row['file']) ? $row['file'] : '',
            'md5sum' => is_string($row['md5sum'] ?? null) ? $row['md5sum'] : null,
            'width' => is_numeric($row['width'] ?? null) ? (int) $row['width'] : null,
            'height' => is_numeric($row['height'] ?? null) ? (int) $row['height'] : null,
            'filesize' => is_numeric($row['filesize'] ?? null) ? (int) $row['filesize'] : null,
        ];
    }

    /**
     * Whether at least one image matches an already-built $condition (a
     * bare SQL boolean expression, not prefixed with WHERE) --
     * Ws\PwgImages::add()'s own upload-time uniqueness check ($condition is
     * caller-built from CurrentConfig::uniquenessMode(), not user input).
     * Same "caller composes trusted fragments" contract as
     * findWithConditionsPaginated() above.
     */
    public function existsWithCondition(string $condition): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE ' . $condition . '
;');

        return is_numeric($value) && (int) $value > 0;
    }

    public function countAllImages(): int
    {
        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM ' . Tables::images() . ';')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Total `filesize` across every image -- Admin\InstallationStats's own
     * "disk_usage" summary figure (original photos only; format-file disk
     * usage is a separate figure, see {@see countAndSumFormats()}).
     */
    public function sumFilesize(): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT
    SUM(filesize)
  FROM ' . Tables::images() . '
;');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Row count and total `filesize` across every generated format file --
     * Admin\InstallationStats's own "nb_formats"/"formats_disk_usage"
     * summary figures.
     *
     * @return array{count: int, sum: int}
     */
    public function countAndSumFormats(): array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchNumeric('
SELECT
    COUNT(*),
    SUM(filesize)
  FROM ' . Tables::imageFormat() . '
;');

        return [
            'count' => ($row !== false && is_numeric($row[0])) ? (int) $row[0] : 0,
            'sum' => ($row !== false && is_numeric($row[1] ?? null)) ? (int) $row[1] : 0,
        ];
    }

    /**
     * Every image's id/file (unfiltered) -- Ws\PwgImages::
     * formatsSearchImage()'s own "build a filename-without-extension index
     * of every photo" scan.
     *
     * @return list<array{id: int, file: string}>
     */
    public function findAllIdsAndFiles(): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'file' => is_string($row['file']) ? $row['file'] : '',
            ],
            $this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT
    id,
    file
  FROM ' . Tables::images() . '
;')
        );
    }

    /**
     * Every image_format row's image_id/ext (unfiltered) -- Ws\PwgImages::
     * formatsSearchImage()'s own "which formats already exist per image"
     * scan.
     *
     * @return list<array{image_id: int, ext: string}>
     */
    public function findAllImageIdsAndExts(): array
    {
        return array_map(
            static fn (array $row): array => [
                'image_id' => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
                'ext' => is_string($row['ext']) ? $row['ext'] : '',
            ],
            $this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT
    image_id,
    ext
  FROM ' . Tables::imageFormat() . '
;')
        );
    }

    /**
     * Earliest `date_available` among every image -- Admin\
     * InstallationStats::getInstallationDate()'s own last-resort
     * installation-date candidate.
     */
    public function findEarliestDateAvailable(): ?string
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    date_available
  FROM ' . Tables::images() . '
  ORDER BY id ASC
  LIMIT 1
;');

        if ($rows === []) {
            return null;
        }

        $dateAvailable = $rows[0]['date_available'];

        return is_string($dateAvailable) ? $dateAvailable : null;
    }

    public function countImagesInCategories(): int
    {
        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('SELECT COUNT(DISTINCT(image_id)) FROM ' . Tables::imageCategory() . ';')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Total row count of `image_category` (every link, not distinct
     * images -- a different figure from {@see countImagesInCategories()}
     * above) -- Ws\PwgCore::getInfos()'s own "nb_image_category" summary
     * figure.
     */
    public function countImageCategoryLinks(): int
    {
        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery('SELECT COUNT(*) FROM ' . Tables::imageCategory() . ';')->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Earliest `date_available` across every image -- Ws\PwgCore::
     * getInfos()'s own "first_date" summary figure, a different query
     * from {@see findEarliestDateAvailable()} above (that one is the
     * first-inserted image's own date, by id; this one is the minimum
     * date value regardless of which image it belongs to).
     */
    public function findMinDateAvailable(): ?string
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('SELECT MIN(date_available) FROM ' . Tables::images() . ';');

        return is_string($value) ? $value : null;
    }

    /**
     * Next free id and total row count, in one round trip --
     * Ws\PwgCore::getMissingDerivatives()'s own pagination-cursor
     * bootstrap (same MAX(id)+1 shape as {@see findNextId()} above, plus
     * COUNT(*) for the "nothing to do" early exit).
     *
     * @return array{nextId: int, count: int}
     */
    public function findNextIdAndCount(): array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchNumeric('SELECT MAX(id)+1, COUNT(*) FROM ' . Tables::images() . ';');

        return [
            'nextId' => ($row !== false && is_numeric($row[0] ?? null)) ? (int) $row[0] : 0,
            'count' => ($row !== false && is_numeric($row[1] ?? null)) ? (int) $row[1] : 0,
        ];
    }

    /**
     * One page of images with id below $startId, matching already-built
     * $whereClauses -- Ws\PwgCore::getMissingDerivatives()'s own
     * cursor-paginated scan, one real caller. $whereClauses are
     * already-built, trusted SQL fragments (WsHelper::stdImageSqlFilter()'s
     * own output plus an optional `id IN (...)` filter), same "caller
     * composes trusted fragments" contract used throughout this
     * repository.
     *
     * @param  list<string>  $whereClauses
     * @return list<array<string, mixed>>
     */
    public function findForMissingDerivatives(array $whereClauses, int $startId, int $limit): array
    {
        $allClauses = $whereClauses;
        $allClauses[] = 'id < ' . $startId;

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, path, representative_ext, width, height, rotation
  FROM ' . Tables::images() . '
  WHERE ' . implode(' AND ', $allClauses) . '
  ORDER BY id DESC
  LIMIT ' . $limit . '
;');
    }

    /**
     * id/label(computed)/filesize/file/path/representative_ext for
     * $imageIds -- Ws\PwgCore::historySearch()'s own thumbnail/label
     * enrichment step, keyed by id.
     *
     * @param  list<int|string>  $imageIds
     * @return array<int|string, array<string, mixed>>
     */
    public function findHistoryDisplayInfoByIds(array $imageIds): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    id,
    IF(name IS NULL, file, name) AS label,
    filesize,
    file,
    path,
    representative_ext
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $imageIds) . ')
;');

        return array_column($rows, null, 'id');
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
    AND id NOT IN (' . implode(',', $loungedIds) . ')';
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

    /**
     * Distinct categories among $imageIds where the link is virtual (the
     * category isn't that image's own storage_category_id, or the image
     * has no storage category at all) -- Admin\BatchManager\
     * FilterPanelRenderer's own "which categories can this selection be
     * dissociated from" listing. Stays raw (id-keyed rows, not a plain
     * list) since the caller's own `array_column(..., 'id', 'id')`
     * membership-set idiom is preserved unchanged at the call site.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findVirtuallyAssociatedCategoryRows(array $imageIds): array
    {
        $idsForSql = array_map(strval(...), $imageIds);

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    DISTINCT(category_id) AS id
  FROM ' . Tables::imageCategory() . ' AS ic
    JOIN ' . Tables::images() . ' AS i ON i.id = ic.image_id
  WHERE ic.image_id IN (' . implode(',', $idsForSql) . ')
    AND (
      ic.category_id != i.storage_category_id
      OR i.storage_category_id IS NULL
    )
;');
    }

    /**
     * Thumbnail-display rows for $categoryId, ordered by rank --
     * Admin\ElementSetRanksPageRenderer's own "sort_order" tab listing.
     * Stays raw (not a shaped array) since every row is handed straight
     * into {@see \Piwigo\Image\SrcImage}'s own constructor, which already
     * narrows each key itself.
     *
     * @return list<array<string, mixed>>
     */
    public function findThumbnailRowsForCategoryOrderedByRank(int $categoryId): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    id,
    file,
    path,
    representative_ext,
    width, height, rotation,
    name,
    `rank`
  FROM ' . Tables::images() . '
    JOIN ' . Tables::imageCategory() . ' ON image_id = id
  WHERE category_id = ' . $categoryId . '
  ORDER BY `rank`
;');
    }

    /**
     * image_id list for $categoryId ordered by rank ascending --
     * Ws\PwgImages::setRank()'s own multi-image "return the new order"
     * response.
     *
     * @return list<int|string>
     */
    public function findImageIdsOrderedByRankForCategory(int $categoryId): array
    {
        return array_values(array_filter(
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT
    image_id
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $categoryId . '
  ORDER BY `rank` ASC
;'), 'image_id'),
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));
    }

    /**
     * Whether $imageId is associated to $categoryId -- Ws\PwgImages::
     * setRank()'s own "is this image even in that category" guard.
     */
    public function isImageInCategory(int $imageId, int $categoryId): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT COUNT(*)
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $imageId . '
    AND category_id = ' . $categoryId . '
;');

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Current highest `rank` for one category (singular -- unlike
     * findMaxRanksByCategory() above, which takes a batch) --
     * Ws\PwgImages::setRank()'s own "what's the current max rank" lookup.
     * Returns null when no image in this category has a rank set yet.
     */
    public function findMaxRankForCategory(int $categoryId): ?int
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT MAX(`rank`) AS max_rank
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $categoryId . '
;');

        if ($row === false || ! is_numeric($row['max_rank'])) {
            return null;
        }

        return (int) $row['max_rank'];
    }

    /**
     * Bumps `rank` by 1 for every image in $categoryId whose rank is >=
     * $rank -- Ws\PwgImages::setRank()'s own "make room" step before
     * inserting a new rank value. `image_category` isn't ORM-mapped, so
     * no caller-side EntityManager::clear() is needed after this write.
     */
    public function incrementRanksFromForCategory(int $categoryId, int $rank): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
UPDATE ' . Tables::imageCategory() . '
  SET `rank` = `rank` + 1
  WHERE category_id = ' . $categoryId . '
    AND `rank` IS NOT NULL
    AND `rank` >= ' . $rank . '
;');
    }

    /**
     * Sets `rank` for one (imageId, categoryId) image_category row --
     * Ws\PwgImages::setRank()'s own final write.
     */
    public function updateRankForImageInCategory(int $imageId, int $categoryId, int $rank): void
    {
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
UPDATE ' . Tables::imageCategory() . '
  SET `rank` = ' . $rank . '
  WHERE image_id = ' . $imageId . '
    AND category_id = ' . $categoryId . '
;');
    }

    /**
     * The category (id + uppercats) the most recently added image was
     * placed into -- Admin\PhotosAddDirectPageRenderer's own "default the
     * upload form to whichever album the last photo went into" lookup.
     *
     * @return array{category_id: int|string, uppercats: string}|null
     */
    public function findMostRecentImageCategoryInfo(): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT category_id, c.uppercats
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON image_id = i.id
    JOIN ' . Tables::categories() . ' AS c ON category_id = c.id
  ORDER BY i.id DESC
  LIMIT 1
;');

        if ($row === false) {
            return null;
        }

        $categoryId = $row['category_id'];
        assert(is_int($categoryId) || is_string($categoryId));
        $uppercats = $row['uppercats'];
        assert(is_string($uppercats));

        return [
            'category_id' => $categoryId,
            'uppercats' => $uppercats,
        ];
    }

    /**
     * Every distinct (width, height) pair among images that have both set
     * -- Controller\Admin\BatchManagerSubController's own dimension-filter
     * option aggregation.
     *
     * @return list<array<string, mixed>>
     */
    public function findDistinctDimensions(): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
  DISTINCT width, height
  FROM ' . Tables::images() . '
  WHERE width IS NOT NULL
    AND height IS NOT NULL
;');
    }

    /**
     * Every distinct filesize among images that have one set --
     * Controller\Admin\BatchManagerSubController's own filesize-filter
     * option aggregation.
     *
     * @return list<array<string, mixed>>
     */
    public function findDistinctFilesizes(): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
  filesize
  FROM ' . Tables::images() . '
  WHERE filesize IS NOT NULL
  GROUP BY filesize
;');
    }

    /**
     * Paginated thumbnail rows for the batch manager's global-mode grid --
     * Admin\BatchManagerGlobalPageRenderer's own listing. $orderBySql is a
     * raw " ORDER BY ..." fragment (CurrentConfig::orderBy()/
     * orderByInsideCategory(), a category's own image_order, or the
     * duplicates-fields ordering the caller computes) -- embedded as text
     * like every other order-by fragment in this codebase, not something
     * this extraction should re-litigate. $categoryId non-null additionally
     * joins imageCategory and restricts to that category.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findBatchManagerThumbnails(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        $query = '
SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation
  FROM ' . Tables::images();

        if ($categoryId !== null) {
            $query .= '
    JOIN ' . Tables::imageCategory() . ' ON id = image_id';
        }

        $query .= '
  WHERE id IN (' . implode(',', $imageIds) . ')';

        if ($categoryId !== null) {
            $query .= '
    AND category_id = ' . $categoryId;
        }

        $query .= '
  ' . $orderBySql . '
  LIMIT ' . $limit . ' OFFSET ' . $offset . '
;';

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($query);
    }

    /**
     * id + date_creation for $imageIds -- Admin\BatchManagerUnitPageRenderer's
     * own per-image form-submission save loop.
     *
     * @param array<array-key, int|string> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findIdsAndDatesForBatchUnitSave(array $imageIds): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, date_creation
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $imageIds) . ')
;');
    }

    /**
     * Same dynamic pagination shape as findBatchManagerThumbnails() above,
     * but every column (`SELECT *`) -- Admin\BatchManagerUnitPageRenderer's
     * own per-image inline-edit grid needs far more columns than the
     * global-mode thumbnail grid does.
     *
     * @param array<array-key, int|string|float|bool> $imageIds
     * @return list<array<string, mixed>>
     */
    public function findBatchManagerUnitRows(array $imageIds, ?int $categoryId, string $orderBySql, int $limit, int $offset): array
    {
        $query = '
SELECT *
  FROM ' . Tables::images();

        if ($categoryId !== null) {
            $query .= '
    JOIN ' . Tables::imageCategory() . ' ON id = image_id';
        }

        $query .= '
  WHERE id IN (' . implode(',', $imageIds) . ')';

        if ($categoryId !== null) {
            $query .= '
    AND category_id = ' . $categoryId;
        }

        $query .= '
  ' . $orderBySql . '
  LIMIT ' . $limit . ' OFFSET ' . $offset . '
;';

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($query);
    }

    /**
     * Categories $imageId is linked to, with each category's own uppercats
     * and dir -- Admin\BatchManagerUnitPageRenderer's own per-image
     * "related albums" display.
     *
     * @return list<array<string, mixed>>
     */
    public function findCategoryLinksForImage(int $imageId): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT category_id, uppercats, dir
  FROM ' . Tables::imageCategory() . ' AS ic
    INNER JOIN ' . Tables::categories() . ' AS c
      ON c.id = ic.category_id
  WHERE image_id = ' . $imageId . '
;');
    }

    /**
     * Bare category ids $imageId is linked to (no join) --
     * Admin\BatchManagerUnitPageRenderer's own "jump to" link permission
     * check, a separate query from findCategoryLinksForImage() above (same
     * image_id, no uppercats/dir needed here).
     *
     * @return list<int>
     */
    public function findCategoryIdsForImage(int $imageId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT category_id
  FROM ' . Tables::imageCategory() . '
  WHERE image_id = ' . $imageId . '
;'), 'category_id')
        );
    }

    /**
     * Ids of images uploaded before the 2022-12-08 issue-1827 fix, under
     * `./upload/`, capped at $limit -- Admin\Maintenance\
     * FilesystemIntegrityChecker::fsQuickCheck()'s own sampling pool for
     * that historical bug, merged with findImageIdsSample()'s general
     * random pool before the actual file_exists() check.
     *
     * @return list<int>
     */
    public function findIssue1827CandidateImageIds(int $limit): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE date_available < \'2022-12-08 00:00:00\'
    AND path LIKE \'./upload/%\'
  LIMIT ' . $limit . '
;'), 'id')
        );
    }

    /**
     * Every image id, capped at $limit, in whatever order the database
     * happens to return them -- FilesystemIntegrityChecker::fsQuickCheck()'s
     * own general sampling pool (the caller shuffles and slices further).
     *
     * @return list<int>
     */
    public function findImageIdsSample(int $limit): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT
    id
  FROM ' . Tables::images() . '
  LIMIT ' . $limit . '
;'), 'id')
        );
    }

    /**
     * Every path sharing its value with at least one other image row --
     * FilesystemIntegrityChecker::fsQuickCheck()'s own duplicate-path
     * detection (only the count matters to the caller).
     *
     * @return list<string>
     */
    public function findDuplicatePaths(): array
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    path
  FROM ' . Tables::images() . '
  GROUP BY path
  HAVING COUNT(*) > 1
;');

        return array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            array_column($rows, 'path')
        );
    }

    /**
     * Image ids referenced by `image_category` with no matching row in
     * `images` at all -- FilesystemIntegrityChecker::imagesIntegrity()'s
     * own orphaned-link detection.
     *
     * @return list<int>
     */
    public function findOrphanImageCategoryLinkIds(): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT
    image_id
  FROM ' . Tables::imageCategory() . '
    LEFT JOIN ' . Tables::images() . ' ON id = image_id
  WHERE id IS NULL
;'), 'image_id')
        );
    }

    /**
     * Deletes every `image_category` row for $imageIds regardless of
     * category -- FilesystemIntegrityChecker::imagesIntegrity()'s own
     * orphaned-link cleanup, unlike deleteImageCategoryLinks() above which
     * is scoped to one category.
     *
     * @param list<int> $imageIds
     */
    public function deleteImageCategoryRowsForImageIds(array $imageIds): void
    {
        if ($imageIds === []) {
            return;
        }

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement('
DELETE
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
;');
    }

    /**
     * id/file/level for $imageId (when positive) or the first image whose
     * file matches $imageFile (a `LIKE` pattern, `_`/`%` already escaped by
     * the caller) -- Controller\PictureController's own "resolve the
     * requested picture, by id or by filename" lookup.
     *
     * @return array<string, mixed>|false
     */
    public function findByIdOrFilePattern(int $imageId, ?string $imageFile): array|false
    {
        $query = '
SELECT id, file, level
  FROM ' . Tables::images() . '
  WHERE ';
        if ($imageId > 0) {
            $query .= 'id = ' . $imageId;
        } else {
            assert($imageFile !== null && $imageFile !== '');
            $query .= 'file LIKE \'' . $imageFile . '.%\' ESCAPE \'/\' LIMIT 1';
        }

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative($query);
    }

    /**
     * Ids of images already at $filename within $categoryId -- Ws\
     * PwgImages::upload()'s own "update_mode" replace-existing lookup.
     *
     * @return list<int>
     */
    public function findIdsByFilenameInCategory(string $filename, int $categoryId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->createQueryBuilder()
                ->select('i.id')
                ->from(Tables::images(), 'i')
                ->innerJoin('i', Tables::imageCategory(), 'ic', 'ic.image_id = i.id')
                ->where('i.file = :filename')
                ->andWhere('ic.category_id = :categoryId')
                ->setParameter('filename', $filename)
                ->setParameter('categoryId', $categoryId)
                ->executeQuery()
                ->fetchFirstColumn()
        );
    }

    /**
     * `path` for $imageId, or null if it doesn't exist -- Ws\PwgImages::
     * checkFiles()'s own "does the client's local file match ours" lookup.
     */
    public function findPathById(int $imageId): ?string
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT path
  FROM ' . Tables::images() . '
  WHERE id = ' . $imageId . '
;');

        return is_string($value) ? $value : null;
    }

    /**
     * id/name/representative_ext/path for $imageId -- Ws\PwgImages::
     * upload()'s own "what does the just-uploaded/replaced photo look
     * like" lookup, used to build the response's thumbnail URLs.
     *
     * @return ?array{id: int, name: ?string, representative_ext: ?string, path: string}
     */
    public function findUploadResultInfoById(int $imageId): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT
    id,
    name,
    representative_ext,
    path
  FROM ' . Tables::images() . '
  WHERE id = ' . $imageId . '
;');

        if ($row === false) {
            return null;
        }

        return [
            'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
            'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
            'representative_ext' => is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
            'path' => is_string($row['path']) ? $row['path'] : '',
        ];
    }

    /**
     * Number of images linked to $categoryId -- Ws\PwgImages::upload()'s
     * own "how many photos are now in this category" response field.
     */
    public function countImagesInCategory(int $categoryId): int
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT COUNT(*)
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $categoryId . '
;');

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether $imageId is reachable via at least one category satisfying
     * $forbiddenCondition -- Controller\PictureController's own "can this
     * image still be accessed differently" fallback check.
     */
    public function isImageAccessibleWithCondition(int $imageId, string $forbiddenCondition): bool
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT id
  FROM ' . Tables::images() . ' INNER JOIN ' . Tables::imageCategory() . ' ON id=image_id
  WHERE id=' . $imageId . '
' . $forbiddenCondition . '
  LIMIT 1') !== false;
    }

    /**
     * Every column of $imageId's own row, if it satisfies
     * $permissionCondition -- Ws\PwgImages::getInfo()'s own image lookup.
     *
     * @return ?array<string, mixed>
     */
    public function findRowWithCondition(int $imageId, string $permissionCondition): ?array
    {
        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative('
SELECT *
  FROM ' . Tables::images() . '
  WHERE id=' . $imageId . '
' . $permissionCondition . '
LIMIT 1
;');

        return $row === false ? null : $row;
    }

    /**
     * Categories $imageId belongs to that satisfy $forbiddenCondition, with
     * each category's own display-relevant columns (including `commentable`,
     * unlike findVisibleCategoriesForImage() below) -- Ws\PwgImages::
     * getInfo()'s own "related categories" block.
     *
     * @return list<array<string, mixed>>
     */
    public function findRelatedCategoriesForImage(int $imageId, string $forbiddenCondition): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, name, permalink, uppercats, global_rank, commentable
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::categories() . ' ON category_id = id
  WHERE image_id = ' . $imageId . '
' . $forbiddenCondition . '
;');
    }

    /**
     * Whether $imageId belongs to at least one commentable category
     * satisfying $permissionCondition -- Ws\PwgImages::addComment()'s own
     * "can this image receive a comment" check.
     */
    public function isImageCommentableWithCondition(int $imageId, string $permissionCondition): bool
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT DISTINCT image_id
  FROM ' . Tables::imageCategory() . '
      INNER JOIN ' . Tables::categories() . ' ON category_id=id
  WHERE commentable=1
    AND image_id=' . $imageId . '
' . $permissionCondition . '
;') !== false;
    }

    /**
     * Categories $imageId belongs to that satisfy $permissionCondition,
     * with each category's own display-relevant columns -- Controller\
     * PictureController's own "related categories" block, ordered by
     * CategoryService::compareByGlobalRank() afterwards (not here).
     *
     * @return list<array<string, mixed>>
     */
    public function findVisibleCategoriesForImage(int $imageId, string $permissionCondition): array
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id,uppercats,commentable,visible,status,global_rank
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::categories() . ' ON category_id = id
  WHERE image_id = ' . $imageId . '
' . $permissionCondition . '
;');
    }

    /**
     * Ids of the real (INNER JOINed, so never-orphaned) categories
     * $imageId is associated with -- Admin\PictureModifyPageRenderer's
     * own "associate to albums" checkbox list, a different query from
     * findCategoryIdsForImage() above (that one has no join, so it can
     * include ids for categories that no longer exist).
     *
     * @return list<int>
     */
    public function findAssociatedCategoryIds(int $imageId): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT id
  FROM ' . Tables::categories() . '
    INNER JOIN ' . Tables::imageCategory() . ' ON id = category_id
  WHERE image_id = ' . $imageId . '
;'), 'id')
        );
    }

    /**
     * Ids of every image with $md5sum -- Admin\Upload\UploadService's own
     * upload-time duplicate detection.
     *
     * @return list<int>
     */
    public function findIdsByMd5sum(string $md5sum): array
    {
        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative('
SELECT
    id
  FROM ' . Tables::images() . '
  WHERE md5sum = \'' . $md5sum . '\'
;'), 'id')
        );
    }

    /**
     * id keyed by md5sum, for a batch of md5sums -- Ws\PwgImages::exist()'s
     * own bulk "which of these already-uploaded checksums exist" check.
     * Parameter-bound (unlike the original's raw string interpolation of
     * client-supplied md5sum values -- an injection risk fixed as part of
     * this migration).
     *
     * @param list<string> $md5sums
     * @return array<string, int>
     */
    public function findIdsByMd5sums(array $md5sums): array
    {
        if ($md5sums === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'md5sum')
            ->from(Tables::images())
            ->where('md5sum IN (:md5sums)')
            ->setParameter('md5sums', $md5sums, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $idByMd5sum = [];
        foreach ($rows as $row) {
            if (is_string($row['md5sum'] ?? null) && is_numeric($row['id'] ?? null)) {
                $idByMd5sum[$row['md5sum']] = (int) $row['id'];
            }
        }

        return $idByMd5sum;
    }

    /**
     * id keyed by filename, for a batch of filenames -- Ws\PwgImages::
     * exist()'s own bulk "which of these filenames already exist" check.
     * Parameter-bound, same injection-risk fix as findIdsByMd5sums() above.
     *
     * @param list<string> $filenames
     * @return array<string, int>
     */
    public function findIdsByFilenames(array $filenames): array
    {
        if ($filenames === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('id', 'file')
            ->from(Tables::images())
            ->where('file IN (:filenames)')
            ->setParameter('filenames', $filenames, ArrayParameterType::STRING)
            ->executeQuery()
            ->fetchAllAssociative();

        $idByFilename = [];
        foreach ($rows as $row) {
            if (is_string($row['file'] ?? null) && is_numeric($row['id'] ?? null)) {
                $idByFilename[$row['file']] = (int) $row['id'];
            }
        }

        return $idByFilename;
    }

    /**
     * The format_id of $imageId's existing format row for $ext, if any --
     * Admin\Upload\UploadService's own "update an existing format vs
     * insert a new one" check.
     */
    public function findFormatIdByImageAndExt(int $imageId, string $ext): ?int
    {
        $formatId = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('format_id')
            ->from(Tables::imageFormat())
            ->where('image_id = :imageId')
            ->andWhere('ext = :ext')
            ->setParameter('imageId', $imageId)
            ->setParameter('ext', $ext)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($formatId) ? (int) $formatId : null;
    }

    /**
     * Whether at least one accessible image (satisfying $permissionCondition)
     * has a non-null author -- Controller\SearchController's own "does this
     * gallery even have authors, for this user" check.
     */
    public function hasAccessibleImageWithAuthor(string $permissionCondition): bool
    {
        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    id
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON ic.image_id = i.id
  ' . $permissionCondition . '
    AND author IS NOT NULL
    LIMIT 1
;');

        return $rows !== [];
    }

    /**
     * Whether $imageId is reachable via at least one of its own categories
     * satisfying $permissionCondition -- Controller\ActionController's own
     * download-permission check. A different query shape from
     * isImageAccessibleWithCondition() above (this one starts from
     * `categories`, joined onto `image_category` by category_id, filtered
     * by image_id -- that one starts from `images`).
     */
    public function isImageAccessibleViaCategoryWithCondition(int $imageId, string $permissionCondition): bool
    {
        return $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT id
  FROM ' . Tables::categories() . '
    INNER JOIN ' . Tables::imageCategory() . ' ON category_id = id
  WHERE image_id = ' . $imageId . '
' . $permissionCondition . '
  LIMIT 1
;') !== false;
    }

    /**
     * Every column of every image matching already-built $whereClauses,
     * joined against `image_category` and deduplicated by image id --
     * Ws\PwgCategories::getImages()'s own paginated listing.
     * $whereClauses/$orderByClause are already-built, trusted SQL
     * fragments, same "caller composes trusted fragments" contract as
     * {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}.
     *
     * @param  list<string>  $whereClauses
     * @return PaginatedResult<array<string, mixed>>
     */
    public function findWithConditionsPaginated(array $whereClauses, string $orderByClause, int $limit, int $offset): PaginatedResult
    {
        $conn = $this->getEntityManager()
            ->getConnection();

        $sql = '
SELECT SQL_CALC_FOUND_ROWS i.*
  FROM ' . Tables::images() . ' i
    INNER JOIN ' . Tables::imageCategory() . ' ON i.id=image_id
  WHERE ' . implode("\n    AND ", $whereClauses) . '
  GROUP BY i.id
  ' . $orderByClause . '
  LIMIT ' . $limit . '
  OFFSET ' . $offset . '
;';

        $rows = $conn->fetchAllAssociative($sql);

        $totalRaw = $conn->fetchOne('SELECT FOUND_ROWS()');

        return new PaginatedResult($rows, is_numeric($totalRaw) ? (int) $totalRaw : 0);
    }

    /**
     * Whether an image with this id exists -- Ws\PwgCategories::
     * setRepresentative()'s own existence check.
     */
    public function existsById(int $id): bool
    {
        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $id . '
;');

        return is_numeric($value) && (int) $value > 0;
    }

    /**
     * Which of $ids are real image ids -- Ws\PwgImages::syncMetadata()'s
     * own "filter the caller's list down to images that actually exist"
     * step.
     *
     * @param array<int|string> $ids
     * @return list<int>
     */
    public function findExistingIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->fetchFirstColumn('
SELECT id
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $ids) . ')
;')
        );
    }

    /**
     * image_id/category_id link rows for $imageIds matching
     * $permissionCondition -- Ws\PwgCategories::getImages()'s own "which
     * albums (that the caller may see) is each returned photo linked to"
     * step.
     *
     * @param  list<int>  $imageIds
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findCategoryLinksForImageIdsWithCondition(array $imageIds, string $permissionCondition): array
    {
        if ($imageIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT
    image_id,
    category_id
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $imageIds) . ')
    AND ' . $permissionCondition . '
;');

        return array_map(
            static fn (array $row): array => [
                'image_id' => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
                'category_id' => is_numeric($row['category_id']) ? (int) $row['category_id'] : 0,
            ],
            $rows
        );
    }

    /**
     * Next free id -- Controller\Admin\SiteUpdateSubController's own
     * manual-id assignment for directory-synced images (mirrors the
     * retired MysqliDb::nextval()).
     */
    public function findNextId(): int
    {
        $next = $this->getEntityManager()
            ->getConnection()
            ->fetchOne('
SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1)
  FROM ' . Tables::images());

        return is_numeric($next) ? (int) $next : 1;
    }

    /**
     * path keyed by id, for every image whose storage_category_id is in
     * $categoryIds -- Controller\Admin\SiteUpdateSubController's own
     * "which files does the DB already know about, for these directory-
     * synced categories" step.
     *
     * @param  list<int|string>  $categoryIds
     * @return array<int, string> keyed by id
     */
    public function findIdsAndPathsByStorageCategoryIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative('
SELECT id, path
  FROM ' . Tables::images() . '
  WHERE storage_category_id IN (' . implode(',', $categoryIds) . ')
;');

        $byId = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            if (! is_numeric($id) || ! is_string($row['path'])) {
                continue;
            }

            $byId[(int) $id] = $row['path'];
        }

        return $byId;
    }
}
