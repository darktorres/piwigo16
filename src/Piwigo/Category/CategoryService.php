<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Cache\CachePools;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\Env;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\TemplateInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\UserRepository;

/**
 * Category domain business logic, ported from the deleted
 * `include/functions_category.inc.php`'s 17 functions (P23 batch 8c
 * folded the remaining `$page`/`$template`-coupled ones in here too --
 * `checkRestrictions()`, `getCategoriesMenu()`, `displaySelectCategories()`/
 * `displaySelectCatWrapper()`, `getRelatedCategoriesMenuWithUrls()` --
 * none type-hint `Template`/`$page`'s callers anywhere, only read the
 * globals via a docblock-typed `global`, same "no hard class dependency"
 * shape already established by `Piwigo\Category\CategoryDefaultRenderer`
 * importing `Template` the same way with 0 deptrac violations).
 *
 * P23 batch 8d file 3 added 17 more (`admin/include/functions.php`'s
 * Categories domain group -- `sync_users()` went to
 * `Piwigo\Users\UserService` instead, wrong domain despite the file
 * grouping). `Activity` is L2bExtendedDomain; {@see deleteSite()}/
 * {@see deleteCategories()}/{@see moveCategories()}/
 * {@see createVirtualCategory()} need it to log activity, but
 * constructor-injecting `ActivityLoggerInterface` (this class's usual
 * cross-layer-dependency fix elsewhere, e.g. {@see imageService()}) would
 * force all ~45 real `new CategoryService(...)` call sites to supply one
 * -- the vast majority pure-read (menu rendering, gallery browsing) and
 * never touch activity logging. Instead, only those 4 write methods take
 * `ActivityLoggerInterface` as an explicit parameter.
 */
