<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Cache\CachePools;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Logger;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\Template;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Renders the main page's subcategory thumbnails (or the recent-albums
 * listing). Ported from include/category_cats.inc.php -- re-scoped after
 * research well past the batch-4 planning pass's original one-line framing
 * ("extend CategoryTreeCache with one more column"). See P23 batch 4b's
 * plan write-up for the full adversarial-validation trail; summary of what
 * changed vs. the original SQL-driven file:
 *
 * - The `user_cache_categories` JOIN's rollup columns (nb_images/
 *   count_images/count_categories/max_date_last/date_last) come from
 *   {@see CategoryTreeCache::getForUser()} (batch 3b) instead -- the same
 *   per-user tree the menubar render on this same page load already warms.
 * - `c.*` (every real `categories` column the original's JOIN needed, which
 *   CategoryTreeCache's own cached row does NOT carry, by design -- adding
 *   it there would bloat every cached tree entry for a caller this narrow)
 *   comes from a fresh {@see CategoryRepository::findFullCategoriesByIds()}
 *   call, scoped to only the current page's already-paginated cat_ids.
 * - `id_uppercat = X` (normal) / the recency filter (`recent_cats`,
 *   {@see CategoryService::isRecentCategory()}) and `ORDER BY` (
 *   {@see CategoryService::compareByRank()} / `compareByGlobalRank()`) are
 *   PHP-side predicates/sorts over the cached tree instead of SQL. Pagination
 *   is `array_slice()` over the filtered+sorted set (`count()` replaces
 *   `SQL_CALC_FOUND_ROWS`/`FOUND_ROWS()`).
 * - `user_representative_picture_id` -- a real, stateful write-back cache
 *   (not a pure rollup value; see the plan's finding 6) -- moves to
 *   `CachePools::categoryTree()`, distinctly key-prefixed (`repr_*`) from
 *   the tree cache's own `tree_*` keys, replacing the `\Piwigo\Db\MysqliDb::massUpdates()` write
 *   onto `user_cache_categories`.
 * - The representative-image fallback chain, privacy-level re-pick,
 *   `$conf['display_fromto']` query, and all template-variable building are
 *   unaffected and port unchanged.
 */
final class CategoryCatsRenderer
{
    public function __construct(
        private readonly FilterUpdaterInterface $filterUpdater,
        private readonly HtmlRenderingInterface $htmlRenderer,
    ) {}

    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var Logger $logger
         * @var array<string, mixed> $page
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $conf, $logger, $page, $template, $user;

        $conn = DbConnection::build();
        $categoryRepo = new CategoryRepository($conn);
        $categoryService = new CategoryService(
            $categoryRepo,
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
        );
        $reprPool = CachePools::categoryTree();
        $treeCache = new CategoryTreeCache($categoryService, $categoryRepo, $reprPool);

        $userId = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;
        $isRecentCats = $page['section'] === 'recent_cats';

        $tree = $treeCache->getForUser($user);

        if ($isRecentCats) {
            $recentPeriod = is_numeric($user['recent_period'] ?? null) ? (int) $user['recent_period'] : 0;
            $lastPhotoDate = is_string($user['last_photo_date'] ?? null) ? $user['last_photo_date'] : null;
            $now = \DateTimeImmutable::createFromMutable(pwg_now());

            $filtered = array_filter($tree, static function (array $row) use ($recentPeriod, $lastPhotoDate, $now): bool {
                $countImages = is_numeric($row['count_images'] ?? null) ? (int) $row['count_images'] : 0;
                if ($countImages <= 0) {
                    return false;
                }

                $dateLast = is_string($row['date_last'] ?? null) ? $row['date_last'] : null;

                return CategoryService::isRecentCategory($dateLast, $recentPeriod, $lastPhotoDate, $now);
            });
        } else {
            $pageCategory = $page['category'] ?? null;
            $targetId = null;
            if (is_array($pageCategory) && is_numeric($pageCategory['id'] ?? null)) {
                $targetId = (int) $pageCategory['id'];
            }

            $filtered = array_filter($tree, static function (array $row) use ($targetId): bool {
                $countImages = is_numeric($row['count_images'] ?? null) ? (int) $row['count_images'] : 0;
                if ($countImages <= 0) {
                    return false;
                }

                $rowUppercat = $row['id_uppercat'] ?? null;

                return $targetId === null ? $rowUppercat === null : $rowUppercat === $targetId;
            });
        }

        if ($isRecentCats) {
            usort($filtered, CategoryService::compareByGlobalRank(...));
        } else {
            usort($filtered, CategoryService::compareByRank(...));
        }

        $totalCategories = count($filtered);
        $page['total_categories'] = $totalCategories;

        $nbCategoriesPage = is_numeric($conf['nb_categories_page'] ?? null) ? (int) $conf['nb_categories_page'] : 0;
        $startcat = is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0;

        $pageRows = array_slice($filtered, $startcat, $nbCategoriesPage);

        $catIds = [];
        foreach ($pageRows as $row) {
            $catId = $row['cat_id'] ?? null;
            if (is_numeric($catId)) {
                $catIds[] = (int) $catId;
            }
        }

        $fullById = [];
        foreach ($categoryRepo->findFullCategoriesByIds($catIds) as $full) {
            $fullId = $full['id'] ?? null;
            if (is_numeric($fullId)) {
                $fullById[(int) $fullId] = $full;
            }
        }

        $categories = [];
        $categoryIds = [];
        $imageIds = [];
        $userRepresentativeUpdatesFor = [];
        $datesOfCategory = [];

        foreach ($pageRows as $row) {
            $catId = $row['cat_id'] ?? null;
            $catId = is_numeric($catId) ? (int) $catId : 0;

            // Category was deleted between the rollup and the full-row
            // fetch a moment later -- vanishingly rare (two queries, no
            // transaction), skip rather than render a row with no name.
            if (! isset($fullById[$catId])) {
                continue;
            }

            $merged = array_merge($fullById[$catId], [
                'nb_images' => $row['nb_images'] ?? 0,
                'date_last' => $row['date_last'] ?? null,
                'max_date_last' => $row['max_date_last'] ?? null,
                'count_images' => $row['count_images'] ?? 0,
                'nb_categories' => $row['nb_categories'] ?? 0,
                'count_categories' => $row['count_categories'] ?? 0,
            ]);

            $maxDateLast = $merged['max_date_last'] ?? null;
            $dateLast = $merged['date_last'] ?? null;
            $merged['is_child_date_last'] = ($maxDateLast !== null && $dateLast !== null) ? ($maxDateLast > $dateLast) : false;

            $cachedRepresentative = $this->getCachedRepresentative($reprPool, $userId, $catId);

            $representativePictureId = $merged['representative_picture_id'] ?? null;
            $representativePictureIdSet = is_scalar($representativePictureId) && $representativePictureId !== '' && $representativePictureId !== '0' && $representativePictureId !== 0;

            if ($cachedRepresentative !== null) {
                $imageId = $cachedRepresentative;
            } elseif ($representativePictureIdSet) { // if a representative picture is set, it has priority
                $imageId = $representativePictureId;
            } elseif ((bool) $conf['allow_random_representative']) { // searching a random representant among elements in sub-categories
                $imageId = $categoryService->getRandomImageInCategory($merged);
            } elseif ($merged['count_categories'] > 0 and $merged['count_images'] > 0) { // at this point, count_images should always be >0 (used as condition above)
                // searching a random representant among representant of sub-categories
                $uppercats = is_string($merged['uppercats'] ?? null) ? $merged['uppercats'] : '';
                $query = '
SELECT representative_picture_id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userCacheCategories() . '
  ON id = cat_id and user_id = ' . $userId . '
  WHERE uppercats LIKE \'' . $uppercats . ',%\'
    AND representative_picture_id IS NOT NULL'
  . (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
      'visible_categories' => 'id',
  ], "\n  AND") . '
  ORDER BY ' . DB_RANDOM_FUNCTION . '()
  LIMIT 1
;';
                $subresult = \Piwigo\Db\MysqliDb::query($query);
                if (\Piwigo\Db\MysqliDb::numRows($subresult) > 0) {
                    $subrow = \Piwigo\Db\MysqliDb::fetchRow($subresult);
                    assert($subrow !== null);
                    [$imageId] = $subrow;
                }
            }

            // every branch above sets either a raw numeric DB value (string)
            // or the int|null return of getRandomImageInCategory(); normalize
            // to a numeric string once so $imageIds stays string-castable
            // for implode().
            if (isset($imageId)) {
                $imageId = is_numeric($imageId) ? (string) $imageId : null;
            }

            if (isset($imageId)) {
                if ((bool) $conf['representative_cache_on_subcats'] and $cachedRepresentative !== $imageId) {
                    $userRepresentativeUpdatesFor[$catId] = $imageId;
                }

                $merged['representative_picture_id'] = $imageId;
                $imageIds[] = $imageId;
                $categories[] = $merged;
                $categoryIds[] = $catId;
            } else {
                $logger->info(
                    sprintf(
                        '[%s] category #%u was listed but no image_id found, so it was skipped',
                        self::class,
                        $catId
                    )
                );
            }
            unset($imageId);
        }

        if ((bool) $conf['display_fromto']) {
            if (count($categoryIds) > 0) {
                $query = '
SELECT
    category_id,
    MIN(date_creation) AS `from`,
    MAX(date_creation) AS `to`
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::images() . ' ON image_id = id
  WHERE category_id IN (' . implode(',', $categoryIds) . ')
' . (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
                    'visible_categories' => 'category_id',
                    'visible_images' => 'id',
                ], 'AND') . '
  GROUP BY category_id
;';
                $datesOfCategory = \Piwigo\Db\MysqliDb::query2Array($query, 'category_id');
            }
        }

        // Note: unlike the original file, $categories is already in its
        // final sorted order here (sorted+paginated before this loop ran,
        // see above) -- no second usort($categories, global_rank_compare(...))
        // pass is needed for recent_cats.

        if (count($categories) > 0) {
            $infosOfImage = [];
            $newImageIds = [];

            $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', array_filter($imageIds, is_string(...))) . ')
