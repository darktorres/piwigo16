<?php

declare(strict_types=1);

namespace Piwigo\Category;

use Piwigo\Cache\CachePools;
use Piwigo\Core\Env;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionService;
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
 *   the tree cache's own `tree_*` keys, replacing the former
 *   `MysqliDb::massUpdates()` write onto `user_cache_categories`.
 * - The representative-image fallback chain, privacy-level re-pick,
 *   `\Piwigo\Config\CurrentConfig::displayFromto()` query, and all template-variable building are
 *   unaffected and port unchanged.
 */
final readonly class CategoryCatsRenderer
{
    public function __construct(
        private FilterUpdaterInterface $filterUpdater,
        private HtmlRenderingInterface $htmlRenderer,
        private TemplateInterface $template,
        private CategoryRepository $categoryRepo,
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private ImageRepository $imageRepo,
        private UrlServiceInterface $urlService,
    ) {}

    /**
     * Legacy Coupling Retirement Track A batch A5.2e: $section/$category/
     * $startcat are explicit params instead of `global $page;` -- the one
     * real caller (GalleryController) already has them from
     * SectionContextRegistry::current(). Plain values rather than the
     * SectionContext object itself: Category is L2aCoreDomain and Section
     * is L2bExtendedDomain, so a SectionContext parameter here is a real
     * `deptrac analyse` DependsOnDisallowedLayer violation (L2a may not
     * depend on L2b), caught during A5.2e-8's verification gate.
     *
     * @param array<string, mixed>|null $category
     */
    public function render(string $section, ?array $category, int $startcat): void
    {
        $logger = \Piwigo\Core\CurrentLogger::get();
        $template = $this->template;

        $categoryRepo = $this->categoryRepo;
        $categoryService = $this->categoryService;
        $reprPool = CachePools::categoryTree();
        $treeCache = new CategoryTreeCache($categoryService, $categoryRepo, $reprPool);

        $currentUser = \Piwigo\Users\CurrentUser::get();
        $userId = $currentUser->id;
        $isRecentCats = $section === 'recent_cats';

        $tree = $treeCache->getForUser($currentUser->rawAttributes);

        if ($isRecentCats) {
            $recentPeriod = is_numeric($currentUser->rawAttributes['recent_period'] ?? null) ? (int) $currentUser->rawAttributes['recent_period'] : 0;
            $lastPhotoDate = is_string($currentUser->rawAttributes['last_photo_date'] ?? null) ? $currentUser->rawAttributes['last_photo_date'] : null;
            $now = \DateTimeImmutable::createFromMutable(Env::now());

            $filtered = array_filter($tree, static function (array $row) use ($recentPeriod, $lastPhotoDate, $now): bool {
                $countImages = is_numeric($row['count_images'] ?? null) ? (int) $row['count_images'] : 0;
                if ($countImages <= 0) {
                    return false;
                }

                $dateLast = is_string($row['date_last'] ?? null) ? $row['date_last'] : null;

                return CategoryService::isRecentCategory($dateLast, $recentPeriod, $lastPhotoDate, $now);
            });
        } else {
            $pageCategory = $category;
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

        $nbCategoriesPage = \Piwigo\Config\CurrentConfig::nbCategoriesPage();

        $pageRows = array_slice($filtered, $startcat, $nbCategoriesPage);

        $catIds = [];
        foreach ($pageRows as $row) {
            $catId = $row['cat_id'] ?? null;
            if (is_numeric($catId)) {
                $catIds[] = (int) $catId;
            }
        }

        // findFullCategoriesByIds() (P17-23 Stage 1b) returns typed Category
        // projections -- unboxed to array here since $fullById feeds
        // array_merge() below, which a readonly object can't participate in.
        $fullById = [];
        foreach ($categoryRepo->findFullCategoriesByIds($catIds) as $full) {
            $fullById[$full->id] = $full->toArray();
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

            $representativePictureId = $merged['representative_picture_id'];
            $representativePictureIdSet = $representativePictureId !== null && $representativePictureId !== 0;

            if ($cachedRepresentative !== null) {
                $imageId = $cachedRepresentative;
            } elseif ($representativePictureIdSet) { // if a representative picture is set, it has priority
                $imageId = $representativePictureId;
            } elseif (\Piwigo\Config\CurrentConfig::allowRandomRepresentative()) { // searching a random representant among elements in sub-categories
                $imageId = $categoryService->getRandomImageInCategory($merged);
            } elseif ($merged['count_categories'] > 0 and $merged['count_images'] > 0) { // at this point, count_images should always be >0 (used as condition above)
                // searching a random representant among representant of sub-categories
                $uppercats = $merged['uppercats'];
                $permissionCondition = $this->permissionService->getSqlConditionFandF([
                    'visible_categories' => 'id',
                ], "\n  AND");
                $found = $this->categoryRepo->findRandomRepresentativeIdAmongSubcategories($uppercats, $userId, $permissionCondition);
                if ($found !== null) {
                    $imageId = $found;
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
                if (\Piwigo\Config\CurrentConfig::representativeCacheOnSubcats() and $cachedRepresentative !== $imageId) {
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

        if (\Piwigo\Config\CurrentConfig::displayFromto()) {
            if (count($categoryIds) > 0) {
                $permissionCondition = $this->permissionService->getSqlConditionFandF([
                    'visible_categories' => 'category_id',
                    'visible_images' => 'id',
                ], 'AND');
                $datesOfCategory = $this->categoryRepo->findDateRangeByCategory($categoryIds, $permissionCondition);
            }
        }

        // Note: unlike the original file, $categories is already in its
        // final sorted order here (sorted+paginated before this loop ran,
        // see above) -- no second usort($categories, global_rank_compare(...))
        // pass is needed for recent_cats.

        // Only ever read below inside this same `if` block (where it's also
        // assigned) -- declared here so Psalm can see it's always defined
        // regardless of the nesting further down.
        $infosOfImage = [];
        if (count($categories) > 0) {
            $newImageIds = [];

            foreach ($this->imageRepo->findByIds(array_values(array_filter($imageIds, is_string(...)))) as $imageRowId => $row) {
                if ($row->level <= $currentUser->level) {
                    $infosOfImage[$imageRowId] = $row->toArray();
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
                        // Docs/PLAN-REPLAY-AUDIT.md gap-closure, 2026-07-23: found live
                        // while retyping ImageRepository::findByIds() -- PHP
                        // canonicalises a numeric string array key ('5') back to an
                        // int key (5), so $imageRowId (this foreach's key) is always
                        // an int, while $categoryRepresentativePictureId is always the
                        // numeric *string* this file normalizes representative_picture_id
                        // to (~line 212 above). The bare `===` between them was always
                        // false, so a category whose representative photo became too
                        // privacy-restricted for the current viewer never got a
                        // substitute picked -- silently missing, not swapped.
                        if ((string) $imageRowId === $categoryRepresentativePictureId) {
                            // searching a random representant among elements in sub-categories
                            $newImageIdRaw = $categoryService->getRandomImageInCategory($category);
                            $newImageId = $newImageIdRaw !== null ? (string) $newImageIdRaw : null;

                            if ($newImageId !== null and ! in_array($newImageId, $imageIds, true)) {
                                $newImageIds[] = $newImageId;
                            }

                            if (\Piwigo\Config\CurrentConfig::representativeCacheOnLevel()) {
                                $userRepresentativeUpdatesFor[$category['id']] = $newImageId;
                            }

                            $category['representative_picture_id'] = $newImageId;
                        }
                    }
                    unset($category);
                }
            }

            if (count($newImageIds) > 0) {
                foreach ($this->imageRepo->findByIds($newImageIds) as $newImageRowId => $row) {
                    $infosOfImage[$newImageRowId] = $row->toArray();
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

            \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_begin_index_category_thumbnails', $categories);

            $tplThumbnailsVar = [];

            foreach ($categories as $category) {
                $categoryCountImagesGate = $category['count_images'] ?? null;
                if (! is_numeric($categoryCountImagesGate) || (int) $categoryCountImagesGate === 0) {
                    continue;
                }

                $renderedCategoryName = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
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

                    'URL' => $this->urlService->makeIndexUrl(
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
                    'DESCRIPTION' => \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                        'render_category_literal_description',
                        \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                            'render_category_description',
                            $category['comment'] ?? null,
                            'subcatify_category_description'
                        )
                    ),
                    'NAME' => $name,
                ]);
                if (\Piwigo\Config\CurrentConfig::indexNewIcon()) {
                    $categoryMaxDateLast = $category['max_date_last'];
                    $categoryMaxDateLast = is_string($categoryMaxDateLast) ? $categoryMaxDateLast : '';
                    $categoryIsChildDateLast = $category['is_child_date_last'];
                    $categoryIsChildDateLast = is_bool($categoryIsChildDateLast) ? $categoryIsChildDateLast : false;
                    $recentPeriodForIcon = is_numeric($currentUser->rawAttributes['recent_period'] ?? null) ? (int) $currentUser->rawAttributes['recent_period'] : 0;
                    $tplVar['icon_ts'] = \Piwigo\Core\RecentIconResolver::getIcon($categoryMaxDateLast, $recentPeriodForIcon, $categoryIsChildDateLast);
                }

                if (\Piwigo\Config\CurrentConfig::displayFromto()) {
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

            $derivativeParams = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_index_album_derivative_params', ImageStdParams::get_by_type(ImageStdParams::THUMB));
            $tplThumbnailsVarSelection = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('loc_end_index_category_thumbnails', $tplThumbnailsVarSelection);
            $template->assign([
                'maxRequests' => \Piwigo\Config\CurrentConfig::maxRequests(),
                'category_thumbnails' => $tplThumbnailsVarSelection,
                'derivative_params' => $derivativeParams,
            ]);

            $template->assign_var_from_handle('CATEGORIES', 'index_category_thumbnails');

            // navigation bar
            $catsNavigationBar = [];
            if ($totalCategories > $nbCategoriesPage) {
                $catsNavigationBar = new \Piwigo\Core\PaginationService()
                    ->createNavigationBar($this->urlService->duplicateIndexUrl([], ['startcat']), $totalCategories, $startcat, $nbCategoriesPage, true, 'startcat');
            }

            $template->assign('cats_navbar', $catsNavigationBar);
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
