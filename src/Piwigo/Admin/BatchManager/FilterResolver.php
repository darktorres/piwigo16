<?php

declare(strict_types=1);

namespace Piwigo\Admin\BatchManager;

use Piwigo\Admin\BatchManager\Projection\DimensionFilter;
use Piwigo\Admin\BatchManager\Projection\DuplicateFieldFlags;
use Piwigo\Admin\BatchManager\Projection\FilesizeFilter;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Image\ImageDuplicateField;
use Piwigo\Image\ImageService;
use Piwigo\Users\UserService;

/**
 * Resolves the batch manager's session-held filter criteria
 * ($_SESSION['bulk_manager_filter']) into concrete photo id lists -- the
 * only data-access concern in admin/batch_manager.php and its 2 tab
 * siblings; the rest of those files is $_POST/$_GET/$_SESSION parsing and
 * template glue, kept inline.
 *
 * This class only orchestrates: it validates/builds the caller-composed
 * WHERE fragments each filter needs, then delegates execution to
 * ImageService/CategoryService/CaddieRepository/UserService.
 *
 * Deliberately does NOT re-implement filters that already have a correct,
 * tested implementation elsewhere (Piwigo\Image\ImageService::getOrphans()/
 * getPhotosNoMd5sum(), get_subcat_ids(), Piwigo\Tag\TagService::
 * getImageIdsForTags(), get_quick_search_results_no_cache()) -- those stay
 * called directly from the admin page glue.
 *
 * The 3 genuinely fixed-shape filter dimensions this class reads
 * (duplicates flags, dimension bounds, filesize bounds) are
 * {@see DuplicateFieldFlags}/{@see DimensionFilter}/{@see FilesizeFilter}
 * value objects, built by the one real caller
 * (`BatchManagerSubController::computeCurrentSet()`) from the raw,
 * still-untyped `$_SESSION['bulk_manager_filter']` session bag -- that bag
 * itself stays a raw array (it's genuinely plugin-extensible, via
 * BatchManagerRegisterFilters/BatchManagerUrlFilter), this class just no
 * longer needs to see more of it than these 3 fixed shapes.
 */