final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $repo,
        private PermissionService $permissionService,
    ) {}

    /**
     * `Activity` is L2bExtendedDomain; `CategoryService` is L2aCoreDomain
     * and may not depend on it directly (a private helper constructing
     * `ActivityService` inline, same as {@see \Piwigo\Image\ImageService}'s
     * own `categoryService()` helper does for same-layer `CategoryService`,
     * is a real `deptrac analyse` violation here specifically because
     * Activity crosses layers). Unlike `ImageService`/`TagService` (each
     * constructed fresh per write operation), `CategoryService` has ~45
     * real construction sites, the vast majority pure-read (menu
     * rendering, gallery browsing) that never touch activity logging --
     * constructor-injecting `ActivityLoggerInterface` would force all 45
     * to supply one. Instead, only the 4 methods that actually log
     * activity ({@see deleteSite()}, {@see deleteCategories()},
     * {@see moveCategories()}, {@see createVirtualCategory()}) take it as
     * an explicit parameter, matching this method's own shape.
     */
    private function imageService(ActivityLoggerInterface $activityLogger): ImageService
    {
        return new ImageService(new ImageRepository(DbConnection::build()), $activityLogger);
    }

    private function userRepository(): UserRepository
    {
        return new UserRepository(DbConnection::build());
    }

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
     * PHP-side port of `get_recent_photos_sql()`'s (`include/
     * functions_user.inc.php`) SQL fragment: `$dbField >= LEAST(today -
     * $recentPeriod days, $lastPhotoDate - 1 day)`. P23 batch 4b's
     * CategoryCatsRenderer applies this per already-cached tree row
     * (CategoryTreeCache) instead of building a SQL `WHERE`, matching the
     * same existence-filter-over-cached-data pattern batch 3a/3b already
     * established. $lastPhotoDate === null matches the original's own
     * `if (!isset($user['last_photo_date'])) return '0=1';` -- nothing is
     * ever "recent" without it, not merely half of the LEAST() comparison.
     */
    public static function isRecentCategory(?string $dateLast, int $recentPeriod, ?string $lastPhotoDate, \DateTimeImmutable $now): bool
    {
        if ($lastPhotoDate === null || $lastPhotoDate === '' || $dateLast === null || $dateLast === '') {
            return false;
        }

        $thresholdFromToday = $now->setTime(0, 0, 0)
            ->modify('-' . $recentPeriod . ' days');
        $thresholdFromLastPhoto = new \DateTimeImmutable($lastPhotoDate)
            ->modify('-1 day');
        $threshold = $thresholdFromToday < $thresholdFromLastPhoto ? $thresholdFromToday : $thresholdFromLastPhoto;

        return new \DateTimeImmutable($dateLast) >= $threshold;
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
                $cat[$k] = \Piwigo\Db\SqlDialect::getBoolean($v);
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

        $orders = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_category_preferred_image_orders', [
            [l10n('Default'), '', true],
            [l10n('Photo title, A &rarr; Z'), 'name ASC', true],
            [l10n('Photo title, Z &rarr; A'), 'name DESC', true],
            [l10n('Date created, new &rarr; old'), 'date_creation DESC', true],
            [l10n('Date created, old &rarr; new'), 'date_creation ASC', true],
            [l10n('Date posted, new &rarr; old'), 'date_available DESC', true],
            [l10n('Date posted, old &rarr; new'), 'date_available ASC', true],
            [l10n('Rating score, high &rarr; low'), 'rating_score DESC', \Piwigo\Config\Config::rateEnabled()],
            [l10n('Rating score, low &rarr; high'), 'rating_score ASC', \Piwigo\Config\Config::rateEnabled()],
            [l10n('Visits, high &rarr; low'), 'hit DESC', true],
            [l10n('Visits, low &rarr; high'), 'hit ASC', true],
            [l10n('Permissions'), 'level DESC', \Piwigo\Auth\AccessControl::isAdmin()],
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

        $orderByConf = \Piwigo\Config\Config::all()['order_by'] ?? null;
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

        $relatedAlbumsDisplayLimit = \Piwigo\Config\Config::relatedAlbumsDisplayLimit();
        $relatedAlbumsDisplayLimitInt = $relatedAlbumsDisplayLimit;

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
            $cats[$idx]['name'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_category_name', $cat['name'], $cat);

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

    /**
     * Is the category accessible to the connected user? If the user is not
     * authorized to see this category, the script exits.
     */
    public function checkRestrictions(int $categoryId, HtmlRenderingInterface $htmlRenderer): void
    {
        // $filter['visible_categories'] and $filter['visible_images']
        // are not used because it's not necessary (filter <> restriction)
        $forbiddenCategoriesStr = \Piwigo\Users\CurrentUser::get()->forbiddenCategories;
        if (in_array($categoryId, explode(',', $forbiddenCategoriesStr))) {
            $htmlRenderer->accessDenied();
        }
    }

    /**
     * Returns all subcategory identifiers of given category ids.
     *
     * @param array<int|string> $ids several callers (comments.php, admin/rating.php)
     *   wrap a raw, unvalidated $_GET value directly — the is_numeric() check
     *   below is a real guard, not dead code
     * @return list<int> array_values() below always reindexes the result
     */
    public function getSubcatIds(array $ids): array
    {
        // Non-numeric values are only warned about, never embedded into the
        // query -- CategoryRepository::findSubcategoryIds() binds each id as a
        // parameter, so a non-numeric value can never reach raw SQL.
        $validatedIds = [];
        foreach ($ids as $categoryId) {
            if (is_numeric($categoryId)) {
                $validatedIds[] = (int) $categoryId;
                continue;
            }

            trigger_error(
                'getSubcatIds expecting numeric, not ' . gettype($categoryId),
                E_USER_WARNING
            );
        }

        return $this->repo->findSubcategoryIds($validatedIds);
    }

    /**
     * Returns template vars for main categories menu.
     *
     * P23 batch 8f-1: `FilterUpdaterInterface` is an explicit parameter
     * here, not a constructor dependency, for the same reason as
     * `ActivityLoggerInterface` above (~45 real construction sites, the
     * vast majority pure-read and never touching filtered-category data;
     * this is the one method that does).
     *
     * Legacy Coupling Retirement Track A batch A5.2e: $category is an
     * explicit param instead of `global $page['category']`. This method
     * also used to write `count_categories` back onto that same global
     * array once it located the matching menu row (for the caller's own
     * later `$page['category']['count_categories']` read) -- with no
     * global left to mutate, that value comes back through the return
     * shape instead.
     *
     * @param array<string, mixed>|null $category
     * @return array{menu: array<int, array<string, mixed>>, categoryCountCategories: mixed}
     */
    public function getCategoriesMenu(?array $category, FilterUpdaterInterface $filterUpdater): array
    {
        $currentUser = \Piwigo\Users\CurrentUser::get();

        $categoryPage = $category;
        $countCategories = null;

        // P23 batch 3b: findMenuCategories()'s SQL WHERE (structural id_uppercat
        // filter, or PermissionService::getSqlConditionFandF()'s
        // visible_categories condition) is replaced by an equivalent PHP-side
        // filter applied to CategoryTreeCache's cached, permission-filtered row
        // set -- see that class's own docblock for why this can no longer be
        // pushed down to SQL (it no longer reads from a DB-backed cache table).
        // The get_categories_menu_sql_where trigger_change() hook is dropped:
        // grep confirms zero real handlers exist for it anywhere in this repo,
        // and its whole contract (mutate a SQL string) has no PHP-filter
        // equivalent worth inventing for zero real consumers.
        $allRows = new CategoryTreeCache(
            $this,
            $this->repo,
            CachePools::categoryTree()
        )->getForUser($currentUser->rawAttributes);

        $rows = self::filterMenuRows(
            $allRows,
            $categoryPage,
            (bool) $currentUser->rawAttributes['expand'],
            \Piwigo\Core\FilterState::isEnabled(),
            \Piwigo\Core\FilterState::visibleCategories()
        );

        $cats = [];
        $selectedCategory = $categoryPage;
        foreach ($rows as $row) {
            // both sides get coerced to string for comparison: $row['id'] is
            // always a DB-fetch string, but $page['category']['id'] may already
            // be an int depending on how that array was populated -- matches
            // the original's loose ==, which PHPStan disallows outright.
            $rowId = $row['id'] ?? null;
            $rowIdStr = is_scalar($rowId) ? (string) $rowId : null;
            $rowGlobalRank = $row['global_rank'] ?? null;
            $childDateLast = @$row['max_date_last'] > @$row['date_last'];
            $selectedId = $selectedCategory['id'] ?? null;
            $selectedIdStr = is_scalar($selectedId) ? (string) $selectedId : null;
            $selectedIdUppercat = $selectedCategory['id_uppercat'] ?? null;
            $selectedIdUppercatStr = is_scalar($selectedIdUppercat) ? (string) $selectedIdUppercat : null;
            $row = array_merge(
                $row,
                [
                    'NAME' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                        'render_category_name',
                        $row['name'],
                        'get_categories_menu'
                    ),
                    'TITLE' => self::getDisplayImagesCount(
                        is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0,
                        is_numeric($row['count_images']) ? (int) $row['count_images'] : 0,
                        is_numeric($row['count_categories']) ? (int) $row['count_categories'] : 0,
                        false,
                        ' / '
                    ),
                    'URL' => make_index_url([
                        'category' => $row,
                    ]),
                    'LEVEL' => substr_count(is_scalar($rowGlobalRank) ? (string) $rowGlobalRank : '', '.') + 1,
                    'SELECTED' => $selectedCategory !== null && $selectedIdStr !== null && $selectedIdStr === $rowIdStr,
                    'IS_UPPERCAT' => $selectedCategory !== null && $selectedIdUppercatStr !== null && $selectedIdUppercatStr === $rowIdStr,
                ]
            );
            if (\Piwigo\Config\Config::indexNewIcon()) {
                $maxDateLast = $row['max_date_last'] ?? null;
                $recentPeriodForIcon = is_numeric($currentUser->rawAttributes['recent_period'] ?? null) ? (int) $currentUser->rawAttributes['recent_period'] : 0;
                $row['icon_ts'] = \Piwigo\Core\RecentIconResolver::getIcon(is_string($maxDateLast) ? $maxDateLast : '', $recentPeriodForIcon, $childDateLast);
            }
            $cats[] = $row;
            $categoryPageId = $categoryPage['id'] ?? null;
            $categoryPageIdStr = is_scalar($categoryPageId) ? (string) $categoryPageId : null;
            if ($categoryPage !== null && $categoryPageIdStr !== null && $categoryPageIdStr === $rowIdStr) { // save the number of subcats for later optim
                $countCategories = $row['count_categories'] ?? null;
            }
        }
        usort($cats, self::compareByGlobalRank(...));

        // Update filtered data
        $filterUpdater->updateCatsWithFilteredData($cats);

        return [
            'menu' => $cats,
            'categoryCountCategories' => $countCategories,
        ];
    }

    /**
     * Assign a template var useable with {html_options} from a list of
     * categories.
     *
     * @param array<int, array<string, mixed>> $categories (at least id,name,global_rank,uppercats for each)
     * @param array<int, mixed> $selecteds
     * @param string $blockname variable name in template
     * @param bool $fullname full breadcrumb or not
     */
    public function displaySelectCategories(
        array $categories,
        array $selecteds,
        string $blockname,
        HtmlRenderingInterface $htmlRenderer,
        TemplateInterface $template,
        bool $fullname = true
    ): void {
        $tplCats = [];
        foreach ($categories as $category) {
            if ($fullname) {
                $uppercats = $category['uppercats'];
                $option = strip_tags(
                    $htmlRenderer->getCatDisplayNameCache(
                        is_string($uppercats) ? $uppercats : '',
                        null
                    )
                );
            } else {
                $globalRank = $category['global_rank'];
                $option = str_repeat(
                    '&nbsp;',
                    (3 * substr_count(is_string($globalRank) ? $globalRank : '', '.'))
                );
                $option .= '- ';
                $renderedName = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                    'render_category_name',
                    $category['name'],
                    'display_select_categories'
                );
                $option .= strip_tags(is_string($renderedName) ? $renderedName : '');
            }
            $id = $category['id'];
            if (is_int($id) || is_string($id)) {
                $tplCats[$id] = $option;
            }
        }

        $template->assign($blockname, $tplCats);
        $template->assign($blockname . '_selected', $selecteds);
    }

    /**
     * Same as displaySelectCategories() but categories are ordered by rank.
     *
     * @see displaySelectCategories()
     * @param array<int, mixed> $selecteds
     * @param string $blockname variable name in template
     * @param bool $fullname full breadcrumb or not
     */
    public function displaySelectCatWrapper(
        string $query,
        array $selecteds,
        string $blockname,
        HtmlRenderingInterface $htmlRenderer,
        TemplateInterface $template,
        bool $fullname = true
    ): void {
        $categories = $this->repo->fetchCallerBuiltQuery($query);
        usort($categories, self::compareByGlobalRank(...));
        $this->displaySelectCategories($categories, $selecteds, $blockname, $htmlRenderer, $template, $fullname);
    }

    /**
     * Same as getRelatedCategoriesMenu(), plus the page-URL decoration
     * (`url` key) the former free function get_related_categories_menu()
     * (functions_category.inc.php, P23 batch 8c) added afterward from
     * `$page`/`make_index_url()`.
     *
     * NOTE: 'combined_categories' below carries $cat AFTER
     * getRelatedCategoriesMenu()'s own 'render_category_name'
     * trigger_change() already ran on 'name' (the original built this
     * array BEFORE that render), so UrlService::makeIndexUrl()'s id-name
     * style would embed the *rendered* name instead of the raw one if a
     * 'render_category_name' plugin handler is ever registered (none are
     * today -- PEM extensions are unwired, trigger_change() is a
     * same-value pass-through -- so this is currently a no-op
     * difference). Re-verify once P31 wires real event handlers.
     *
     * Legacy Coupling Retirement Track A batch A5.2e: $category/
     * $combinedCategories are explicit params instead of
     * `global $page['category']`/`['combined_categories']`.
     *
     * @param  array<int, int|string>  $items
     * @param  array<int, int|string>  $excludedCatIds
     * @param  array<string, mixed>|null  $category
     * @param  list<array<string, mixed>>|null  $combinedCategories
     * @return list<array<string, mixed>>
     */
    public function getRelatedCategoriesMenuWithUrls(array $items, array $excludedCatIds = [], ?array $category = null, ?array $combinedCategories = null): array
    {
        $cats = $this->getRelatedCategoriesMenu(
            array_values(array_map(intval(...), $items)),
            array_values(array_map(intval(...), $excludedCatIds))
        );

        foreach ($cats as $idx => $cat) {
            if (! isset($cat['count_images'])) {
                continue;
            }

            $urlParams = [];
            if ($category !== null) {
                $urlParams['category'] = $category;

                $urlParams['combined_categories'] = [$cat];
                if ($combinedCategories !== null) {
                    $urlParams['combined_categories'] = array_merge($combinedCategories, [$cat]);
                }
            } else {
                $urlParams['category'] = $cat;
            }

            $cats[$idx]['url'] = make_index_url($urlParams);
        }

        return $cats;
    }

    /**
     * Deletes a site and its primary categories.
     */
    public function deleteSite(int $id, ActivityLoggerInterface $activityLogger): void
    {
        $categoryIds = $this->repo->findCategoryIdsBySite($id);
        $this->deleteCategories($categoryIds, $activityLogger);

        $this->repo->deleteSiteRow($id);
    }

    /**
     * Recursively deletes one or more categories.
     * It also deletes:
     *    - all the elements physically linked to the category (with ImageService::deleteElements())
     *    - all the links between elements and this category
     *    - all the restrictions linked to the category
     *
     * @param array<int, int> $ids
     * @param string $photoDeletionMode
     *    - no_delete: delete no photo, may create orphans
     *    - delete_orphans: delete photos that are no longer linked to any category
     *    - force_delete: delete photos even if they are linked to another category
     */
    public function deleteCategories(array $ids, ActivityLoggerInterface $activityLogger, string $photoDeletionMode = 'no_delete'): void
    {
        if (count($ids) === 0) {
            return;
        }

        // add sub-category ids to the given ids : if a category is deleted, all
        // sub-categories must be so
        $ids = $this->getSubcatIds($ids);

        $imageService = $this->imageService($activityLogger);

        // destruction of all photos physically linked to the category
        $elementIds = $this->repo->findStorageLinkedImageIds($ids);
        $imageService->deleteElements($elementIds);

        // now, should we delete photos that are virtually linked to the category?
        if ($photoDeletionMode === 'delete_orphans' || $photoDeletionMode === 'force_delete') {
            $imageIdsLinked = $this->repo->findDistinctLinkedImageIds($ids);

            if (count($imageIdsLinked) > 0) {
                if ($photoDeletionMode === 'delete_orphans') {
                    $imageIdsNotOrphans = $this->repo->findNonOrphanImageIds($imageIdsLinked, $ids);
                    $imageIdsToDelete = array_diff($imageIdsLinked, $imageIdsNotOrphans);
                } else {
                    $imageIdsToDelete = $imageIdsLinked;
                }

                $imageService->deleteElements(array_map(intval(...), $imageIdsToDelete), true);
            }
        }

        // destruction of the links between images and this category
        $this->repo->deleteImageCategoryLinksForCategories($ids);

        // destruction of the access linked to the category
        $this->repo->deleteUserAccessForCategories($ids);
        $this->repo->deleteGroupAccessForCategories($ids);

        // destruction of the category
        $this->repo->deleteCategoriesByIds($ids);

        $this->repo->deleteOldPermalinksForCategories($ids);
        $this->repo->deleteUserCacheCategoriesForCategories($ids);

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('delete_categories', $ids);
        $activityLogger->record('album', $ids, 'delete', [
            'photo_deletion_mode' => $photoDeletionMode,
        ]);
    }

    /**
     * Verifies that the representative picture really exists in the db and
     * picks up a random representative if possible and based on config.
     *
     * @param 'all'|int|array<int|string> $ids ws_functions/pwg.images.php passes
     *   preg_match()-validated but never int-cast category id strings; $ids only
     *   ever flows into implode()/SQL contexts below, so numeric strings work
     *   identically
     */
    public function updateCategory(array|int|string $ids = 'all'): ?false
    {

        if ($ids === 'all') {
            $whereCats = '1=1';
        } elseif (! is_array($ids)) {
            $whereCats = '%s=' . $ids;
        } else {
            if (count($ids) === 0) {
                return false;
            }
            $whereCats = '%s IN(' . wordwrap(implode(', ', $ids), 120, "\n") . ')';
        }

        // find all categories where the setted representative is not possible :
        // the picture does not exist
        $wrongRepresentant = $this->repo->findWrongRepresentativeCategoryIds(sprintf($whereCats, 'c.id'));

        if (count($wrongRepresentant) > 0) {
            $this->repo->clearRepresentativePictureIds($wrongRepresentant);
        }

        if (! \Piwigo\Config\Config::allowRandomRepresentative()) {
            // If the random representant is not allowed, we need to find
            // categories with elements and with no representant. Those categories
            // must be added to the list of categories to set to a random
            // representant.
            $toRand = $this->repo->findCategoriesNeedingRandomRepresentative(sprintf($whereCats, 'category_id'));
            if (count($toRand) > 0) {
                $this->setRandomRepresentant($toRand);
            }
        }

        return null;
    }

    /**
     * Checks and repairs integrity on categories.
     * Removes all entries from related tables which correspond to a deleted category.
     */
    public function checkCategoriesIntegrity(): void
    {
        $relatedColumns = [
            [Tables::imageCategory(), 'category_id'],
            [Tables::userAccess(), 'cat_id'],
            [Tables::groupAccess(), 'cat_id'],
            [Tables::oldPermalinks(), 'cat_id'],
            [Tables::userCacheCategories(), 'cat_id'],
        ];

        foreach ($relatedColumns as [$table, $column]) {
            $orphans = $this->repo->findOrphanedColumnValues($table, $column);

            if (count($orphans) > 0) {
                $this->repo->deleteRowsWhereColumnIn($table, $column, $orphans);
            }
        }
    }

    /**
     * save the rank depending on given categories order
     *
     * The list of ordered categories id is supposed to be in the same parent
     * category
     *
     * @param array<int, mixed> $categories
     */
    public function saveCategoriesOrder(array $categories): void
    {
        $currentRankForIdUppercat = [];
        $currentRank = 0;

        $datas = [];
        foreach ($categories as $category) {
            if (is_array($category)) {
                $id = $category['id'];
                $idUppercat = $category['id_uppercat'];
                if (! is_int($idUppercat) && ! is_string($idUppercat)) {
                    // id_uppercat is null (or otherwise non-scalar) for top-level
                    // categories; bucket them together like the '' sentinel used
                    // for $currentUppercat in updateGlobalRank() below.
                    $idUppercat = '';
                }

                if (! isset($currentRankForIdUppercat[$idUppercat])) {
                    $currentRankForIdUppercat[$idUppercat] = 0;
                }
                $currentRank = ++$currentRankForIdUppercat[$idUppercat];
            } else {
                $id = $category;
                $currentRank++;
            }

            $datas[] = [
                'id' => $id,
                'rank' => $currentRank,
            ];
        }
        $this->repo->massUpdateRanks($datas);

        $this->updateGlobalRank();
    }

    /**
     * Orders categories (update categories.rank and global_rank database fields)
     * so that rank field are consecutive integers starting at 1 for each child.
     */
    public function updateGlobalRank(): int
    {
        $rows = $this->repo->findCategoriesForRankUpdate();

        $catMap = [];
        $currentRank = 0;
        $currentUppercat = null;

        foreach ($rows as $row) {
            $rowIdUppercat = is_scalar($row['id_uppercat']) ? (string) $row['id_uppercat'] : null;
            if ($rowIdUppercat !== $currentUppercat) {
                $currentRank = 0;
                $currentUppercat = $rowIdUppercat;
            }
            ++$currentRank;

            $rowId = $row['id'];
            $rowUppercats = $row['uppercats'];
            $rowRank = $row['rank'];
            // id, uppercats, and rank are NOT NULL columns in the categories table.
            assert(is_string($rowId) && is_string($rowUppercats) && is_numeric($rowRank));

            $cat =
              [
                  'rank' => $currentRank,
                  'rank_changed' => $currentRank !== (int) $rowRank,
                  'global_rank' => $row['global_rank'],
                  'uppercats' => $rowUppercats,
              ];
            $catMap[$rowId] = $cat;
        }

        $datas = [];

        $catMapCallback = function (array $m) use ($catMap): string {
            $matchedId = $m[1] ?? null;
            if (! is_string($matchedId) || ! isset($catMap[$matchedId])) {
                return '';
            }
            return (string) $catMap[$matchedId]['rank'];
        };

        foreach ($catMap as $id => $cat) {
            $newGlobalRank = preg_replace_callback(
                '/(\d+)/',
                $catMapCallback,
                str_replace(',', '.', $cat['uppercats'])
            );

            if ($cat['rank_changed'] || $newGlobalRank !== $cat['global_rank']) {
                $datas[] = [
                    'id' => $id,
                    'rank' => $cat['rank'],
                    'global_rank' => $newGlobalRank,
                ];
            }
        }

        $this->repo->massUpdateRanksAndGlobalRank($datas);

        return count($datas);
    }

    /**
     * Change the **visible** property on a set of categories.
     *
     * @param int[] $categories
     */
    public function setCatVisible(array $categories, bool|string $value, bool $unlockChild = false): ?false
    {
        if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
            trigger_error("setCatVisible invalid param {$value}", E_USER_WARNING);
            return false;
        }

        // unlocking a category => all its parent categories become unlocked
        if ($value) {
            $cats = $this->getUppercatIds($categories);
            if ($unlockChild) {
                $cats = array_merge($cats, $this->getSubcatIds($categories));
            }
            $this->repo->updateCategoryVisibility($cats, true);
        } else {
            // locking a category => all its child categories become locked
            $subcats = $this->getSubcatIds($categories);
            $this->repo->updateCategoryVisibility($subcats, false);
        }

        return null;
    }

    /**
     * Change the **status** property on a set of categories : private or public.
     *
     * @param int[] $categories
     */
    public function setCatStatus(array $categories, string $value): ?false
    {
        if (! in_array($value, ['public', 'private'], true)) {
            trigger_error("setCatStatus invalid param {$value}", E_USER_WARNING);
            return false;
        }

        // make public a category => all its parent categories become public
        if ($value === 'public') {
            $uppercats = $this->getUppercatIds($categories);
            $this->repo->updateCategoryStatus($uppercats, 'public');
        }

        // make a category private => all its child categories become private
        if ($value === 'private') {
            $subcats = $this->getSubcatIds($categories);
            $this->repo->updateCategoryStatus($subcats, 'private');

            // We have to keep permissions consistant: a sub-album can't be
            // permitted to a user or group if its parent album is not permitted to
            // the same user or group. Let's remove all permissions on sub-albums if
            // it is not consistant. Let's take the following example:
            //
            // A1        permitted to U1,G1
            // A1/A2     permitted to U1,U2,G1,G2
            // A1/A2/A3  permitted to U3,G1
            // A1/A2/A4  permitted to U2
            // A1/A5     permitted to U4
            // A6        permitted to U4
            // A6/A7     permitted to G1
            //
            // (we consider that it can be possible to start with inconsistant
            // permission, given that public albums can have hidden permissions,
            // revealed once the album returns to private status)
            //
            // The admin selects A2,A3,A4,A5,A6,A7 to become private (all but A1,
            // which is private, which can be true if we're moving A2 into A1). The
            // result must be:
            //
            // A2 permission removed to U2,G2
            // A3 permission removed to U3
            // A4 permission removed to U2
            // A5 permission removed to U2
            // A6 permission removed to U4
            // A7 no permission removed
            //
            // 1) we must extract "top albums": A2, A5 and A6
            // 2) for each top album, decide which album is the reference for permissions
            // 3) remove all inconsistant permissions from sub-albums of each top-album

            // step 1, search top albums
            $topCategories = [];
            $parentIds = [];

            $allCategories = $this->repo->findCategoriesByIds(array_values(array_map(intval(...), $categories)));
            usort($allCategories, self::compareByGlobalRank(...));

            foreach ($allCategories as $cat) {
                $isTop = true;

                $catIdUppercat = $cat['id_uppercat'];
                $catHasParent = $catIdUppercat !== null && $catIdUppercat !== '' && $catIdUppercat !== '0' && $catIdUppercat !== 0;
                $catUppercats = $cat['uppercats'];
                assert(is_string($catUppercats));

                if ($catHasParent) {
                    foreach (explode(',', $catUppercats) as $idUppercat) {
                        if (isset($topCategories[$idUppercat])) {
                            $isTop = false;
                            break;
                        }
                    }
                }

                if ($isTop) {
                    $catId = $cat['id'];
                    assert(is_string($catId) || is_int($catId));
                    $topCategories[$catId] = $cat;

                    if ($catHasParent) {
                        $parentIds[] = $catIdUppercat;
                    }
                }
            }

            // step 2, search the reference album for permissions
            //
            // to find the reference of each top album, we will need the parent albums
            $parentCats = [];

            if (count($parentIds) > 0) {
                $parentCats = $this->repo->findStatusByIds(array_map(intval(...), $parentIds));
            }

            $tables = [
                Tables::userAccess() => 'user_id',
                Tables::groupAccess() => 'group_id',
            ];

            foreach ($topCategories as $topCategory) {
                // what is the "reference" for list of permissions? The parent album
                // if it is private, else the album itself
                $topCategoryId = $topCategory['id'];
                assert(is_string($topCategoryId) || is_int($topCategoryId));
                $refCatId = (int) $topCategoryId;

                $topCategoryIdUppercat = $topCategory['id_uppercat'];
                $topCategoryHasParent = $topCategoryIdUppercat !== null && $topCategoryIdUppercat !== '' && $topCategoryIdUppercat !== '0' && $topCategoryIdUppercat !== 0;
                if ($topCategoryHasParent && is_numeric($topCategoryIdUppercat)) {
                    $parentCatId = (int) $topCategoryIdUppercat;
                    if (isset($parentCats[$parentCatId]) && $parentCats[$parentCatId]['status'] === 'private') {
                        $refCatId = $parentCatId;
                    }
                }

                $subcats = $this->getSubcatIds([$topCategoryId]);

                foreach ($tables as $table => $field) {
                    // what are the permissions user/group of the reference album
                    $refAccess = $field === 'user_id'
                        ? $this->repo->findAccessUserIds($refCatId)
                        : $this->repo->findAccessGroupIds($refCatId);

                    if (count($refAccess) === 0) {
                        $refAccess[] = -1;
                    }

                    // step 3, remove the inconsistant permissions from sub-albums
                    $this->repo->deleteInconsistentAccess($table, $field, $refAccess, $subcats);
                }
            }
        }

        return null;
    }

    /**
     * Returns all uppercats category ids of the given category ids.
     *
     * @param int[] $catIds
     * @return int[]
     */
    public function getUppercatIds(array $catIds): array
    {
        return $this->repo->findUppercatIds($catIds);
    }

    /**
     * @return array{src: string|array<int|string, mixed>, url: string}
     */
    public function getCategoryRepresentantProperties(int|string $imageId, ?string $size = null): array
    {
        $row = new ImageRepository(DbConnection::build())->findById($imageId);
        if ($row === null) {
            throw new \Exception("getCategoryRepresentantProperties(): image {$imageId} does not exist (stale representative_picture_id?)");
        }
        if ($size === null) {
            $src = DerivativeImage::thumb_url($row);
        } else {
            $src = DerivativeImage::url($size, $row);
        }
        $url = get_root_url() . 'admin.php?page=photo-' . $imageId;

        return [
            'src' => $src,
            'url' => $url,
        ];
    }

    /**
     * Set a new random representant to the categories.
     *
     * @param int[] $categories
     */
    public function setRandomRepresentant(array $categories): void
    {
        $datas = [];
        foreach ($categories as $categoryId) {
            $representative = $this->repo->findRandomImageIdInCategory($categoryId);

            $datas[] = [
                'id' => $categoryId,
                'representative_picture_id' => $representative,
            ];
        }

        $this->repo->massUpdateRepresentativePictures($datas);
    }

    /**
     * Returns the fulldir for each given category id.
     *
     * @param int[] $catIds
     * @return string[]
     */
    public function getFulldirs(array $catIds): array
    {
        if (count($catIds) === 0) {
            return [];
        }

        $catDirs = $this->repo->findCategoryDirsById();
        $galleriesUrl = $this->repo->findSiteGalleriesUrls();
        $categories = $this->repo->findCategoriesForFulldirs(array_map(intval(...), $catIds));

        $catDirsCallback = function (array $m) use ($catDirs): string {
            $matchedId = $m[1] ?? null;
            return (is_string($matchedId) && isset($catDirs[(int) $matchedId])) ? $catDirs[(int) $matchedId] : '';
        };

        $catFulldirs = [];
        foreach ($categories as $category) {
            $catId = $category['id'];
            $siteId = $category['site_id'];
            $categoryUppercats = $category['uppercats'];
            // id and uppercats are NOT NULL columns; site_id is always populated
            // when a category is created (defaults to the local site).
            assert(is_numeric($catId) && is_numeric($siteId) && is_string($categoryUppercats));
            $catId = (int) $catId;
            $siteId = (int) $siteId;

            $uppercats = str_replace(',', '/', $categoryUppercats);
            $catFulldirs[$catId] = $galleriesUrl[$siteId];
            $catFulldirs[$catId] .= preg_replace_callback(
                '/(\d+)/',
                $catDirsCallback,
                $uppercats
            );
        }

        return $catFulldirs;
    }

    /**
     * Updates categories.uppercats field based on categories.id + categories.id_uppercat
     */
    public function updateUppercats(): void
    {
        $catMap = [];
        foreach ($this->repo->findCategoriesForRankUpdate() as $row) {
            // findCategoriesForRankUpdate() carries the extra rank/global_rank
            // columns this method never reads -- shared with updateGlobalRank()
            // rather than adding a near-duplicate id/id_uppercat/uppercats-only
            // query.
            $id = $row['id'];
            assert(is_string($id));
            $catMap[$id] = $row;
        }

        $datas = [];
        foreach ($catMap as $id => $cat) {
            $upperList = [];

            $uppercat = $id;
            while ((bool) $uppercat) {
                $upperList[] = $uppercat;
                $nextUppercat = $catMap[$uppercat]['id_uppercat'] ?? null;
                $uppercat = is_string($nextUppercat) ? $nextUppercat : null;
            }

            $newUppercats = implode(',', array_reverse($upperList));
            $catUppercats = $cat['uppercats'];
            assert(is_string($catUppercats));
            if ($newUppercats !== $catUppercats) {
                $datas[] = [
                    'id' => $id,
                    'uppercats' => $newUppercats,
                ];
            }
        }
        $this->repo->massUpdateUppercats($datas);
    }

    /**
     * Update images.path field base on images.file and storage categories fulldirs.
     */
    public function updatePath(): void
    {
        $catIds = $this->repo->findDistinctStorageCategoryIds();
        $fulldirs = $this->getFulldirs($catIds);

        foreach ($catIds as $catId) {
            $this->repo->updateImagePathsForCategory($catId, $fulldirs[$catId]);
        }
    }

    /**
     * Change the parent category of the given categories. The categories are
     * supposed virtual.
     *
     * @param array<int, int> $categoryIds
     * @param int $newParent (-1 for root)
     */
    public function moveCategories(array $categoryIds, ActivityLoggerInterface $activityLogger, int $newParent = -1): void
    {
        if (count($categoryIds) === 0) {
            return;
        }

        $newParentSql = $newParent < 1 ? 'NULL' : (string) $newParent;

        $categories = [];
        foreach ($this->repo->findCategoriesForMove($categoryIds) as $row) {
            $rowId = $row['id'];
            $rowUppercats = $row['uppercats'];
            assert(is_string($rowId) && is_string($rowUppercats));

            $rowIdUppercat = $row['id_uppercat'];
            $rowHasParent = $rowIdUppercat !== null && $rowIdUppercat !== '' && $rowIdUppercat !== '0' && $rowIdUppercat !== 0;

            $categories[$rowId] =
              [
                  'parent' => $rowHasParent ? $rowIdUppercat : 'NULL',
                  'status' => $row['status'],
                  'uppercats' => $rowUppercats,
              ];
        }

        // is the movement possible? The movement is impossible if you try to move
        // a category in a sub-category or itself
        if ($newParentSql !== 'NULL') {
            $newParentUppercats = $this->repo->findCategoryUppercatsById((int) $newParentSql);
            assert($newParentUppercats !== null);

            foreach ($categories as $category) {
                // technically, you can't move a category with uppercats 12,125,13,14
                // into a new parent category with uppercats 12,125,13,14,24
                if ((bool) preg_match('/^' . $category['uppercats'] . '(,|$)/', $newParentUppercats)) {
                    \Piwigo\Core\PageState::current()->addError(l10n('You cannot move an album in its own sub album'));
                    return;
                }
            }
        }

        $this->repo->updateCategoryParent($categoryIds, $newParentSql);

        $this->updateUppercats();
        $this->updateGlobalRank();

        // status and related permissions management
        if ($newParentSql === 'NULL') {
            $parentStatus = 'public';
        } else {
            $parentStatus = $this->repo->findCategoryStatus((int) $newParentSql);
        }

        if ($parentStatus === 'private') {
            $this->setCatStatus(array_map(intval(...), array_keys($categories)), 'private');
        }

        \Piwigo\Core\PageState::current()->addInfo(l10n_dec(
            '%d album moved',
            '%d albums moved',
            count($categories)
        ));

        $activityLogger->record('album', $categoryIds, 'move', [
            'parent' => $newParentSql,
        ]);
    }

    /**
     * Create a virtual category.
     *
     * @param int|string|null $parentId ws_categories_add() passes null by
     *   default (WsParamType::INT param, unset by the caller), admin/cat_list.php
     *   passes a raw, unvalidated $_GET['parent_id'] string
     * @param array{commentable?: mixed, visible?: mixed, status?: mixed, comment?: mixed, inherit?: mixed} $options
     *   values are validated internally (is_bool()/==), not trusted from callers
     * @return array{error: string}|array{info: string, id: int|string}
     */
    public function createVirtualCategory(string $categoryName, ActivityLoggerInterface $activityLogger, int|string|null $parentId = null, array $options = []): array
    {

        // is the given category name only containing blank spaces ?
        if ((bool) preg_match('/^\s*$/', $categoryName)) {
            return [
                'error' => l10n('The name of an album must not be empty'),
            ];
        }

        $rank = 0;
        if (\Piwigo\Config\Config::newcatDefaultPosition() === 'last') {
            // what is the current higher rank for this parent?
            $maxRank = $this->repo->findMaxRankForParent($parentId);
            if ($maxRank !== null) {
                $rank = $maxRank + 1;
            }
        }

        $insert = [
            'name' => $categoryName,
            'rank' => $rank,
            'global_rank' => 0,
            // Otherwise relies on the schema's own DEFAULT CURRENT_TIMESTAMP,
            // which reads the real DB-server clock -- invisible to Env::now()'s
            // PIWIGO_TEST_NOW freeze.
            'lastmodified' => Env::now()
                ->format('Y-m-d H:i:s'),
        ];

        // is the album commentable?
        if (isset($options['commentable']) && is_bool($options['commentable'])) {
            $insert['commentable'] = $options['commentable'];
        } else {
            $insert['commentable'] = \Piwigo\Config\Config::newcatDefaultCommentable();
        }
        $insert['commentable'] = \Piwigo\Db\SqlDialect::booleanToString($insert['commentable']);

        // is the album temporarily locked? (only visible by administrators,
        // whatever permissions) (may be overwritten if parent album is not
        // visible)
        if (isset($options['visible']) && is_bool($options['visible'])) {
            $insert['visible'] = $options['visible'];
        } else {
            $insert['visible'] = \Piwigo\Config\Config::newcatDefaultVisible();
        }
        $insert['visible'] = \Piwigo\Db\SqlDialect::booleanToString($insert['visible']);

        // is the album private? (may be overwritten if parent album is private)
        if (isset($options['status']) && $options['status'] === 'private') {
            $insert['status'] = 'private';
        } else {
            $insert['status'] = \Piwigo\Config\Config::newcatDefaultStatus();
        }

        // any description for this album?
        if (isset($options['comment'])) {
            $comment = is_scalar($options['comment']) ? (string) $options['comment'] : '';
            $insert['comment'] = (\Piwigo\Config\Config::allowHtmlDescriptions()) ? $options['comment'] : strip_tags($comment);
        }

        $parentIdIsEmpty = $parentId === null || $parentId === 0 || $parentId === '0' || $parentId === '';
        if (! $parentIdIsEmpty && is_numeric($parentId)) {
            $parent = $this->repo->findParentCategoryForCreate($parentId);
            if ($parent === null) {
                return [
                    'error' => l10n('The parent album does not exist'),
                ];
            }

            $insert['id_uppercat'] = $parent['id'];
            $insert['global_rank'] = $parent['global_rank'] . '.' . $insert['rank'];

            // at creation, must a category be visible or not ? Warning : if the
            // parent category is invisible, the category is automatically create
            // invisible. (invisible = locked)
            if ($parent['visible'] === 'false') {
                $insert['visible'] = 'false';
            }

            // at creation, must a category be public or private ? Warning : if the
            // parent category is private, the category is automatically create
            // private.
            if ($parent['status'] === 'private') {
                $insert['status'] = 'private';
            }

            $uppercatsPrefix = $parent['uppercats'] . ',';
        } else {
            $uppercatsPrefix = '';
        }

        // we have then to add the virtual category
        $insertedId = $this->repo->insertCategory($insert);

        $this->repo->updateCategoryAfterInsert($insertedId, [
            'uppercats' => $uppercatsPrefix . $insertedId,
            // This UPDATE is an unconditional, immediate follow-up to the
            // INSERT above (needs the auto-generated id first) -- part of
            // the same logical "create category" operation, not a later,
            // independent edit. Re-set explicitly, since ON UPDATE
            // CURRENT_TIMESTAMP would otherwise silently overwrite the
            // INSERT's own frozen lastmodified with the real DB-server
            // clock the moment this UPDATE runs.
            'lastmodified' => Env::now()
                ->format('Y-m-d H:i:s'),
        ]);

        $this->updateGlobalRank();

        $insertIdUppercat = $insert['id_uppercat'] ?? null;
        if ($insert['status'] === 'private' && $insertIdUppercat !== null && $insertIdUppercat !== 0 && ((isset($options['inherit']) && (bool) $options['inherit']) || \Piwigo\Config\Config::inheritanceByDefault())) {
            $grantedGrps = $this->repo->findAccessGroupIds($insertIdUppercat);
            $inserts = [];
            foreach ($grantedGrps as $grantedGrp) {
                $inserts[] = [
                    'group_id' => $grantedGrp,
                    'cat_id' => $insertedId,
                ];
            }
            $this->repo->massInsertGroupAccess($inserts);

            $grantedUsers = $this->repo->findAccessUserIds($insertIdUppercat);
            $this->permissionService->addPermissionOnCategory((int) $insertedId, $grantedUsers);
        } elseif ($insert['status'] === 'private') {
            $currentUserId = \Piwigo\Users\CurrentUser::get()->id;
            $adminIds = $this->userRepository()
                ->findAdminIds();
            $this->permissionService->addPermissionOnCategory((int) $insertedId, array_unique(array_merge($adminIds, [$currentUserId])));
        }

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('create_virtual_category', array_merge([
            'id' => $insertedId,
        ], $insert));
        $activityLogger->record('album', $insertedId, 'add');

        return [
            'info' => l10n('Album added'),
            'id' => $insertedId,
        ];
    }

    /**
     * Is the category accessible to the (Admin) user ?
     * Note : if the user is not authorized to see this category, category jump
     * will be replaced by admin cat_modify page
     */
    public function catAdminAccess(int $categoryId): bool
    {
        // $filter['visible_categories'] and $filter['visible_images']
        // are not used because it's not necessary (filter <> restriction)
        $forbiddenCategories = \Piwigo\Users\CurrentUser::get()->forbiddenCategories;
        return ! in_array((string) $categoryId, explode(',', $forbiddenCategories), true);
    }
}
