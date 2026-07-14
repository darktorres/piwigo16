<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Permission\PermissionService;

/**
 * Category domain business logic, ported from
 * `include/functions_category.inc.php`'s 17 functions. Functions with real
 * `$page`/`$template` coupling (`get_categories_menu()`,
 * `display_select_categories()`, `display_select_cat_wrapper()`,
 * `get_related_categories_menu()`'s URL-building half) stay as free-function
 * delegates that call into this service for the data-access/computation
 * half only -- same "extract data access, keep page/template glue inline"
 * split as every prior phase's page-coupled functions.
 */
final class CategoryService
{
    public function __construct(
        private readonly CategoryRepository $repo,
        private readonly PermissionService $permissionService,
    ) {}

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareByGlobalRank(array $a, array $b): int
    {
        $aRank = $a['global_rank'];
        $bRank = $b['global_rank'];

        return strnatcasecmp(
            is_scalar($aRank) ? (string) $aRank : '',
            is_scalar($bRank) ? (string) $bRank : ''
        );
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function compareByRank(array $a, array $b): int
    {
        $aRank = $a['rank'];
        $bRank = $b['rank'];

        return (is_numeric($aRank) ? (int) $aRank : 0) - (is_numeric($bRank) ? (int) $bRank : 0);
    }

    /**
     * `get_categories_menu()`'s (`include/functions_category.inc.php`) menu
     * filter, extracted as a pure function so it's testable without that
     * free function's `$page`/`$user`/`$filter`/`$conf` global dependencies
     * -- P23 batch 3b replaced `findMenuCategories()`'s SQL `WHERE` (a
     * structural `id_uppercat` filter, or `PermissionService::
     * getSqlConditionFandF()`'s `visible_categories` condition) with this
     * PHP-side equivalent, applied to `CategoryTreeCache`'s cached,
     * permission-filtered row set.
     *
     * @param array<int, array<string, mixed>> $allRows keyed by category id,
     *   already permission-filtered (CategoryTreeCache::getForUser())
     * @param array<string, mixed>|null $categoryPage the currently-viewed
     *   category ($page['category']), if any
     * @return array<int, array<string, mixed>>
     */
    public static function filterMenuRows(
        array $allRows,
        ?array $categoryPage,
        bool $expand,
        bool $filterEnabled,
        string $visibleCategoriesCsv
    ): array {
        // Always expand when a filter is active -- matches the original
        // SQL's own branch condition exactly.
        if (! $expand && ! $filterEnabled) {
            $uppercatsRaw = $categoryPage['uppercats'] ?? null;
            $uppercatIds = $categoryPage !== null && is_scalar($uppercatsRaw) && $uppercatsRaw !== ''
                ? array_map(intval(...), explode(',', (string) $uppercatsRaw))
                : [];

            return array_filter(
                $allRows,
                static fn (array $row): bool => ($row['id_uppercat'] ?? null) === null
                    || in_array($row['id_uppercat'], $uppercatIds, true)
            );
        }

        if ($visibleCategoriesCsv === '') {
            // Matches getSqlConditionFandF()'s own fallthrough: no active
            // filter means "everything visible" (the original's `1 = 1`).
            return $allRows;
        }

        $visibleIds = array_map(intval(...), explode(',', $visibleCategoriesCsv));

        return array_filter(
            $allRows,
            static fn (array $row): bool => in_array($row['id'] ?? null, $visibleIds, true)
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCategoryInfo(int $id): ?array
    {
        $cat = $this->repo->findById($id);
        if ($cat === null) {
            return null;
        }

        foreach ($cat as $k => $v) {
            if ($v === 'true' || $v === 'false') {
                $cat[$k] = get_boolean($v);
            }
        }

        $uppercats = $cat['uppercats'];
        $upperIds = explode(',', is_scalar($uppercats) ? (string) $uppercats : '');
        if (count($upperIds) === 1) {
            $cat['upper_names'] = [
                [
                    'id' => $cat['id'],
                    'name' => $cat['name'],
                    'permalink' => $cat['permalink'],
                ],
            ];
        } else {
            $names = $this->repo->findNamesByIds(array_map(intval(...), $upperIds));

            $cat['upper_names'] = [];
            foreach ($upperIds as $upperId) {
                $upperIdInt = (int) $upperId;
                if (isset($names[$upperIdInt])) {
                    $cat['upper_names'][] = $names[$upperIdInt];
                }
            }
        }

        return $cat;
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: bool}>
     */
    public function getPreferredImageOrders(): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $orders = trigger_change('get_category_preferred_image_orders', [
            [l10n('Default'), '', true],
            [l10n('Photo title, A &rarr; Z'), 'name ASC', true],
            [l10n('Photo title, Z &rarr; A'), 'name DESC', true],
            [l10n('Date created, new &rarr; old'), 'date_creation DESC', true],
            [l10n('Date created, old &rarr; new'), 'date_creation ASC', true],
            [l10n('Date posted, new &rarr; old'), 'date_available DESC', true],
            [l10n('Date posted, old &rarr; new'), 'date_available ASC', true],
            [l10n('Rating score, high &rarr; low'), 'rating_score DESC', $conf['rate']],
            [l10n('Rating score, low &rarr; high'), 'rating_score ASC', $conf['rate']],
            [l10n('Visits, high &rarr; low'), 'hit DESC', true],
            [l10n('Visits, low &rarr; high'), 'hit ASC', true],
            [l10n('Permissions'), 'level DESC', is_admin()],
        ]);

        if (! is_array($orders)) {
            return [];
        }

        $result = [];
        foreach ($orders as $order) {
            if (! is_array($order) || ! isset($order[0], $order[1], $order[2]) || ! is_string($order[0]) || ! is_string($order[1])) {
                continue;
            }

            $visible = $order[2];
            $visible = is_scalar($visible) ? (bool) $visible : true;
            $result[] = [$order[0], $order[1], $visible];
        }

        return $result;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function getSubcategoryIds(array $ids): array
    {
        return $this->repo->findSubcategoryIds($ids);
    }

    /**
     * @param  list<string>  $permalinks
     */
    public function findCategoryIdFromPermalinks(array $permalinks, ?int &$idx): ?int
    {
        $permaHash = $this->repo->findPermalinkMatches($permalinks);

        if ($permaHash === []) {
            return null;
        }

        for ($i = count($permalinks) - 1; $i >= 0; $i--) {
            if (! isset($permaHash[$permalinks[$i]])) {
                continue;
            }

            $idx = $i;
            $match = $permaHash[$permalinks[$i]];
            $catId = $match['id'];
            if ((bool) $match['is_old']) {
                $this->repo->touchOldPermalinkHit($permalinks[$i], $catId);
            }

            return $catId;
        }

        return null;
    }

    public static function getDisplayImagesCount(
        int $catNbImages,
        int $catCountImages,
        int $catCountCategories,
        bool $shortMessage = true,
        string $separator = '\n'
    ): string {
        $displayText = '';

        if ($catCountImages > 0) {
            if ($catNbImages > 0 && $catNbImages < $catCountImages) {
                $displayText .= self::getDisplayImagesCount($catNbImages, $catNbImages, 0, $shortMessage, $separator) . $separator;
                $catCountImages -= $catNbImages;
                $catNbImages = 0;
            }

            $displayText .= l10n_dec('%d photo', '%d photos', $catCountImages);

            if ($catCountCategories === 0 || $catNbImages === $catCountImages) {
                if (! $shortMessage) {
                    $displayText .= ' ' . l10n('in this album');
                }
            } else {
                $displayText .= ' ' . l10n_dec('in %d sub-album', 'in %d sub-albums', $catCountCategories);
            }
        }

        return $displayText;
    }

    /**
     * @param  array<string, mixed>  $category  (at least id, uppercats, count_images)
     */
    public function getRandomImageInCategory(array $category, bool $recursive = true): ?int
    {
        $countImages = $category['count_images'] ?? null;
        if (! is_numeric($countImages) || (int) $countImages <= 0) {
            return null;
        }

        $catId = $category['id'];
        $catId = is_numeric($catId) ? (int) $catId : 0;
        $uppercats = $category['uppercats'];
        $uppercats = is_string($uppercats) ? $uppercats : '';

        $permissionCondition = $this->permissionService->getSqlConditionFandF([
            'forbidden_categories' => 'c.id',
            'visible_categories' => 'c.id',
            'visible_images' => 'image_id',
        ], "\n  AND");

        return $this->repo->findRandomImageId($catId, $uppercats, $recursive, $permissionCondition);
    }

    /**
     * @param  array<string, mixed>  $userdata
     * @return array<int|string, array<string, mixed>>
     */
    public function getComputedCategories(array &$userdata, ?int $filterDays = null): array
    {
        $level = $userdata['level'];
        $level = is_numeric($level) ? (int) $level : 0;

        $forbiddenCategories = $userdata['forbidden_categories'];
        $forbiddenCategoriesCsv = is_string($forbiddenCategories) ? $forbiddenCategories : '';

        $rows = $this->repo->findComputedCategoriesRollup($level, $filterDays, $forbiddenCategoriesCsv);

        $userdata['last_photo_date'] = null;
        $cats = [];
        foreach ($rows as $row) {
            $catId = is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0;
            $idUppercatRaw = $row['id_uppercat'];
            $idUppercat = is_numeric($idUppercatRaw) ? (int) $idUppercatRaw : null;
            $nbImages = is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0;
            $dateLast = $row['date_last'];

            $row['cat_id'] = $catId;
            $row['id_uppercat'] = $idUppercat;
            $row['nb_images'] = $nbImages;
            $row['user_id'] = $userdata['id'];
            $row['nb_categories'] = 0;
            $row['count_categories'] = 0;
            $row['count_images'] = $nbImages;
            $row['max_date_last'] = $dateLast;
            if ($dateLast !== null && ($userdata['last_photo_date'] === null || $dateLast > $userdata['last_photo_date'])) {
                $userdata['last_photo_date'] = $dateLast;
            }

            $cats[$catId] = $row;
        }

        uasort($cats, self::compareByGlobalRank(...));

        foreach ($cats as $cat) {
            $idUppercat = $cat['id_uppercat'];
            if (! is_int($idUppercat)) {
                continue;
            }

            if (! isset($cats[$idUppercat])) {
                continue;
            }

            $parent = &$cats[$idUppercat];
            $parent['nb_categories']++;

            $nbImages = $cat['nb_images'];

            do {
                $parent['count_images'] += $nbImages;
                $parent['count_categories']++;

                $parentMaxDateLast = $parent['max_date_last'] ?? null;
                if ($parentMaxDateLast === null || $parentMaxDateLast === '' || $parentMaxDateLast < $cat['date_last']) {
                    $parent['max_date_last'] = $cat['date_last'];
                }

                $parentIdUppercat = $parent['id_uppercat'];
                if (! is_int($parentIdUppercat)) {
                    break;
                }

                $parent = &$cats[$parentIdUppercat];
            } while (true);
            unset($parent);
        }

        if ($filterDays !== null) {
            foreach ($cats as $category) {
                $categoryMaxDateLast = $category['max_date_last'] ?? null;
                if ($categoryMaxDateLast === null || $categoryMaxDateLast === '') {
                    self::removeComputedCategory($cats, $category);
                }
            }
        }

        return $cats;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $cats
     * @param  array<string, mixed>  $cat  category to remove
     */
    public static function removeComputedCategory(array &$cats, array $cat): void
    {
        $idUppercat = $cat['id_uppercat'] ?? null;
        if ((is_int($idUppercat) || is_string($idUppercat)) && isset($cats[$idUppercat])) {
            $parent = &$cats[$idUppercat];

            $nbCategories = $parent['nb_categories'] ?? null;
            $parent['nb_categories'] = (is_numeric($nbCategories) ? (int) $nbCategories : 0) - 1;

            do {
                $countImages = $parent['count_images'] ?? null;
                $nbImages = $cat['nb_images'] ?? null;
                $parent['count_images'] = (is_numeric($countImages) ? (int) $countImages : 0)
                    - (is_numeric($nbImages) ? (int) $nbImages : 0);

                $countCategories = $parent['count_categories'] ?? null;
                $catCountCategories = $cat['count_categories'] ?? null;
                $parent['count_categories'] = (is_numeric($countCategories) ? (int) $countCategories : 0)
                    - (1 + (is_numeric($catCountCategories) ? (int) $catCountCategories : 0));

                $parentIdUppercat = $parent['id_uppercat'] ?? null;
                if (! (is_int($parentIdUppercat) || is_string($parentIdUppercat)) || ! isset($cats[$parentIdUppercat])) {
                    break;
                }

                $parent = &$cats[$parentIdUppercat];
            } while (true);
        }

        $catIdKey = $cat['cat_id'] ?? null;
        if (is_int($catIdKey) || is_string($catIdKey)) {
            unset($cats[$catIdKey]);
        }
    }

    /**
     * @param  list<int>  $catIds
     * @return list<int>
     */
    public function getImageIdsForCategories(
        array $catIds,
        string $mode = 'AND',
        string $extraImagesWhereSql = '',
        string $orderBy = '',
        bool $usePermissions = true
    ): array {
        /** @var array<string, mixed> $conf */
        global $conf;

        if ($catIds === []) {
            return [];
        }

        // no prefix: findImageIdsForCategories() feeds this into andWhere(),
        // which already inserts its own "AND" between conditions -- a
        // "\n  AND"-prefixed condition here double-wraps into literal
        // "AND AND (...)", a real SQL syntax error caught via live
        // verification (menubar.inc.php -> get_related_categories_menu()).
        $permissionCondition = $usePermissions
            ? $this->permissionService->getSqlConditionFandF([
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
                'visible_images' => 'id',
            ])
            : '';

        $orderByConf = $conf['order_by'] ?? null;
        $orderByConfStr = is_scalar($orderByConf) ? (string) $orderByConf : '';
        $effectiveOrderBy = $orderBy === '' ? $orderByConfStr : $orderBy;

        return $this->repo->findImageIdsForCategories($catIds, $mode, $extraImagesWhereSql, $effectiveOrderBy, $permissionCondition);
    }

    /**
     * @param  list<int>  $items
     * @param  list<int>  $excludedCatIds
     * @return array<int, array{id: int, uppercats: string, counter: int}>
     */
    public function getCommonCategories(array $items, ?int $max = null, array $excludedCatIds = [], bool $usePermissions = true): array
    {
        if ($items === []) {
            return [];
        }

        // no prefix -- see getImageIdsForCategories()'s own comment: this
        // feeds into findCommonCategories()'s andWhere(), which already
        // inserts "AND" itself.
        $permissionCondition = $usePermissions
            ? $this->permissionService->getSqlConditionFandF([
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
            ])
            : '';

        return $this->repo->findCommonCategories($items, $max, $excludedCatIds, $permissionCondition);
    }

    /**
     * Common-categories tree for a list of items, WITHOUT the page-URL
     * decoration (`url` key) -- the free-function wrapper
     * `get_related_categories_menu()` adds that from `$page`/
     * `make_index_url()` afterward, same split as every other
     * $page-coupled function in this domain.
     *
     * @param  list<int>  $items
     * @param  list<int>  $excludedCatIds
     * @return list<array<string, mixed>>
     */
    public function getRelatedCategoriesMenu(array $items, array $excludedCatIds = []): array
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $relatedAlbumsDisplayLimit = $conf['related_albums_display_limit'] ?? null;
        $relatedAlbumsDisplayLimitInt = is_numeric($relatedAlbumsDisplayLimit) ? (int) $relatedAlbumsDisplayLimit : null;

        $commonCats = $this->getCommonCategories($items, $relatedAlbumsDisplayLimitInt, $excludedCatIds);

        if ($commonCats === []) {
            return [];
        }

        $catIds = [];
        foreach ($commonCats as $cat) {
            foreach (explode(',', $cat['uppercats']) as $uppercat) {
                $catIds[$uppercat] = ($catIds[$uppercat] ?? 0) + 1;
            }
        }

        $cats = $this->repo->findCategoriesByIds(array_map(intval(...), array_keys($catIds)));
        usort($cats, self::compareByGlobalRank(...));

        $indexOfCat = [];

        foreach ($cats as $idx => $cat) {
            // 'id' comes back as a native int under this project's mysqli
            // driver config (unlike varchar columns, which stay strings) --
            // is_int()||is_string() here, not is_string() alone, otherwise
            // this whole branch (and the subcategory-count propagation
            // below, which depends on $indexOfCat) silently never runs.
            $catId = $cat['id'];
            $catIdIsKeyable = is_int($catId) || is_string($catId);
            if ($catIdIsKeyable) {
                $indexOfCat[$catId] = $idx;
            }

            $globalRank = $cat['global_rank'];
            $cats[$idx]['LEVEL'] = substr_count(is_scalar($globalRank) ? (string) $globalRank : '', '.') + 1;
            $cats[$idx]['name'] = trigger_change('render_category_name', $cat['name'], $cat);

            if ($catIdIsKeyable && isset($commonCats[$catId])) {
                $cats[$idx]['count_images'] = $commonCats[$catId]['counter'];
            }

            $idUppercat = $cat['id_uppercat'] ?? null;
            $hasIdUppercat = $idUppercat !== null && $idUppercat !== '' && $idUppercat !== '0' && $idUppercat !== 0;
            $countImages = $cats[$idx]['count_images'] ?? 0;
            $countImages = is_numeric($countImages) ? (int) $countImages : 0;
            if ($hasIdUppercat && $countImages > 0) {
                $uppercats = $cat['uppercats'];
                foreach (array_slice(explode(',', is_scalar($uppercats) ? (string) $uppercats : ''), 0, -1) as $uppercatId) {
                    $parentIdx = $indexOfCat[$uppercatId] ?? null;
                    if (! is_int($parentIdx)) {
                        continue;
                    }

                    $countCategories = $cats[$parentIdx]['count_categories'] ?? null;
                    $cats[$parentIdx]['count_categories'] = (is_numeric($countCategories) ? (int) $countCategories : 0) + 1;
                }
            }
        }

        return $cats;
    }
}