;';
            $result = \Piwigo\Db\MysqliDb::query($query);
            while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                $imageRowId = $row['id'];
                if (! is_string($imageRowId)) {
                    // 'id' is the images table primary key (NOT NULL); this should never happen
                    continue;
                }

                if ($row['level'] <= $user['level']) {
                    $infosOfImage[$imageRowId] = $row;
                } else {
                    // problem: we must not display the thumbnail of a photo which has a
                    // higher privacy level than user privacy level
                    //
                    // * what is the represented category?
                    // * find a random photo matching user permissions
                    // * register it at the representative cache
                    // * set it as the representative_picture_id for the category

                    foreach ($categories as &$category) {
                        $categoryRepresentativePictureId = $category['representative_picture_id'] ?? null;
                        if ($imageRowId === $categoryRepresentativePictureId) {
                            // searching a random representant among elements in sub-categories
                            $newImageIdRaw = $categoryService->getRandomImageInCategory($category);
                            $newImageId = $newImageIdRaw !== null ? (string) $newImageIdRaw : null;

                            if ($newImageId !== null and ! in_array($newImageId, $imageIds, true)) {
                                $newImageIds[] = $newImageId;
                            }

                            if ((bool) $conf['representative_cache_on_level']) {
                                $categoryIdForUpdate = $category['id'] ?? null;
                                $categoryIdForUpdate = is_numeric($categoryIdForUpdate) ? (int) $categoryIdForUpdate : null;
                                if ($categoryIdForUpdate !== null) {
                                    $userRepresentativeUpdatesFor[$categoryIdForUpdate] = $newImageId;
                                }
                            }

                            $category['representative_picture_id'] = $newImageId;
                        }
                    }
                    unset($category);
                }
            }

            if (count($newImageIds) > 0) {
                $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $newImageIds) . ')
