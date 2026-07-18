<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Piwigo\Db\Tables;

/**
 * Resolves the batch manager's session-held filter criteria
 * ($_SESSION['bulk_manager_filter']) into concrete photo id lists --
 * extracted from admin/batch_manager.php's own ~320-line inline block
 * (the file's only real "data access" concern; everything else in that
 * file and its 2 tab siblings is $_POST/$_GET/$_SESSION parsing and
 * template glue, kept inline per this phase's established "extract data
 * access, keep page/template glue inline" pattern).
 *
 * Deliberately does NOT re-implement filters that already have a correct,
 * tested implementation elsewhere (Piwigo\Image\ImageService::getOrphans()/
 * getPhotosNoMd5sum(), get_subcat_ids(), Piwigo\Tag\TagService::
 * getImageIdsForTags(), get_quick_search_results_no_cache()) -- those stay
 * called directly from the admin page glue, same as this phase's
 * ExtensionLifecycle/ExtensionUpdateChecker still calling
 * pwg_activity()/conf_update_param().
 */
final readonly class FilterResolver
{
    public function __construct(
        private Connection $conn,
    ) {}

    /**
     * The 7 prefilters that were bare inline SQL in batch_manager.php.
     * Returns null for any other prefilter value (including the 3 handled
     * by an existing free function or a plugin hook) -- the caller decides
     * what null means for its own prefilter value.
     *
     * @param array<string, mixed> $bulkFilter
     * @return list<int>|null
     */
    public function resolvePrefilter(string $prefilter, array $bulkFilter, int $userId, string $orderBy): ?array
    {
        return match ($prefilter) {
            'caddie' => $this->caddiePhotoIds($userId),
            'favorites' => $this->favoritePhotoIds($userId),
            'last_import' => $this->lastImportPhotoIds(),
            'no_virtual_album' => $this->noVirtualAlbumPhotoIds(),
            'no_tag' => $this->noTagPhotoIds(),
            'duplicates' => $this->duplicatePhotoIds($this->duplicateFieldsFromFilter($bulkFilter)),
            'all_photos' => count($bulkFilter) === 1 ? $this->allPhotoIds($orderBy) : null,
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    private function caddiePhotoIds(int $userId): array
    {
        return $this->fetchIntColumn(
            $this->conn->createQueryBuilder()
                ->select('element_id')
                ->from(Tables::caddie())
                ->where('user_id = :user_id')
                ->setParameter('user_id', $userId)
        );
    }

    /**
     * @return list<int>
     */
    private function favoritePhotoIds(int $userId): array
    {
        return $this->fetchIntColumn(
            $this->conn->createQueryBuilder()
                ->select('image_id')
                ->from(Tables::favorites())
                ->where('user_id = :user_id')
                ->setParameter('user_id', $userId)
        );
    }

    /**
     * Images added on the same day as the most recently added image --
     * "day" per \Piwigo\Db\MysqliDb::getRecentPeriodExpression()'s own DB-specific
     * date arithmetic (kept as-is: not parameterizable SQL text, and
     * already correct/tested).
     *
     * @return list<int>
     */
    private function lastImportPhotoIds(): array
    {
        $lastDate = $this->conn->createQueryBuilder()
            ->select('MAX(date_available) AS max_date')
            ->from(Tables::images())
            ->executeQuery()
            ->fetchOne();

        if (! is_string($lastDate) || $lastDate === '') {
            return [];
        }

        $sql = 'SELECT id FROM ' . Tables::images()
            . ' WHERE date_available BETWEEN ' . \Piwigo\Db\MysqliDb::getRecentPeriodExpression(1, $lastDate) . ' AND :last_date';

        return $this->fetchIntColumnSql($sql, [
            'last_date' => $lastDate,
        ]);
    }

    /**
     * Images not linked to any virtual category (a category with no real
     * filesystem directory).
     *
     * @return list<int>
     */
    private function noVirtualAlbumPhotoIds(): array
    {
        $allImageIds = $this->fetchIntColumn(
            $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::images())
        );

        $virtualCategoryIds = $this->fetchIntColumn(
            $this->conn->createQueryBuilder()
                ->select('id')
                ->from(Tables::categories())
                ->where('dir IS NULL')
        );

        if ($virtualCategoryIds === []) {
            return $allImageIds;
        }

        $linkedToVirtual = $this->fetchIntColumn(
            $this->conn->createQueryBuilder()
                ->select('DISTINCT(image_id)')
                ->from(Tables::imageCategory())
                ->where('category_id IN (:ids)')
                ->setParameter('ids', $virtualCategoryIds, ArrayParameterType::INTEGER)
        );

        return array_values(array_diff($allImageIds, $linkedToVirtual));
    }

    /**
     * @return list<int>
     */
    private function noTagPhotoIds(): array
    {
        $sql = 'SELECT id FROM ' . Tables::images()
            . ' LEFT JOIN ' . Tables::imageTag() . ' ON id = image_id'
            . ' WHERE tag_id IS NULL';

        return $this->fetchIntColumnSql($sql, []);
    }

    /**
     * $fields is the caller-resolved list of column names to group
     * duplicates by (file/md5sum/date_creation/width+height) -- kept as a
     * caller-supplied parameter rather than reading
     * $bulkFilter['duplicates_*'] itself, so admin/batch_manager_global.php's
     * own later use of this exact field list (its duplicates ORDER BY) can
     * share the same resolved list without re-deriving it. Column names
     * come from a fixed internal allowlist (duplicateFieldsFromFilter()),
     * never from raw user input.
     *
     * TODO (pre-existing, not fixed here): GROUP_CONCAT truncates at 1024
     * chars by default, so a duplicate group larger than ~250 ids silently
     * loses members -- matches the legacy code's own TODO comment.
     *
     * @param list<string> $fields
     * @return list<int>
     */
    public function duplicatePhotoIds(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        $qb = $this->conn->createQueryBuilder()
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
     * Also called directly by admin/batch_manager.php's own glue code: the
     * resolved field list is needed a second time by
     * admin/batch_manager_global.php (its duplicates-mode thumbnail
     * ordering), so exposing this avoids computing it twice from two
     * different places.
     *
     * @param array<string, mixed> $bulkFilter
     * @return list<string>
     */
    public function duplicateFieldsFromFilter(array $bulkFilter): array
    {
        $fields = [];
        if (isset($bulkFilter['duplicates_filename'])) {
            $fields[] = 'file';
        }
        if (isset($bulkFilter['duplicates_checksum'])) {
            $fields[] = 'md5sum';
        }
        if (isset($bulkFilter['duplicates_date'])) {
            $fields[] = 'date_creation';
        }
        if (isset($bulkFilter['duplicates_dimensions'])) {
            $fields[] = 'width';
            $fields[] = 'height';
        }

        return $fields;
    }

    /**
     * @return list<int>
     */
    private function allPhotoIds(string $orderBy): array
    {
        $sql = 'SELECT id FROM ' . Tables::images() . ' ' . $orderBy;

        return $this->fetchIntColumnSql($sql, []);
    }

    public function categoryExists(int $categoryId): bool
    {
        $count = $this->conn->createQueryBuilder()
            ->select('COUNT(*)')
            ->from(Tables::categories())
            ->where('id = :id')
            ->setParameter('id', $categoryId)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) && (int) $count > 0;
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function categoryImageIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return $this->fetchIntColumn(
            $this->conn->createQueryBuilder()
                ->select('DISTINCT(image_id)')
                ->from(Tables::imageCategory())
                ->where('category_id IN (:ids)')
                ->setParameter('ids', $categoryIds, ArrayParameterType::INTEGER)
        );
    }

    /**
     * @return list<int>
     */
    public function levelPhotoIds(int $level, bool $includeLower, string $orderBy): array
    {
        $operator = $includeLower ? '<=' : '=';
        $sql = 'SELECT id FROM ' . Tables::images()
            . ' WHERE level ' . $operator . ' :level ' . $orderBy;

        return $this->fetchIntColumnSql($sql, [
            'level' => $level,
        ]);
    }

    /**
     * Real, verified bug found (not fixed on the legacy file, only here):
     * a crafted `?filter=dimension-<garbage>` URL token can leave
     * $bulkFilter['dimension'] set to an empty array (see
     * admin/batch_manager.php's own URL-filter parsing: it unconditionally
     * writes back $url_filter['dimension'] = $dimension after the loop,
     * even when nothing inside the loop validated). The legacy inline SQL
     * built `WHERE ` . implode(' AND ', []) . ' ' . $order_by in that case
     * -- a malformed query with an empty WHERE clause, a real SQL syntax
     * error triggerable by an authenticated admin's URL. Returning null
     * here instead (skip this filter dimension entirely) avoids ever
     * building that malformed query.
     *
     * @param array<string, mixed> $dimension
     * @return list<int>|null
     */
    public function dimensionPhotoIds(array $dimension, string $orderBy): ?array
    {
        $where = [];
        $params = [];

        if (isset($dimension['min_width']) && is_numeric($dimension['min_width'])) {
            $where[] = 'width >= :min_width';
            $params['min_width'] = (int) $dimension['min_width'];
        }
        if (isset($dimension['max_width']) && is_numeric($dimension['max_width'])) {
            $where[] = 'width <= :max_width';
            $params['max_width'] = (int) $dimension['max_width'];
        }
        if (isset($dimension['min_height']) && is_numeric($dimension['min_height'])) {
            $where[] = 'height >= :min_height';
            $params['min_height'] = (int) $dimension['min_height'];
        }
        if (isset($dimension['max_height']) && is_numeric($dimension['max_height'])) {
            $where[] = 'height <= :max_height';
            $params['max_height'] = (int) $dimension['max_height'];
        }
        if (isset($dimension['min_ratio']) && is_numeric($dimension['min_ratio'])) {
            $where[] = 'width/height >= :min_ratio';
            $params['min_ratio'] = (float) $dimension['min_ratio'];
        }
        if (isset($dimension['max_ratio']) && is_numeric($dimension['max_ratio'])) {
            // max_ratio is a floor value, so must be a bit increased.
            $where[] = 'width/height < :max_ratio';
            $params['max_ratio'] = (float) $dimension['max_ratio'] + 0.01;
        }

        if ($where === []) {
            return null;
        }

        $sql = 'SELECT id FROM ' . Tables::images()
            . ' WHERE ' . implode(' AND ', $where) . ' ' . $orderBy;

        return $this->fetchIntColumnSql($sql, $params);
    }

    /**
     * @param array<string, mixed> $filesize
     * @return list<int>|null
     */
    public function filesizePhotoIds(array $filesize, string $orderBy): ?array
    {
        $where = [];
        $params = [];

        if (isset($filesize['min']) && is_numeric($filesize['min'])) {
            // to counter the effect of converting kB to mB and rounding, go
            // slightly lower for the minimum value.
            $where[] = 'filesize >= :min_filesize';
            $params['min_filesize'] = ((float) $filesize['min'] - 0.1) * 1024;
        }
        if (isset($filesize['max']) && is_numeric($filesize['max'])) {
            // to counter the effect of converting kB to mB and rounding, go
            // slightly higher for the maximum value.
            $where[] = 'filesize <= :max_filesize';
            $params['max_filesize'] = ((float) $filesize['max'] + 0.1) * 1024;
        }

        if ($where === []) {
            return null;
        }

        $sql = 'SELECT id FROM ' . Tables::images()
            . ' WHERE ' . implode(' AND ', $where) . ' ' . $orderBy;

        return $this->fetchIntColumnSql($sql, $params);
    }

    /**
     * @return list<int>
     */
    private function fetchIntColumn(QueryBuilder $qb): array
    {
        $values = $qb->executeQuery()
            ->fetchFirstColumn();

        return $this->toIntList($values);
    }

    /**
     * @param array<string, int|float|string> $params
     * @return list<int>
     */
    private function fetchIntColumnSql(string $sql, array $params): array
    {
        $values = $this->conn->executeQuery($sql, $params)
            ->fetchFirstColumn();

        return $this->toIntList($values);
    }

    /**
     * @param list<mixed> $values
     * @return list<int>
     */
    private function toIntList(array $values): array
    {
        $ints = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $ints[] = (int) $value;
            }
        }

        return $ints;
    }
}
