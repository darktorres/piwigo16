<?php

declare(strict_types=1);

namespace Piwigo\Image;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityRepository;
use Piwigo\Common\Dto\PaginatedResult;
use Piwigo\Core\Env;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\SqlDialect;
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
        $imagesTable = Tables::images();
        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement(
                <<<SQL
                UPDATE {$imagesTable} SET hit = hit + 1, lastmodified = lastmodified WHERE id = ?
                SQL
                ,
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
        $imagesTable = Tables::images();
        $idsCsv = implode(',', $imageIds);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT
                    id,
                    path,
                    representative_ext
                FROM {$imagesTable}
                WHERE id IN ({$idsCsv})
                SQL)->fetchAllAssociative();

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

        $imagesTable = Tables::images();
        $idsCsv = implode(',', $imageIds);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT id, path, representative_ext, level
                FROM {$imagesTable}
                WHERE id IN ({$idsCsv})
                SQL)->fetchAllAssociative();

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
        $imagesTable = Tables::images();
        $idsCsv = implode(',', $imageIds);

        return (int) $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                UPDATE {$imagesTable}
                SET level={$level}
                WHERE id IN ({$idsCsv})
                SQL);
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
            $conn->executeStatement(<<<SQL
                DELETE FROM {$table}
                WHERE image_id IN ({$idsStr})
                SQL);
        }

        foreach ([Tables::rate(), Tables::caddie()] as $table) {
            $conn->executeStatement(<<<SQL
                DELETE FROM {$table}
                WHERE element_id IN ({$idsStr})
                SQL);
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
        $categoriesTable = Tables::categories();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT
                id
            FROM {$categoriesTable}
            WHERE representative_picture_id IN ({$idsStr})
            SQL)->fetchFirstColumn());
    }

    /**
     * @return list<array{image_id: int, category_id: int}>
     */
    public function findLoungeRows(): array
    {
        $loungeTable = Tables::lounge();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT
                    image_id,
                    category_id
                FROM {$loungeTable}
                ORDER BY category_id ASC, image_id ASC
                SQL)->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'image_id' => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
                'category_id' => is_numeric($row['category_id']) ? (int) $row['category_id'] : 0,
            ],
            $rows
        );
    }

    /**
     * `date_available` for the oldest photo still in the lounge, alongside
     * the DB server's own NOW() (so age can be computed without relying
     * on PHP's own clock) -- LoungeMaintenance::needsEmptying()'s own "is
     * the oldest lounge photo older than the max wait time" check.
     * Returns null when the lounge is empty.
     *
     * @return ?array{dateAvailable: string, dbNow: string}
     */
    public function findOldestLoungeAgeInfo(): ?array
    {
        $loungeTable = Tables::lounge();
        $imagesTable = Tables::images();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative(<<<SQL
                SELECT
                    date_available,
                    NOW() AS dbnow
                FROM {$loungeTable}
                    JOIN {$imagesTable} ON image_id = id
                ORDER BY image_id ASC
                LIMIT 1
                SQL);

        if ($row === false) {
            return null;
        }

        $dateAvailable = $row['date_available'];
        $dbNow = $row['dbnow'];
        if (! is_scalar($dateAvailable) || ! is_scalar($dbNow)) {
            return null;
        }

        return [
            'dateAvailable' => (string) $dateAvailable,
            'dbNow' => (string) $dbNow,
        ];
    }

    /**
     * Number of lounge rows for $categoryId not yet linked into
     * `image_category` -- Ws\PwgImages::upload()'s own "how many photos
     * are still awaiting validation in this category" response field.
     */
    public function countLoungeImagesPendingForCategory(int $categoryId): int
    {
        $loungeTable = Tables::lounge();
        $imageCategoryTable = Tables::imageCategory();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$loungeTable}
                WHERE category_id = {$categoryId}
                AND image_id NOT IN (SELECT image_id FROM {$imageCategoryTable})
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function deleteLoungeUpTo(int $maxImageId): void
    {
        $loungeTable = Tables::lounge();
        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(
                <<<SQL
                DELETE FROM {$loungeTable} WHERE image_id <= ?
                SQL
                ,
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

        $configTable = Tables::config();
        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement(
                <<<SQL
                INSERT IGNORE INTO {$configTable} SET param = ?, value = ?
                SQL
                ,
                ['empty_lounge_running', $encodedLockValue]
            );
        $em->clear();
    }

    public function findLoungeLockValue(): ?string
    {
        $configTable = Tables::config();
        $value = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(
                <<<SQL
                SELECT value FROM {$configTable} WHERE param = "empty_lounge_running"
                SQL
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
        $imageCategoryTable = Tables::imageCategory();
        $imagesCsv = implode(',', $images);
        $categoriesCsv = implode(',', $categories);

        foreach ($this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT
                image_id,
                category_id
            FROM {$imageCategoryTable}
            WHERE image_id IN ({$imagesCsv})
                AND category_id IN ({$categoriesCsv})
            SQL)->fetchAllAssociative() as $row) {
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
        $imageCategoryTable = Tables::imageCategory();
        $categoriesCsv = implode(',', $categories);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT
                    category_id,
                    MAX(`rank`) AS max_rank
                FROM {$imageCategoryTable}
                WHERE `rank` IS NOT NULL
                    AND category_id IN ({$categoriesCsv})
                GROUP BY category_id
                SQL)->fetchAllKeyValue();

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
        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();
        $imagesCsv = implode(',', $images);

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT id
            FROM {$imageCategoryTable}
                INNER JOIN {$imagesTable} ON image_id = id
            WHERE category_id = {$category}
                AND id IN ({$imagesCsv})
                AND (
                    category_id != storage_category_id
                    OR storage_category_id IS NULL
                )
            SQL)->fetchFirstColumn());
    }

    /**
     * @param array<int, int> $imageIds
     */
    public function deleteImageCategoryLinks(array $imageIds, int|string $category): void
    {
        $imageCategoryTable = Tables::imageCategory();
        $imagesCsv = implode(',', $imageIds);

        $em = $this->getEntityManager();
        $em->getConnection()
            ->executeStatement(<<<SQL
                DELETE
                FROM {$imageCategoryTable}
                WHERE category_id = {$category}
                    AND image_id IN ({$imagesCsv})
                SQL);
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

        $imageCategoryTable = Tables::imageCategory();
        $categoryIdsCsv = implode(',', $categoryIds);

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                DELETE
                FROM {$imageCategoryTable}
                WHERE image_id = {$imageId}
                    AND category_id IN ({$categoryIdsCsv})
                SQL);
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
        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();
        $imagesCsv = implode(',', $images);

        $query = <<<SQL
            DELETE {$imageCategoryTable}.*
            FROM {$imageCategoryTable}
                JOIN {$imagesTable} ON image_id=id
            WHERE id IN ({$imagesCsv})
            SQL;

        if ($categories !== []) {
            $categoriesCsv = implode(',', $categories);
            $query .= <<<SQL

                AND category_id NOT IN ({$categoriesCsv})
                SQL;
        }

        $query .= <<<SQL

            AND (storage_category_id IS NULL OR storage_category_id != category_id)
            SQL;

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
        $imagesTable = Tables::images();

        return array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->getEntityManager()->getConnection()->executeQuery(<<<SQL
            SELECT id
            FROM {$imagesTable}
            WHERE md5sum IS NULL
            SQL)->fetchFirstColumn());
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int|string, string> id => path
     */
    public function findPathsForMd5sum(array $ids): array
    {
        $imagesTable = Tables::images();
        $idsCsv = implode(', ', $ids);

        $paths = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT
                    id,
                    path
                FROM {$imagesTable}
                WHERE id IN ({$idsCsv})
                SQL)->fetchAllKeyValue();

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
        $imagesTable = Tables::images();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative(<<<SQL
                SELECT
                    path, file, md5sum,
                    width, height, filesize
                FROM {$imagesTable}
                WHERE id = {$imageId}
                SQL);

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
        $imagesTable = Tables::images();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$imagesTable}
                WHERE {$condition}
                SQL);

        return is_numeric($value) && (int) $value > 0;
    }

    public function countAllImages(): int
    {
        $imagesTable = Tables::images();

        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT COUNT(*) FROM {$imagesTable}
                SQL)->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * Total `filesize` across every image -- Admin\InstallationStats's own
     * "disk_usage" summary figure (original photos only; format-file disk
     * usage is a separate figure, see {@see countAndSumFormats()}).
     */
    public function sumFilesize(): int
    {
        $imagesTable = Tables::images();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT
                    SUM(filesize)
                FROM {$imagesTable}
                SQL);

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
        $imageFormatTable = Tables::imageFormat();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchNumeric(<<<SQL
                SELECT
                    COUNT(*),
                    SUM(filesize)
                FROM {$imageFormatTable}
                SQL);

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
        $imagesTable = Tables::images();

        return array_map(
            static fn (array $row): array => [
                'id' => is_numeric($row['id']) ? (int) $row['id'] : 0,
                'file' => is_string($row['file']) ? $row['file'] : '',
            ],
            $this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT
                        id,
                        file
                    FROM {$imagesTable}
                    SQL)
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
        $imageFormatTable = Tables::imageFormat();

        return array_map(
            static fn (array $row): array => [
                'image_id' => is_numeric($row['image_id']) ? (int) $row['image_id'] : 0,
                'ext' => is_string($row['ext']) ? $row['ext'] : '',
            ],
            $this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT
                        image_id,
                        ext
                    FROM {$imageFormatTable}
                    SQL)
        );
    }

    /**
     * Earliest `date_available` among every image -- Admin\
     * InstallationStats::getInstallationDate()'s own last-resort
     * installation-date candidate.
     */
    public function findEarliestDateAvailable(): ?string
    {
        $imagesTable = Tables::images();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    date_available
                FROM {$imagesTable}
                ORDER BY id ASC
                LIMIT 1
                SQL);

        if ($rows === []) {
            return null;
        }

        $dateAvailable = $rows[0]['date_available'];

        return is_string($dateAvailable) ? $dateAvailable : null;
    }

    public function countImagesInCategories(): int
    {
        $imageCategoryTable = Tables::imageCategory();

        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT COUNT(DISTINCT(image_id)) FROM {$imageCategoryTable}
                SQL)->fetchOne();

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
        $imageCategoryTable = Tables::imageCategory();

        $count = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT COUNT(*) FROM {$imageCategoryTable}
                SQL)->fetchOne();

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
        $imagesTable = Tables::images();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT MIN(date_available) FROM {$imagesTable}
                SQL);

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
        $imagesTable = Tables::images();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchNumeric(<<<SQL
                SELECT MAX(id)+1, COUNT(*) FROM {$imagesTable}
                SQL);

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

        $imagesTable = Tables::images();
        $whereSql = implode(' AND ', $allClauses);

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT id, path, representative_ext, width, height, rotation
                FROM {$imagesTable}
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit}
                SQL);
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

        $imagesTable = Tables::images();
        $idsCsv = implode(',', $imageIds);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    id,
                    IF(name IS NULL, file, name) AS label,
                    filesize,
                    file,
                    path,
                    representative_ext
                FROM {$imagesTable}
                WHERE id IN ({$idsCsv})
                SQL);

        return array_column($rows, null, 'id');
    }

    /**
     * @return list<int>
     */
    public function findLoungedImageIds(): array
    {
        $loungeTable = Tables::lounge();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery(<<<SQL
                    SELECT image_id FROM {$loungeTable}
                    SQL)->fetchFirstColumn()
        );
    }

    /**
     * @param list<int> $loungedIds
     * @return list<int>
     */
    public function findOrphanImageIds(array $loungedIds): array
    {
        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();

        $query = <<<SQL
            SELECT
                id
            FROM {$imagesTable}
                LEFT JOIN {$imageCategoryTable} ON id = image_id
            WHERE category_id IS NULL
            SQL;

        if (count($loungedIds) > 0) {
            $loungedIdsCsv = implode(',', $loungedIds);
            $query .= <<<SQL

                AND id NOT IN ({$loungedIdsCsv})
                SQL;
        }

        $query .= <<<SQL

            ORDER BY id ASC
            SQL;

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

        $imagesTable = Tables::images();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->executeQuery(<<<SQL
                SELECT *
                FROM {$imagesTable}
                WHERE id IN (:ids)
                SQL
                , [
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
        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();
        $idsCsv = implode(',', $idsForSql);

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    DISTINCT(category_id) AS id
                FROM {$imageCategoryTable} AS ic
                    JOIN {$imagesTable} AS i ON i.id = ic.image_id
                WHERE ic.image_id IN ({$idsCsv})
                    AND (
                        ic.category_id != i.storage_category_id
                        OR i.storage_category_id IS NULL
                    )
                SQL);
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
        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    id,
                    file,
                    path,
                    representative_ext,
                    width, height, rotation,
                    name,
                    `rank`
                FROM {$imagesTable}
                    JOIN {$imageCategoryTable} ON image_id = id
                WHERE category_id = {$categoryId}
                ORDER BY `rank`
                SQL);
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
        $imageCategoryTable = Tables::imageCategory();

        return array_values(array_filter(
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT
                        image_id
                    FROM {$imageCategoryTable}
                    WHERE category_id = {$categoryId}
                    ORDER BY `rank` ASC
                    SQL), 'image_id'),
            static fn (mixed $v): bool => is_int($v) || is_string($v)
        ));
    }

    /**
     * Whether $imageId is associated to $categoryId -- Ws\PwgImages::
     * setRank()'s own "is this image even in that category" guard.
     */
    public function isImageInCategory(int $imageId, int $categoryId): bool
    {
        $imageCategoryTable = Tables::imageCategory();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$imageCategoryTable}
                WHERE image_id = {$imageId}
                    AND category_id = {$categoryId}
                SQL);

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
        $imageCategoryTable = Tables::imageCategory();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative(<<<SQL
                SELECT MAX(`rank`) AS max_rank
                FROM {$imageCategoryTable}
                WHERE category_id = {$categoryId}
                SQL);

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
        $imageCategoryTable = Tables::imageCategory();

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                UPDATE {$imageCategoryTable}
                SET `rank` = `rank` + 1
                WHERE category_id = {$categoryId}
                    AND `rank` IS NOT NULL
                    AND `rank` >= {$rank}
                SQL);
    }

    /**
     * Sets `rank` for one (imageId, categoryId) image_category row --
     * Ws\PwgImages::setRank()'s own final write.
     */
    public function updateRankForImageInCategory(int $imageId, int $categoryId, int $rank): void
    {
        $imageCategoryTable = Tables::imageCategory();

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                UPDATE {$imageCategoryTable}
                SET `rank` = {$rank}
                WHERE image_id = {$imageId}
                    AND category_id = {$categoryId}
                SQL);
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
        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();
        $categoriesTable = Tables::categories();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative(<<<SQL
                SELECT category_id, c.uppercats
                FROM {$imagesTable} AS i
                    JOIN {$imageCategoryTable} AS ic ON image_id = i.id
                    JOIN {$categoriesTable} AS c ON category_id = c.id
                ORDER BY i.id DESC
                LIMIT 1
                SQL);

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
        $imagesTable = Tables::images();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                DISTINCT width, height
                FROM {$imagesTable}
                WHERE width IS NOT NULL
                    AND height IS NOT NULL
                SQL);
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
        $imagesTable = Tables::images();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                filesize
                FROM {$imagesTable}
                WHERE filesize IS NOT NULL
                GROUP BY filesize
                SQL);
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
        $imagesTable = Tables::images();
        $imagesCsv = implode(',', $imageIds);

        $query = <<<SQL
            SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation
            FROM {$imagesTable}
            SQL;

        if ($categoryId !== null) {
            $imageCategoryTable = Tables::imageCategory();
            $query .= <<<SQL

                JOIN {$imageCategoryTable} ON id = image_id
                SQL;
        }

        $query .= <<<SQL

            WHERE id IN ({$imagesCsv})
            SQL;

        if ($categoryId !== null) {
            $query .= <<<SQL

                AND category_id = {$categoryId}
                SQL;
        }

        $query .= <<<SQL

            {$orderBySql}
            LIMIT {$limit} OFFSET {$offset}
            SQL;

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
        $imagesTable = Tables::images();
        $idsCsv = implode(',', $imageIds);

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT id, date_creation
                FROM {$imagesTable}
                WHERE id IN ({$idsCsv})
                SQL);
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
        $imagesTable = Tables::images();
        $imagesCsv = implode(',', $imageIds);

        $query = <<<SQL
            SELECT *
            FROM {$imagesTable}
            SQL;

        if ($categoryId !== null) {
            $imageCategoryTable = Tables::imageCategory();
            $query .= <<<SQL

                JOIN {$imageCategoryTable} ON id = image_id
                SQL;
        }

        $query .= <<<SQL

            WHERE id IN ({$imagesCsv})
            SQL;

        if ($categoryId !== null) {
            $query .= <<<SQL

                AND category_id = {$categoryId}
                SQL;
        }

        $query .= <<<SQL

            {$orderBySql}
            LIMIT {$limit} OFFSET {$offset}
            SQL;

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
        $imageCategoryTable = Tables::imageCategory();
        $categoriesTable = Tables::categories();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT category_id, uppercats, dir
                FROM {$imageCategoryTable} AS ic
                    INNER JOIN {$categoriesTable} AS c
                        ON c.id = ic.category_id
                WHERE image_id = {$imageId}
                SQL);
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
        $imageCategoryTable = Tables::imageCategory();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT category_id
                    FROM {$imageCategoryTable}
                    WHERE image_id = {$imageId}
                    SQL), 'category_id')
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
        $imagesTable = Tables::images();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT
                        id
                    FROM {$imagesTable}
                    WHERE date_available < '2022-12-08 00:00:00'
                        AND path LIKE './upload/%'
                    LIMIT {$limit}
                    SQL), 'id')
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
        $imagesTable = Tables::images();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT
                        id
                    FROM {$imagesTable}
                    LIMIT {$limit}
                    SQL), 'id')
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
        $imagesTable = Tables::images();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    path
                FROM {$imagesTable}
                GROUP BY path
                HAVING COUNT(*) > 1
                SQL);

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
        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT
                        image_id
                    FROM {$imageCategoryTable}
                        LEFT JOIN {$imagesTable} ON id = image_id
                    WHERE id IS NULL
                    SQL), 'image_id')
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

        $imageCategoryTable = Tables::imageCategory();
        $idsCsv = implode(',', $imageIds);

        $this->getEntityManager()
            ->getConnection()
            ->executeStatement(<<<SQL
                DELETE
                FROM {$imageCategoryTable}
                WHERE image_id IN ({$idsCsv})
                SQL);
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
        $imagesTable = Tables::images();

        $query = <<<SQL
            SELECT id, file, level
            FROM {$imagesTable}
            WHERE
            SQL;
        if ($imageId > 0) {
            $query .= " id = {$imageId}";
        } else {
            assert($imageFile !== null && $imageFile !== '');
            $query .= " file LIKE '{$imageFile}.%' ESCAPE '/' LIMIT 1";
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
        $imagesTable = Tables::images();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT path
                FROM {$imagesTable}
                WHERE id = {$imageId}
                SQL);

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
        $imagesTable = Tables::images();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative(<<<SQL
                SELECT
                    id,
                    name,
                    representative_ext,
                    path
                FROM {$imagesTable}
                WHERE id = {$imageId}
                SQL);

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
        $imageCategoryTable = Tables::imageCategory();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$imageCategoryTable}
                WHERE category_id = {$categoryId}
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Whether $imageId is reachable via at least one category satisfying
     * $forbiddenCondition -- Controller\PictureController's own "can this
     * image still be accessed differently" fallback check.
     */
    public function isImageAccessibleWithCondition(int $imageId, string $forbiddenCondition): bool
    {
        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT id
                FROM {$imagesTable} INNER JOIN {$imageCategoryTable} ON id=image_id
                WHERE id={$imageId}
                {$forbiddenCondition}
                LIMIT 1
                SQL) !== false;
    }

    /**
     * Every column of $imageId's own row, if it satisfies
     * $permissionCondition -- Ws\PwgImages::getInfo()'s own image lookup.
     *
     * @return ?array<string, mixed>
     */
    public function findRowWithCondition(int $imageId, string $permissionCondition): ?array
    {
        $imagesTable = Tables::images();

        $row = $this->getEntityManager()
            ->getConnection()
            ->fetchAssociative(<<<SQL
                SELECT *
                FROM {$imagesTable}
                WHERE id={$imageId}
                {$permissionCondition}
                LIMIT 1
                SQL);

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
        $imageCategoryTable = Tables::imageCategory();
        $categoriesTable = Tables::categories();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT id, name, permalink, uppercats, global_rank, commentable
                FROM {$imageCategoryTable}
                    INNER JOIN {$categoriesTable} ON category_id = id
                WHERE image_id = {$imageId}
                {$forbiddenCondition}
                SQL);
    }

    /**
     * Whether $imageId belongs to at least one commentable category
     * satisfying $permissionCondition -- Ws\PwgImages::addComment()'s own
     * "can this image receive a comment" check.
     */
    public function isImageCommentableWithCondition(int $imageId, string $permissionCondition): bool
    {
        $imageCategoryTable = Tables::imageCategory();
        $categoriesTable = Tables::categories();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT DISTINCT image_id
                FROM {$imageCategoryTable}
                    INNER JOIN {$categoriesTable} ON category_id=id
                WHERE commentable=1
                    AND image_id={$imageId}
                {$permissionCondition}
                SQL) !== false;
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
        $imageCategoryTable = Tables::imageCategory();
        $categoriesTable = Tables::categories();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT id,uppercats,commentable,visible,status,global_rank
                FROM {$imageCategoryTable}
                    INNER JOIN {$categoriesTable} ON category_id = id
                WHERE image_id = {$imageId}
                {$permissionCondition}
                SQL);
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
        $categoriesTable = Tables::categories();
        $imageCategoryTable = Tables::imageCategory();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT id
                    FROM {$categoriesTable}
                        INNER JOIN {$imageCategoryTable} ON id = category_id
                    WHERE image_id = {$imageId}
                    SQL), 'id')
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
        $imagesTable = Tables::images();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            array_column($this->getEntityManager()
                ->getConnection()
                ->fetchAllAssociative(<<<SQL
                    SELECT
                        id
                    FROM {$imagesTable}
                    WHERE md5sum = '{$md5sum}'
                    SQL), 'id')
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
        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    id
                FROM {$imagesTable} AS i
                    JOIN {$imageCategoryTable} AS ic ON ic.image_id = i.id
                {$permissionCondition}
                    AND author IS NOT NULL
                    LIMIT 1
                SQL);

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
        $categoriesTable = Tables::categories();
        $imageCategoryTable = Tables::imageCategory();

        return $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT id
                FROM {$categoriesTable}
                    INNER JOIN {$imageCategoryTable} ON category_id = id
                WHERE image_id = {$imageId}
                {$permissionCondition}
                LIMIT 1
                SQL) !== false;
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

        $imagesTable = Tables::images();
        $imageCategoryTable = Tables::imageCategory();
        $whereSql = implode("\n    AND ", $whereClauses);

        $sql = <<<SQL
            SELECT SQL_CALC_FOUND_ROWS i.*
            FROM {$imagesTable} i
                INNER JOIN {$imageCategoryTable} ON i.id=image_id
            WHERE {$whereSql}
            GROUP BY i.id
            {$orderByClause}
            LIMIT {$limit}
            OFFSET {$offset}
            SQL;

        $rows = $conn->fetchAllAssociative($sql);

        $totalRaw = $conn->fetchOne(<<<SQL
            SELECT FOUND_ROWS()
            SQL);

        return new PaginatedResult($rows, is_numeric($totalRaw) ? (int) $totalRaw : 0);
    }

    /**
     * Whether an image with this id exists -- Ws\PwgCategories::
     * setRepresentative()'s own existence check.
     */
    public function existsById(int $id): bool
    {
        $imagesTable = Tables::images();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$imagesTable}
                WHERE id = {$id}
                SQL);

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

        $imagesTable = Tables::images();
        $idsCsv = implode(',', $ids);

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->fetchFirstColumn(<<<SQL
                    SELECT id
                    FROM {$imagesTable}
                    WHERE id IN ({$idsCsv})
                    SQL)
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

        $imageCategoryTable = Tables::imageCategory();
        $idsCsv = implode(',', $imageIds);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    image_id,
                    category_id
                FROM {$imageCategoryTable}
                WHERE image_id IN ({$idsCsv})
                    AND {$permissionCondition}
                SQL);

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
        $imagesTable = Tables::images();

        $next = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT IF(MAX(id)+1 IS NULL, 1, MAX(id)+1)
                FROM {$imagesTable}
                SQL);

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

        $imagesTable = Tables::images();
        $categoryIdsCsv = implode(',', $categoryIds);

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT id, path
                FROM {$imagesTable}
                WHERE storage_category_id IN ({$categoryIdsCsv})
                SQL);

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

    /**
     * Distinct image ids linked (via image_category) to any of
     * $categoryIds -- Admin\BatchManager\FilterResolver's own
     * "categories" prefilter.
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function findIdsInCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->createQueryBuilder()
                ->select('DISTINCT(image_id)')
                ->from(Tables::imageCategory())
                ->where('category_id IN (:ids)')
                ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchFirstColumn()
        );
    }

    /**
     * Every image id NOT linked (via image_category) to any of
     * $categoryIds -- an empty $categoryIds returns every image,
     * unfiltered. Admin\BatchManager\FilterResolver's own
     * "no_virtual_album" prefilter (paired with
     * CategoryRepository::findIdsByDirNull() above).
     *
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function findIdsNotInCategories(array $categoryIds): array
    {
        $imagesTable = Tables::images();

        if ($categoryIds === []) {
            return array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                $this->getEntityManager()
                    ->getConnection()
                    ->fetchFirstColumn(<<<SQL
                        SELECT id
                        FROM {$imagesTable}
                        SQL)
            );
        }

        $imageCategoryTable = Tables::imageCategory();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->createQueryBuilder()
                ->select('id')
                ->from($imagesTable)
                ->where('id NOT IN (
                    SELECT DISTINCT(image_id) FROM ' . $imageCategoryTable . ' WHERE category_id IN (:ids)
                )')
                ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER)
                ->executeQuery()
                ->fetchFirstColumn()
        );
    }

    /**
     * Ids of every image added on the same day as the most recently
     * added one -- Admin\BatchManager\FilterResolver's own "last_import"
     * prefilter. "Day" per SqlDialect::getRecentPeriodExpression()'s own
     * DB-specific date arithmetic. Returns [] when there are no images at
     * all.
     *
     * @return list<int>
     */
    public function findIdsAddedSameDayAsLatest(): array
    {
        $imagesTable = Tables::images();
        $conn = $this->getEntityManager()
            ->getConnection();

        $lastDate = $conn->createQueryBuilder()
            ->select('MAX(date_available) AS max_date')
            ->from($imagesTable)
            ->executeQuery()
            ->fetchOne();

        if (! is_string($lastDate) || $lastDate === '') {
            return [];
        }

        $recentPeriodExpr = SqlDialect::getRecentPeriodExpression(1, $lastDate);

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $conn->executeQuery(
                <<<SQL
                    SELECT id FROM {$imagesTable} WHERE date_available BETWEEN {$recentPeriodExpr} AND :last_date
                    SQL
                ,
                [
                    'last_date' => $lastDate,
                ],
            )->fetchFirstColumn()
        );
    }

    /**
     * Ids of every image with no linked tag -- Admin\BatchManager\
     * FilterResolver's own "no_tag" prefilter.
     *
     * @return list<int>
     */
    public function findIdsWithNoTag(): array
    {
        $imagesTable = Tables::images();
        $imageTagTable = Tables::imageTag();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->fetchFirstColumn(<<<SQL
                    SELECT id FROM {$imagesTable} LEFT JOIN {$imageTagTable} ON id = image_id WHERE tag_id IS NULL
                    SQL)
        );
    }

    /**
     * Ids of images that share the same value across every column in
     * $fields with at least one other image -- Admin\BatchManager\
     * FilterResolver's own "duplicates" prefilter. $fields is a
     * caller-validated column-name allowlist (file/md5sum/date_creation/
     * width+height), never raw user input -- same "caller composes
     * trusted fragments" contract as findWithConditionsPaginated() above.
     * GROUP_CONCAT truncates at 1024 chars by default, so a duplicate
     * group larger than ~250 ids silently loses members -- a pre-existing
     * limitation, not introduced here.
     *
     * @param list<string> $fields
     * @return list<int>
     */
    public function findIdsGroupedByDuplicateFields(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $qb = $this->getEntityManager()
            ->getConnection()
            ->createQueryBuilder()
            ->select('GROUP_CONCAT(id) AS ids')
            ->from(Tables::images());

        if (in_array('md5sum', $fields, true)) {
            $qb->where('md5sum IS NOT NULL');
        }

        $qb->groupBy(implode(',', $fields))
            ->having('COUNT(*) > 1');

        $idLists = $qb->executeQuery()
            ->fetchFirstColumn();

        $ids = [];
        foreach ($idLists as $idList) {
            if (! is_string($idList)) {
                continue;
            }
            foreach (explode(',', rtrim($idList, ',')) as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Image ids matching already-built $whereClauses (ANDed together; []
     * means unfiltered), in $orderBySql order -- Admin\BatchManager\
     * FilterResolver's own several id-only prefilters (all_photos/level/
     * dimension/filesize). Same "caller composes trusted fragments"
     * contract as findWithConditionsPaginated() above: $whereClauses are
     * trusted SQL boolean expressions, $params are the bound values
     * referenced by any named placeholders inside them.
     *
     * @param list<string> $whereClauses
     * @param array<string, int|float|string> $params
     * @return list<int>
     */
    public function findIdsWithConditions(array $whereClauses, array $params, string $orderBySql): array
    {
        $imagesTable = Tables::images();
        $whereSql = $whereClauses === [] ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->executeQuery(
                    <<<SQL
                        SELECT id FROM {$imagesTable} {$whereSql} {$orderBySql}
                        SQL
                    ,
                    $params,
                )->fetchFirstColumn()
        );
    }

    /**
     * Distinct image ids linked to any category in $categoryIdsCsv (an
     * already-built comma-separated category id list, or the literal
     * "-1" sentinel meaning "none"), added on/after $recentPeriodExpr (an
     * already-built SqlDialect date expression) -- Filter\FilterService's
     * own recent-content filter computation. Same "caller composes
     * trusted fragments" contract as findWithConditionsPaginated() above.
     *
     * @return list<int>
     */
    public function findIdsVisibleInCategoriesRecentlyAvailable(string $categoryIdsCsv, string $recentPeriodExpr): array
    {
        $imageCategoryTable = Tables::imageCategory();
        $imagesTable = Tables::images();

        return array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $this->getEntityManager()
                ->getConnection()
                ->fetchFirstColumn(<<<SQL
                    SELECT DISTINCT image_id
                    FROM {$imageCategoryTable} INNER JOIN {$imagesTable} ON image_id = id
                    WHERE category_id IN ({$categoryIdsCsv})
                        AND date_available >= {$recentPeriodExpr}
                    SQL)
        );
    }

    /**
     * Most recent `date_available` among every image -- Admin\
     * PiwigoInfosSender's own "much faster" fallback when no sync-added
     * photo exists (see findAddMethodBreakdown() below). Descending
     * counterpart of findEarliestDateAvailable() above.
     */
    public function findMostRecentDateAvailable(): ?string
    {
        $imagesTable = Tables::images();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT date_available
                FROM {$imagesTable}
                ORDER BY id DESC
                LIMIT 1
                SQL);

        return is_string($value) ? $value : null;
    }

    /**
     * Number of images with a non-null `storage_category_id` (added via
     * filesystem sync, not the API) -- Admin\PiwigoInfosSender's own
     * "is it worth running the slower sync-vs-api breakdown query" guard.
     */
    public function countWithStorageCategory(): int
    {
        $imagesTable = Tables::images();

        $value = $this->getEntityManager()
            ->getConnection()
            ->fetchOne(<<<SQL
                SELECT COUNT(*)
                FROM {$imagesTable}
                WHERE storage_category_id IS NOT NULL
                SQL);

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Per add-method (sync = filesystem sync, api = everything else)
     * counts and most recent `date_available` -- Admin\PiwigoInfosSender's
     * own "how were most photos added" telemetry breakdown.
     *
     * @return list<array{add_method: string, last_added_on: ?string, nb_files: int}>
     */
    public function findAddMethodBreakdown(): array
    {
        $imagesTable = Tables::images();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    IF(storage_category_id IS NULL, 'api', 'sync') AS add_method,
                    MAX(date_available) AS last_added_on,
                    COUNT(*) AS nb_files
                FROM {$imagesTable}
                GROUP BY add_method
                SQL);

        return array_map(
            static fn (array $row): array => [
                'add_method' => is_string($row['add_method']) ? $row['add_method'] : '',
                'last_added_on' => is_string($row['last_added_on'] ?? null) ? $row['last_added_on'] : null,
                'nb_files' => is_numeric($row['nb_files']) ? (int) $row['nb_files'] : 0,
            ],
            $rows
        );
    }

    /**
     * Per-extension row count and total filesize across every image --
     * Controller\Admin\IntroSubController's own storage chart and Admin\
     * PiwigoInfosSender's own telemetry breakdown, both keyed by ext.
     *
     * @return list<array{ext: string, counter: int, filesize: int}>
     */
    public function findExtensionBreakdown(): array
    {
        $imagesTable = Tables::images();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    SUBSTRING_INDEX(path, ".", -1) AS ext,
                    COUNT(*) AS counter,
                    SUM(filesize) AS filesize
                FROM {$imagesTable}
                GROUP BY ext
                SQL);

        return array_map(
            static fn (array $row): array => [
                'ext' => is_string($row['ext']) ? $row['ext'] : '',
                'counter' => is_numeric($row['counter']) ? (int) $row['counter'] : 0,
                'filesize' => is_numeric($row['filesize']) ? (int) $row['filesize'] : 0,
            ],
            $rows
        );
    }

    /**
     * Per-extension row count and total filesize across every generated
     * format file -- Controller\Admin\IntroSubController's own storage
     * chart "Formats" bucket. Id-list sibling of findExtensionBreakdown()
     * above, but against `image_format`, not `images`.
     *
     * @return list<array{ext: string, counter: int, filesize: int}>
     */
    public function findFormatExtensionBreakdown(): array
    {
        $imageFormatTable = Tables::imageFormat();

        $rows = $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative(<<<SQL
                SELECT
                    ext,
                    COUNT(*) AS counter,
                    SUM(filesize) AS filesize
                FROM {$imageFormatTable}
                GROUP BY ext
                SQL);

        return array_map(
            static fn (array $row): array => [
                'ext' => is_string($row['ext']) ? $row['ext'] : '',
                'counter' => is_numeric($row['counter']) ? (int) $row['counter'] : 0,
                'filesize' => is_numeric($row['filesize']) ? (int) $row['filesize'] : 0,
            ],
            $rows
        );
    }
}