;';
                $result = \Piwigo\Db\MysqliDb::query($query);
                while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                    $newImageRowId = $row['id'];
                    if (! is_string($newImageRowId)) {
                        // 'id' is the images table primary key (NOT NULL); this should never happen
                        continue;
                    }

                    $infosOfImage[$newImageRowId] = $row;
                }
            }

            foreach ($infosOfImage as &$info) {
                $info['src_image'] = new SrcImage($info);
            }
            unset($info);
        }

        foreach ($userRepresentativeUpdatesFor as $updateCatId => $updateImageId) {
            $this->setCachedRepresentative($reprPool, $userId, $updateCatId, is_string($updateImageId) ? $updateImageId : null);
        }

        if (count($categories) > 0) {
            // Update filtered data
            $this->filterUpdater->updateCatsWithFilteredData($categories);

            $template->set_filename('index_category_thumbnails', 'mainpage_categories.tpl');

            trigger_notify('loc_begin_index_category_thumbnails', $categories);

            $tplThumbnailsVar = [];

            foreach ($categories as $category) {
                $categoryCountImagesGate = $category['count_images'] ?? null;
                if (! is_numeric($categoryCountImagesGate) || (int) $categoryCountImagesGate === 0) {
                    continue;
                }

                $renderedCategoryName = trigger_change(
                    'render_category_name',
                    $category['name'],
                    'subcatify_category_name'
                );
                $category['name'] = is_string($renderedCategoryName)
                    ? $renderedCategoryName
                    : (is_string($category['name']) ? $category['name'] : '');

                if ($isRecentCats) {
                    $categoryUppercats = $category['uppercats'];
                    $categoryUppercats = is_string($categoryUppercats) ? $categoryUppercats : '';
                    $name = $this->htmlRenderer->getCatDisplayNameCache($categoryUppercats, null);
                } else {
                    $name = $category['name'];
                }

                // 'representative_picture_id' is always a numeric string or
                // int by this point (see the normalization above); narrow
                // defensively to satisfy the array key type
                $representativePictureId = $category['representative_picture_id'];
                $representativePictureId = (is_string($representativePictureId) or is_int($representativePictureId))
                    ? $representativePictureId
                    : 0;
                $representativeInfos = $infosOfImage[$representativePictureId] ?? null;

                $catNbImages = $category['nb_images'];
                $catNbImages = is_numeric($catNbImages) ? (int) $catNbImages : 0;
                $catCountImages = $category['count_images'];
                $catCountImages = is_numeric($catCountImages) ? (int) $catCountImages : 0;
                $catCountCategories = $category['count_categories'];
                $catCountCategories = is_numeric($catCountCategories) ? (int) $catCountCategories : 0;

                $tplVar = array_merge($category, [
                    'ID' => $category['id'] /* obsolete */,
                    'representative' => $representativeInfos,
                    'TN_ALT' => strip_tags($category['name']),

                    'URL' => make_index_url(
                        [
                            'category' => $category,
                        ]
                    ),
                    'CAPTION_NB_IMAGES' => CategoryService::getDisplayImagesCount(
                        $catNbImages,
                        $catCountImages,
                        $catCountCategories,
                        true,
                        '<br>'
                    ),
                    'DESCRIPTION' => trigger_change(
                        'render_category_literal_description',
                        trigger_change(
                            'render_category_description',
                            $category['comment'] ?? null,
                            'subcatify_category_description'
                        )
                    ),
                    'NAME' => $name,
                ]);
                if ((bool) $conf['index_new_icon']) {
                    $categoryMaxDateLast = $category['max_date_last'];
                    $categoryMaxDateLast = is_string($categoryMaxDateLast) ? $categoryMaxDateLast : '';
                    $categoryIsChildDateLast = $category['is_child_date_last'];
                    $categoryIsChildDateLast = is_bool($categoryIsChildDateLast) ? $categoryIsChildDateLast : false;
                    $tplVar['icon_ts'] = \Piwigo\Core\RecentIconResolver::getIcon($categoryMaxDateLast, $categoryIsChildDateLast);
                }

                if ((bool) $conf['display_fromto']) {
                    $categoryIdKey = $category['id'];
                    $categoryIdKey = (is_string($categoryIdKey) or is_int($categoryIdKey)) ? $categoryIdKey : 0;
                    if (isset($datesOfCategory[$categoryIdKey])) {
                        $from = $datesOfCategory[$categoryIdKey]['from'];
                        $to = $datesOfCategory[$categoryIdKey]['to'];
                        $to = is_string($to) ? $to : '';

                        if (is_string($from) && $from !== '') {
                            $tplVar['INFO_DATES'] = \Piwigo\Core\DateHelper::formatFromto($from, $to);
                        }
                    }
                }

                $tplThumbnailsVar[] = $tplVar;
            }

            // pagination
            $tplThumbnailsVarSelection = $tplThumbnailsVar;

            $derivativeParams = trigger_change('get_index_album_derivative_params', ImageStdParams::get_by_type(IMG_THUMB));
            $tplThumbnailsVarSelection = trigger_change('loc_end_index_category_thumbnails', $tplThumbnailsVarSelection);
            $template->assign([
                'maxRequests' => $conf['max_requests'],
                'category_thumbnails' => $tplThumbnailsVarSelection,
                'derivative_params' => $derivativeParams,
            ]);

            $template->assign_var_from_handle('CATEGORIES', 'index_category_thumbnails');

            // navigation bar
            $page['cats_navigation_bar'] = [];
            if ($totalCategories > $nbCategoriesPage) {
                $page['cats_navigation_bar'] = (new \Piwigo\Core\PaginationService())->createNavigationBar(duplicate_index_url([], ['startcat']), $totalCategories, $startcat, $nbCategoriesPage, true, 'startcat');
            }

            $template->assign('cats_navbar', $page['cats_navigation_bar']);
        }

        \Piwigo\Core\TimingHelper::debug('end CategoryCatsRenderer::render()');
    }

    private function getCachedRepresentative(CacheItemPoolInterface $pool, int $userId, int $catId): ?string
    {
        $item = $pool->getItem('repr_' . $userId . '_' . $catId);
        if (! $item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) ? $value : null;
    }

    private function setCachedRepresentative(CacheItemPoolInterface $pool, int $userId, int $catId, ?string $imageId): void
    {
        $item = $pool->getItem('repr_' . $userId . '_' . $catId);
        $item->set($imageId);
        $pool->save($item);
    }
}