final readonly class FilterResolver
{
    public function __construct(
        private ImageService $imageService,
        private CategoryService $categoryService,
        private CaddieRepository $caddieRepo,
        private UserService $userService,
    ) {}

    /**
     * The 7 prefilters that were bare inline SQL in batch_manager.php.
     * Returns null for any other prefilter value (including the 3 handled
     * by an existing free function or a plugin hook) -- the caller decides
     * what null means for its own prefilter value.
     *
     * $isOnlyFilterActive replaces the old `count($bulkFilter) === 1`
     * check -- "all_photos" only runs when the prefilter is the sole
     * active session filter key, a fact only the caller (which still holds
     * the raw session bag) can determine.
     *
     * @return list<int>|null
     */
    public function resolvePrefilter(string $prefilter, DuplicateFieldFlags $duplicateFlags, bool $isOnlyFilterActive, UserId $userId, string $orderBy): ?array
    {
        return match ($prefilter) {
            'caddie' => $this->caddiePhotoIds($userId),
            'favorites' => $this->favoritePhotoIds($userId),
            'last_import' => $this->lastImportPhotoIds(),
            'no_virtual_album' => $this->noVirtualAlbumPhotoIds(),
            'no_tag' => $this->noTagPhotoIds(),
            'duplicates' => $this->duplicatePhotoIds($this->duplicateFieldsFromFilter($duplicateFlags)),
            'all_photos' => $isOnlyFilterActive ? $this->allPhotoIds($orderBy) : null,
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    private function caddiePhotoIds(UserId $userId): array
    {
        return $this->caddieRepo->findElementIdsForUser($userId->value);
    }

    /**
     * @return list<int>
     */
    private function favoritePhotoIds(UserId $userId): array
    {
        return $this->userService->getFavoriteImageIds($userId);
    }

    /**
     * Images added on the same day as the most recently added image --
     * "day" per \Piwigo\Db\SqlDialect::getRecentPeriodExpression()'s own DB-specific
     * date arithmetic (kept as-is: not parameterizable SQL text, and
     * already correct/tested).
     *
     * @return list<int>
     */
    private function lastImportPhotoIds(): array
    {
        return $this->imageService->getIdsAddedSameDayAsLatest();
    }

    /**
     * Images not linked to any virtual category (a category with no real
     * filesystem directory).
     *
     * @return list<int>
     */
    private function noVirtualAlbumPhotoIds(): array
    {
        $virtualCategoryIds = $this->categoryService->getIdsByDirNull(true);

        return $this->imageService->getIdsNotInCategories($virtualCategoryIds);
    }

    /**
     * @return list<int>
     */
    private function noTagPhotoIds(): array
    {
        return $this->imageService->getIdsWithNoTag();
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
     * Known limitation (pre-existing, not fixed here): GROUP_CONCAT
     * truncates at 1024 chars by default, so a duplicate group larger
     * than ~250 ids silently loses members -- matches the legacy code's
     * own equivalent limitation.
     *
     * @param list<ImageDuplicateField> $fields
     * @return list<int>
     */
    public function duplicatePhotoIds(array $fields): array
    {
        return $this->imageService->getIdsGroupedByDuplicateFields($fields);
    }

    /**
     * Also called directly by admin/batch_manager.php's own glue code: the
     * resolved field list is needed a second time by
     * admin/batch_manager_global.php (its duplicates-mode thumbnail
     * ordering), so exposing this avoids computing it twice from two
     * different places.
     *
     * @return list<ImageDuplicateField>
     */
    public function duplicateFieldsFromFilter(DuplicateFieldFlags $flags): array
    {
        $fields = [];
        if ($flags->filename) {
            $fields[] = ImageDuplicateField::File;
        }
        if ($flags->checksum) {
            $fields[] = ImageDuplicateField::Md5sum;
        }
        if ($flags->date) {
            $fields[] = ImageDuplicateField::DateCreation;
        }
        if ($flags->dimensions) {
            $fields[] = ImageDuplicateField::Width;
            $fields[] = ImageDuplicateField::Height;
        }

        return $fields;
    }

    /**
     * @return list<int>
     */
    private function allPhotoIds(string $orderBy): array
    {
        return $this->imageService->getIdsWithConditions([], [], $orderBy);
    }

    public function categoryExists(CategoryId $categoryId): bool
    {
        return $this->categoryService->existsById($categoryId->value);
    }

    /**
     * @param list<int> $categoryIds
     * @return list<int>
     */
    public function categoryImageIds(array $categoryIds): array
    {
        return $this->imageService->getIdsInCategories($categoryIds);
    }

    /**
     * @return list<int>
     */
    public function levelPhotoIds(int $level, bool $includeLower, string $orderBy): array
    {
        $operator = $includeLower ? '<=' : '=';

        return $this->imageService->getIdsWithConditions(
            ["level {$operator} :level"],
            [
                'level' => $level,
            ],
            $orderBy
        );
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
     * @return list<int>|null
     */
    public function dimensionPhotoIds(DimensionFilter $dimension, string $orderBy): ?array
    {
        if ($dimension->isEmpty()) {
            return null;
        }

        $where = [];
        $params = [];

        if ($dimension->minWidth !== null) {
            $where[] = 'width >= :min_width';
            $params['min_width'] = $dimension->minWidth;
        }
        if ($dimension->maxWidth !== null) {
            $where[] = 'width <= :max_width';
            $params['max_width'] = $dimension->maxWidth;
        }
        if ($dimension->minHeight !== null) {
            $where[] = 'height >= :min_height';
            $params['min_height'] = $dimension->minHeight;
        }
        if ($dimension->maxHeight !== null) {
            $where[] = 'height <= :max_height';
            $params['max_height'] = $dimension->maxHeight;
        }
        // pgsql support pass: real bug found live -- width/height are
        // both plain integer columns. MySQL's `/` always computes in
        // DECIMAL/floating context regardless of operand types, but
        // PostgreSQL's `/` on two integer operands truncates to an
        // integer (same real bug already fixed in SearchService's own
        // ratio filters -- see that call site's docblock for the
        // live-confirmed 200/150 example). Beyond the wrong ratio
        // value, a truncated-to-integer left side also makes Postgres
        // infer the bound :min_ratio/:max_ratio parameter's type as
        // integer too, rejecting a genuinely fractional ratio outright.
        // `width * 1.0` forces decimal-context arithmetic on both
        // platforms without needing a DQL/SQL CAST. NULLIF(height, 0)
        // additionally guards a genuinely zero height (a real, if
        // degenerate, row) -- MySQL's `/` silently returns NULL for a
        // zero divisor, but Postgres raises a real "division by zero"
        // error instead, confirmed live via SearchService's own identical
        // ratio-bucket clause.
        if ($dimension->minRatio !== null) {
            $where[] = 'width * 1.0 / NULLIF(height, 0) >= :min_ratio';
            $params['min_ratio'] = $dimension->minRatio;
        }
        if ($dimension->maxRatio !== null) {
            // max_ratio is a floor value, so must be a bit increased.
            $where[] = 'width * 1.0 / NULLIF(height, 0) < :max_ratio';
            $params['max_ratio'] = $dimension->maxRatio + 0.01;
        }

        return $this->imageService->getIdsWithConditions($where, $params, $orderBy);
    }

    /**
     * @return list<int>|null
     */
    public function filesizePhotoIds(FilesizeFilter $filesize, string $orderBy): ?array
    {
        if ($filesize->isEmpty()) {
            return null;
        }

        $where = [];
        $params = [];

        if ($filesize->min !== null) {
            // to counter the effect of converting kB to mB and rounding, go
            // slightly lower for the minimum value.
            //
            // pgsql support pass: real bug found live -- `filesize` is a
            // genuine integer column (mediumint), and this fractional
            // threshold used to bind as an untyped parameter. MySQL
            // tolerates comparing an integer column against a fractional
            // value (compares numerically, no cast error); PostgreSQL
            // infers an unbound parameter's type from how it's used --
            // compared against an integer column via `>=` -- and rejects
            // a non-integer value outright ("invalid input syntax for
            // type integer: '-102.4'"). floor() only ever WIDENS this
            // already-deliberate safety margin (never narrows it), so
            // this changes no real match outcome for a whole-number
            // filesize value.
            $where[] = 'filesize >= :min_filesize';
            $params['min_filesize'] = (int) floor(($filesize->min - 0.1) * 1024.0);
        }
        if ($filesize->max !== null) {
            // to counter the effect of converting kB to mB and rounding, go
            // slightly higher for the maximum value. Same real pgsql bug
            // as min_filesize above -- ceil() only ever widens the margin.
            $where[] = 'filesize <= :max_filesize';
            $params['max_filesize'] = (int) ceil(($filesize->max + 0.1) * 1024.0);
        }

        return $this->imageService->getIdsWithConditions($where, $params, $orderBy);
    }
}
