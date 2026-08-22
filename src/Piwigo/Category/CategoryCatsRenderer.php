<?php

declare(strict_types=1);

namespace Piwigo\Category;

use DateTimeImmutable;
use Piwigo\Cache\CategoryTreeCachePool;
use Piwigo\Category\Event\IndexCategoryThumbnailsRendered;
use Piwigo\Category\Event\IndexCategoryThumbnailsRendering;
use Piwigo\Category\Event\RenderCategoryDescription;
use Piwigo\Category\Event\RenderCategoryLiteralDescription;
use Piwigo\Category\Event\RenderCategoryName;
use Piwigo\Category\Projection\CategoryCatsNavbarPageContext;
use Piwigo\Category\Projection\CategoryCatsResult;
use Piwigo\Category\Projection\CategoryInfo;
use Piwigo\Category\Projection\RandomImageCategoryQuery;
use Piwigo\Common\Enum\Section;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Env;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PaginationService;
use Piwigo\Core\ProcessCache;
use Piwigo\Core\RecentIconResolver;
use Piwigo\Core\RequestMetrics;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Renders the main page's subcategory thumbnails (or the recent-albums
 * listing).
 *
 * - The `user_cache_categories` rollup columns (nb_images/count_images/
 *   count_categories/max_date_last/date_last) come from {@see
 *   CategoryTreeCache::getForUser()} -- the same per-user tree the menubar
 *   render on this same page load already warms.
 * - The remaining `categories` columns (`c.*`) come from a fresh {@see
 *   CategoryRepository::findFullCategoriesByIds()} call, scoped to only the
 *   current page's already-paginated cat_ids -- CategoryTreeCache's own
 *   cached row does not carry them, by design (adding them there would
 *   bloat every cached tree entry for a caller this narrow).
 * - Category selection (`id_uppercat = X` / the recency filter, {@see
 *   CategoryService::isRecentCategory()}) and ordering ({@see
 *   CategoryService::compareByRank()} / `compareByGlobalRank()`) are
 *   PHP-side predicates/sorts over the cached tree. Pagination is
 *   `array_slice()` over the filtered+sorted set.
 * - `user_representative_picture_id` is a real, stateful write-back cache
 *   (not a pure rollup value), stored in `CategoryTreeCachePool`,
 *   distinctly key-prefixed (`repr_*`) from the tree cache's own `tree_*`
 *   keys.
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
        private CurrentLogger $currentLogger,
        private EventDispatcher $eventDispatcher,
        private ImageStdParams $imageStdParams,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
        private Lang $lang,
        private ProcessCache $processCache,
        private RequestMetrics $requestMetrics,
        private CategoryTreeCachePool $categoryTreeCachePool,
    ) {}

    /**
     * $section/$category/$startcat are plain values rather than the
     * SectionContext object itself: Category is L2aCoreDomain and Section
     * is L2bExtendedDomain, so a SectionContext parameter here would be a
     * `deptrac analyse` DependsOnDisallowedLayer violation (L2a may not
     * depend on L2b). The one real caller (GalleryController) already has
     * these values from SectionContextRegistry::current().
     *
     * Returns raw category-thumbnail-grid data rather than rendering
     * `mainpage_categories.latte` itself and returning `Html`:
     * `Piwigo\Category\*` is L2aCoreDomain and may not depend on
     * `Renderer`/`View` (L3Presentation) directly, same split as
     * `CategoryDefaultRenderer`/`Piwigo\Picture\PictureMetadataRenderer`.
     * The `cats_navbar` ambient assign stays inside this method unchanged
     * -- it's a plain `assignContext()` call (no render involved), which
     * `TemplateInterface` (L1Infrastructure) already allows an L2a class
     * to call directly.
     */
    public function render(Section $section, ?CategoryInfo $category, int $startcat): ?CategoryCatsResult
    {
        $logger = $this->currentLogger->get();
        $template = $this->template;

        $categoryRepo = $this->categoryRepo;
        $categoryService = $this->categoryService;
        $reprPool = $this->categoryTreeCachePool;
        $treeCache = new CategoryTreeCache($categoryService, $categoryRepo, $reprPool);

        $user = $this->currentUser->get();
        $userId = $user->id;
        $isRecentCats = $section === Section::RecentCats;

        $tree = $treeCache->getForUser($user->id->value, $user->level, $user->forbiddenCategories);

        if ($isRecentCats) {
            $recentPeriod = is_numeric($user->rawAttributes['recent_period'] ?? null) ? (int) $user->rawAttributes['recent_period'] : 0;
            $lastPhotoDate = is_string($user->rawAttributes['last_photo_date'] ?? null) ? $user->rawAttributes['last_photo_date'] : null;
            $now = DateTimeImmutable::createFromMutable(Env::now());

            $filtered = array_filter($tree, static function (array $row) use ($recentPeriod, $lastPhotoDate, $now): bool {
                $countImages = $row['count_images'];
                if ($countImages <= 0) {
                    return false;
                }

                $dateLast = is_string($row['date_last']) ? $row['date_last'] : null;

                return CategoryService::isRecentCategory($dateLast, $recentPeriod, $lastPhotoDate, $now);
            });
        } else {
            $targetId = $category?->id;

            $filtered = array_filter($tree, static function (array $row) use ($targetId): bool {
                $countImages = $row['count_images'];
                if ($countImages <= 0) {
                    return false;
                }

                $rowUppercat = $row['id_uppercat'];

                return $targetId === null ? $rowUppercat === null : $rowUppercat === $targetId;
            });
        }

        if ($isRecentCats) {
            usort($filtered, CategoryService::compareByGlobalRank(...));
        } else {
            usort($filtered, CategoryService::compareByRank(...));
        }

        $totalCategories = count($filtered);

        $nbCategoriesPage = $this->currentConfig->nbCategoriesPage;

        $pageRows = array_slice($filtered, $startcat, $nbCategoriesPage);

        $catIds = [];
        foreach ($pageRows as $row) {
            $catIds[] = $row['cat_id'];
        }

        // findFullCategoriesByIds() returns typed Category
        // projections -- unboxed to array here since $fullById feeds
        // array_merge() below, which a readonly object can't participate in.
        $fullById = [];
        foreach ($categoryRepo->findFullCategoriesByIds($catIds) as $full) {
            $fullById[$full->id->value] = $full->toArray();
        }

        $categories = [];
        $categoryIds = [];
        $imageIds = [];
        $userRepresentativeUpdatesFor = [];
        $datesOfCategory = [];

        foreach ($pageRows as $row) {
            $catId = $row['cat_id'];

            // Category was deleted between the rollup and the full-row
            // fetch a moment later -- vanishingly rare (two queries, no
            // transaction), skip rather than render a row with no name.
            if (! isset($fullById[$catId])) {
                continue;
            }

            $merged = array_merge($fullById[$catId], [
                'nb_images' => $row['nb_images'],
                'date_last' => $row['date_last'],
                'max_date_last' => $row['max_date_last'],
                'count_images' => $row['count_images'],
                'nb_categories' => $row['nb_categories'],
                'count_categories' => $row['count_categories'],
            ]);

            $maxDateLast = $merged['max_date_last'];
            $dateLast = $merged['date_last'];
            $merged['is_child_date_last'] = ($maxDateLast !== null && $dateLast !== null) ? ($maxDateLast > $dateLast) : false;

            $cachedRepresentative = $this->getCachedRepresentative($reprPool, $userId, $catId);

            $representativePictureId = $merged['representative_picture_id'];
            $representativePictureIdSet = $representativePictureId !== null && $representativePictureId !== 0;

            if ($cachedRepresentative !== null) {
                $imageId = $cachedRepresentative;
            } elseif ($representativePictureIdSet) { // if a representative picture is set, it has priority
                $imageId = $representativePictureId;
            } elseif ($this->currentConfig->allowRandomRepresentative) { // searching a random representant among elements in sub-categories
                $imageId = $categoryService->getRandomImageInCategory(new RandomImageCategoryQuery(
                    id: CategoryId::from($catId),
                    uppercats: $merged['uppercats'],
                    countImages: $merged['count_images'],
                ));
            } elseif ($merged['count_categories'] > 0 and $merged['count_images'] > 0) { // at this point, count_images should always be >0 (used as condition above)
                // searching a random representant among representant of sub-categories
                $uppercats = $merged['uppercats'];
                $found = $this->categoryRepo->findRandomRepresentativeIdAmongSubcategories($uppercats, $this->permissionService->getPermissionCriteria());
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
                if ($this->currentConfig->representativeCacheOnSubcats and $cachedRepresentative !== $imageId) {
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

        if ($this->currentConfig->displayFromto) {
            if (count($categoryIds) > 0) {
                $datesOfCategory = $this->categoryRepo->findDateRangeByCategory($categoryIds, $this->permissionService->getPermissionCriteria());
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
                if ($row->level <= $user->level) {
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
                        $categoryRepresentativePictureId = $category['representative_picture_id'];
                        // ImageRepository::findByIds() -- PHP
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
                            $newImageIdRaw = $categoryService->getRandomImageInCategory(new RandomImageCategoryQuery(
                                id: CategoryId::from($category['id']),
                                uppercats: $category['uppercats'],
                                countImages: $category['count_images'],
                            ));
                            $newImageId = $newImageIdRaw !== null ? (string) $newImageIdRaw : null;

                            if ($newImageId !== null and ! in_array($newImageId, $imageIds, true)) {
                                $newImageIds[] = $newImageId;
                            }

                            if ($this->currentConfig->representativeCacheOnLevel) {
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

        $result = null;

        if (count($categories) > 0) {
            // Update filtered data
            $this->filterUpdater->updateCatsWithFilteredData($categories);

            $this->eventDispatcher->dispatch(new IndexCategoryThumbnailsRendering($categories));

            $tplThumbnailsVar = [];

            foreach ($categories as $category) {
                $categoryCountImagesGate = $category['count_images'] ?? null;
                if (! is_numeric($categoryCountImagesGate) || (int) $categoryCountImagesGate === 0) {
                    continue;
                }

                $nameEvent = $this->eventDispatcher->dispatch(new RenderCategoryName(is_string($category['name']) ? $category['name'] : '', 'subcatify_category_name'));
                $category['name'] = $nameEvent->categoryName;

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

                $categoryComment = $category['comment'] ?? null;
                $descriptionEvent = $this->eventDispatcher->dispatch(new RenderCategoryDescription(is_string($categoryComment) ? $categoryComment : null, 'subcatify_category_description'));
                $literalDescriptionEvent = $this->eventDispatcher->dispatch(new RenderCategoryLiteralDescription($descriptionEvent->categoryDescription));

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
                        $this->lang,
                        $catNbImages,
                        $catCountImages,
                        $catCountCategories,
                        true,
                        '<br>'
                    ),
                    'DESCRIPTION' => $literalDescriptionEvent->description,
                    'NAME' => $name,
                ]);
                if ($this->currentConfig->indexNewIcon) {
                    $categoryMaxDateLast = $category['max_date_last'];
                    $categoryMaxDateLast = is_string($categoryMaxDateLast) ? $categoryMaxDateLast : '';
                    $categoryIsChildDateLast = $category['is_child_date_last'];
                    $categoryIsChildDateLast = is_bool($categoryIsChildDateLast) ? $categoryIsChildDateLast : false;
                    $recentPeriodForIcon = is_numeric($user->rawAttributes['recent_period'] ?? null) ? (int) $user->rawAttributes['recent_period'] : 0;
                    $tplVar['icon_ts'] = RecentIconResolver::getIcon($categoryMaxDateLast, $recentPeriodForIcon, $this->processCache, $this->lang, $categoryIsChildDateLast);
                }

                if ($this->currentConfig->displayFromto) {
                    $categoryIdKey = $category['id'];
                    $categoryIdKey = (is_string($categoryIdKey) or is_int($categoryIdKey)) ? $categoryIdKey : 0;
                    if (isset($datesOfCategory[$categoryIdKey])) {
                        $from = $datesOfCategory[$categoryIdKey]->from;
                        $to = $datesOfCategory[$categoryIdKey]->to;
                        $to = is_string($to) ? $to : '';

                        if (is_string($from) && $from !== '') {
                            $tplVar['INFO_DATES'] = DateHelper::formatFromto($from, $to);
                        }
                    }
                }

                $tplThumbnailsVar[] = $tplVar;
            }

            // pagination
            $tplThumbnailsVarSelection = $tplThumbnailsVar;

            $derivativeParams = $this->imageStdParams->getByType(ImageStdParams::THUMB);
            $tplThumbnailsVarSelection = $this->eventDispatcher->dispatch(new IndexCategoryThumbnailsRendered($tplThumbnailsVarSelection))
                ->tplThumbnailsVar;
            $result = new CategoryCatsResult(
                maxRequests: $this->currentConfig->maxRequests,
                categoryThumbnails: $tplThumbnailsVarSelection,
                derivativeParams: $derivativeParams,
            );

            // navigation bar
            $catsNavigationBar = [];
            if ($totalCategories > $nbCategoriesPage) {
                // PaginationService takes this file's own constructor-injected
                // $currentConfig directly (same instance nbCategoriesPage()/
                // displayFromto()/etc. read elsewhere in this method).
                $catsNavigationBar = new PaginationService($this->currentConfig)
                    ->createNavigationBar($this->urlService->duplicateIndexUrl([], ['startcat']), $totalCategories, $startcat, $nbCategoriesPage, true, 'startcat');
            }

            $template->assignContext(new CategoryCatsNavbarPageContext($catsNavigationBar));
        }

        TimingHelper::debug('end CategoryCatsRenderer::render()', $this->requestMetrics);

        return $result;
    }

    private function getCachedRepresentative(CacheItemPoolInterface $pool, UserId $userId, int $catId): ?string
    {
        $item = $pool->getItem('repr_' . $userId->value . '_' . $catId);
        if (! $item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) ? $value : null;
    }

    private function setCachedRepresentative(CacheItemPoolInterface $pool, UserId $userId, int $catId, ?string $imageId): void
    {
        $item = $pool->getItem('repr_' . $userId->value . '_' . $catId);
        $item->set($imageId);
        $pool->save($item);
    }
}
