<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\CategoryTreeCachePool;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Cache\PermissionsCachePool;
use Piwigo\Category\CategoryAdminListCriteria;
use Piwigo\Category\CategoryListCriteria;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryTreeCache;
use Piwigo\Category\Projection\Category;
use Piwigo\Category\Projection\RandomImageCategoryQuery;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Picture\RenderElementName;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Image\CategoryImagesCriteria;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\SqlCondition;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;

/**
 * `pwg.categories.*` WS methods (12 registrations) -- registered via
 * callable arrays in include/ws_default_methods.inc.php.
 */
final readonly class Categories
{
    public function __construct(
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private ActivityService $activityService,
        private UserService $userService,
        private ImageService $imageService,
        private CategoryRepository $categoryRepository,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private AccessControl $accessControl,
        private EntityManagerInterface $entityManager,
        private SessionService $sessionService,
        private PageState $pageState,
        private ImageStdParams $imageStdParams,
        private WsHelper $wsHelper,
        private PermissionsCachePool $permissionsCachePool,
        private CategoryTreeCachePool $categoryTreeCachePool,
    ) {}

    /**
     * getList()'s rollup columns (nb_images/count_images/count_categories/
     * date_last/max_date_last) are not real `categories` columns --
     * computed here via CategoryTreeCache, the same computation
     * CategoryCatsRenderer/CategoryService::getCategoriesMenu() rely on.
     * Used for the public/normal branches, whose forbidden_categories
     * value matches what those other consumers already cache under the
     * same user id (see getList()'s own call site for why the admin
     * branch deliberately bypasses this instead).
     */
    private function categoryTreeCache(): CategoryTreeCache
    {
        return new CategoryTreeCache($this->categoryService, $this->categoryRepository, $this->categoryTreeCachePool);
    }

    /**
     * Explodes a `forbidden_categories`-shaped CSV id string into a bound
     * `NOT IN (:x)` list -- `[0]` (a category id that never exists, a
     * no-op exclusion) when $csv is empty, since `NOT IN ()` is invalid
     * SQL.
     *
     * @return list<int>
     */
    private static function csvToIntList(string $csv): array
    {
        $ids = array_map(intval(...), array_filter(explode(',', $csv), is_numeric(...)));

        return $ids === [] ? [0] : array_values($ids);
    }

    /**
     * A per-user "remembered random representative" override, not just a
     * permission-visibility cache -- see getList()'s own write-back logic
     * below. Shares the same CategoryTreeCachePool pool and
     * `'repr_' . $userId . '_' . $catId'` key format as
     * Category\CategoryCatsRenderer's own getCachedRepresentative()/
     * setCachedRepresentative(), deliberately not a separate pool, so a
     * user sees the same remembered representative whether browsing the
     * website or calling this WS method.
     */
    private function getCachedRepresentative(int $userId, int $catId): ?string
    {
        $item = $this->categoryTreeCachePool->getItem('repr_' . $userId . '_' . $catId);
        if (! $item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) ? $value : null;
    }

    private function setCachedRepresentative(int $userId, int $catId, ?string $imageId): void
    {
        $pool = $this->categoryTreeCachePool;
        $item = $pool->getItem('repr_' . $userId . '_' . $catId);
        $item->set($imageId);
        $pool->save($item);
    }

    /**
     * API method
     * Returns images per category
     *
     * @param array{cat_id: array<int, int>, recursive: bool, per_page: int, page: int, order: string|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     *   cat_id: WsParamFlag::FORCE_ARRAY|WsParamType::INT|WsParamType::POSITIVE, null default
     *   -- makeArrayParam() converts the null default to [], always a list of
     *   positive ints. f_* keys: the shared $f_params block merged into this
     *   registration, see WsHelper::stdImageSqlFilterCriteria()/WsHelper::stdImageSqlOrder().
     * @return WsErrorResponse|array{paging: NamedStruct, images: NamedArray}
     */
    public function getImages(array $params, Server &$service): WsErrorResponse|array
    {
        $urlService = $this->urlService;

        $params['cat_id'] = array_unique($params['cat_id']);

        if (count($params['cat_id']) > 0) {
            // do the categories really exist?
            $db_cat_ids = $this->categoryService->getExistingIds(array_values($params['cat_id']));
            $missing_cat_ids = array_diff($params['cat_id'], $db_cat_ids);

            if (count($missing_cat_ids) > 0) {
                return new WsErrorResponse(404, 'cat_id {' . implode(',', $missing_cat_ids) . '} not found');
            }
        }

        $images = [];
        $image_ids = [];
        $total_images = 0;

        // ------------------------------------------------- get the related categories
        $catClauses = [];
        $catParams = [];
        // CategoryRepository::findIdsAndImageOrderWithConditions() runs
        // these clauses as real DQL, so they're DQL property paths (`c.`-
        // prefixed) rather than raw SQL column names, and the regex match
        // uses the portable REGEXP() DQL function ({@see
        // \Piwigo\Db\DqlFunction\RegexpFunction}) rather than a
        // hand-resolved per-platform operator string.
        foreach ($params['cat_id'] as $i => $cat_id) {
            if ($params['recursive']) {
                $catClauses[] = 'REGEXP(c.uppercats, :catUppercatsLike' . $i . ') = true';
                $catParams['catUppercatsLike' . $i] = '(^|,)' . $cat_id . '(,|$)';
            } else {
                $catClauses[] = 'c.id = :catId' . $i;
                $catParams['catId' . $i] = $cat_id;
            }
        }
        $catConditions = [];
        if ($catClauses !== []) {
            $catConditions[] = new SqlCondition('(' . implode("\n    OR ", $catClauses) . ')', $catParams);
        }
        $catConditions[] = $this->permissionService->getPermissionCriteria()->forbiddenCategoriesCondition('c.id');

        $cats = [];
        foreach ($this->categoryService->getIdsAndImageOrderWithConditions($catConditions) as $row) {
            $cats[$row->id] = $row;
        }

        // -------------------------------------------------------- get the images
        if ($cats !== []) {
            $permissionCriteria = $this->permissionService->getPermissionCriteria();
            $imagesCriteria = new CategoryImagesCriteria(
                filterCriteria: $this->wsHelper->stdImageSqlFilterCriteria($params, $service),
                categoryIds: array_keys($cats),
                // visible_images's own old fallthrough into forbidden_images
                // (fieldName 'i.id' -> the images-table's own level check) --
                // see PermissionCriteria's own docblock.
                visibleImagesCondition: SqlCondition::combine(
                    'AND',
                    $permissionCriteria->visibleImagesCondition('i.id'),
                    $permissionCriteria->maxLevelCondition('i.level'),
                ),
            );

            $order_by = $this->wsHelper->stdImageSqlOrder($params, 'i.');
            if ($order_by === ''
                  and count($params['cat_id']) === 1
                  and ($cats[$params['cat_id'][0]]->imageOrder ?? null) !== null
            ) {
                $order_by = $cats[$params['cat_id'][0]]->imageOrder;
            }
            $order_by = $order_by === '' ? $this->currentConfig->orderBy : 'ORDER BY ' . $order_by;
            $favorite_ids = $urlService->getUserFavorites();

            $paginated_images = $this->imageService->getWithConditionsPaginated(
                $imagesCriteria,
                $order_by,
                $params['per_page'],
                $params['per_page'] * $params['page']
            );
            $rows = $paginated_images->rows;

            foreach ($rows as $image_row) {
                // id is images.id, a NOT NULL primary key. Native int under DBAL
                // (vs. guaranteed string under legacy mysqli), so cast
                // instead of asserting string.
                assert(is_numeric($image_row['id']));
                $image_row_id = (int) $image_row['id'];
                $image_ids[] = $image_row_id;

                $image = [];
                $image['is_favorite'] = isset($favorite_ids[$image_row_id]);
                foreach (['id', 'width', 'height', 'hit'] as $k) {
                    if (isset($image_row[$k])) {
                        $image[$k] = is_numeric($image_row[$k]) ? (int) $image_row[$k] : 0;
                    }
                }
                foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                    $image[$k] = $image_row[$k] ?? null;
                }

                $nameEvent = $this->eventDispatcher->dispatchChange(new RenderElementName(is_string($image['name']) ? $image['name'] : '', __FUNCTION__));
                $image['name'] = strip_tags($nameEvent->elementName);
                $descriptionEvent = $this->eventDispatcher->dispatchChange(new RenderElementDescription(is_string($image['comment']) ? $image['comment'] : '', __FUNCTION__));
                $image['comment'] = $descriptionEvent->elementDescription;

                $image = array_merge($image, $this->wsHelper->stdGetUrls($image_row, $urlService));

                $images[] = $image;
            }

            $total_images = $paginated_images->total ?? 0;

            // let's take care of adding the related albums to each photo
            if (count($image_ids) > 0) {
                $category_ids = [];

                // find the complete list (given permissions) of albums linked to photos
                $image_category_rows = $this->imageService->getCategoryLinksForImageIdsWithCondition(
                    $image_ids,
                    $this->permissionService->getPermissionCriteria()
                );
                $categories_of_image = [];
                foreach ($image_category_rows as $image_category_row) {
                    $category_ids[] = $image_category_row->categoryId;
                    $categories_of_image[$image_category_row->imageId][] = $image_category_row->categoryId;
                }

                $details_for_category = [];
                if (count($category_ids) > 0) {
                    // find details (for URL generation) about each album
                    $details_for_category = array_column(
                        $this->categoryService->getCategoriesByIds(array_values(array_unique($category_ids))),
                        null,
                        'id'
                    );
                }

                foreach ($images as $idx => $img) {
                    $image_cats = [];

                    $image_id = $img['id'] ?? null;
                    if (! is_int($image_id)) {
                        continue;
                    }

                    // it should not be possible at this point, but let's consider a photo can be in no album
                    if (! isset($categories_of_image[$image_id])) {
                        continue;
                    }

                    foreach ($categories_of_image[$image_id] as $cat_id) {
                        $url = $urlService->makeIndexUrl([
                            'category' => $details_for_category[$cat_id],
                        ]);

                        $page_url = $urlService->makePictureUrl(
                            [
                                'category' => $details_for_category[$cat_id],
                                'image_id' => $image_id,
                                'image_file' => $img['file'] ?? null,
                            ]
                        );

                        $image_cats[] = [
                            'id' => $cat_id,
                            'url' => $url,
                            'page_url' => $page_url,
                        ];
                    }

                    $images[$idx]['categories'] = new NamedArray(
                        $image_cats,
                        'category',
                        ['id', 'url', 'page_url']
                    );
                }
            }
        }

        return [
            'paging' => new NamedStruct(
                [
                    'page' => $params['page'],
                    'per_page' => $params['per_page'],
                    'count' => count($images),
                    'total_count' => $total_images,
                ]
            ),
            'images' => new NamedArray(
                $images,
                'image',
                $this->wsHelper->stdGetImageXmlAttributes()
            ),
        ];
    }

    /**
     * API method
     * Returns a list of categories
     *
     * @param array{cat_id: int|null, recursive: bool, public: bool, tree_output: bool, fullname: bool, thumbnail_size: string, search: string|null, limit: int|null, ...} $params
     *   all keys have a 'default' key in ws.php's registration, so all are
     *   always present (never absent), and cat_id/search/limit's null default
     *   is a real possible runtime value since they have no non-null-forcing
     *   type flag beyond INT|POSITIVE (which still allows the null default
     *   through unchanged).
     * Genuinely dynamic response shape: tree_output controls whether
     * categories nest recursively or come back flat, same rationale as
     * Ws\Users::getList()'s own client-controlled response shape.
     * @return WsErrorResponse|array<int|string, mixed>
     */
    public function getList(array $params, Server &$service): WsErrorResponse|array
    {
        $currentUser = $this->currentUser->get();

        $categoryService = $this->categoryService;

        if (! in_array($params['thumbnail_size'], array_keys($this->imageStdParams->getDefinedTypeMap()), true)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid thumbnail_size');
        }

        if (! in_array($params['limit'], [null, 0], true) and $params['recursive']) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Cannot use both recursive and limit parameters at the same time');
        }

        $output = [];
        $user_id = $currentUser->id->value;
        // Which user's own "remembered random representative" cache entry
        // (CategoryTreeCachePool, see below) each row's
        // user_representative_picture_id is read from/written to --
        // overridden to the guest identity in the public branch.
        $repr_user_id = $user_id;

        // Each of the 3 branches below computes its own explicit `id NOT
        // IN (forbidden)` condition against $forbiddenCategoryIds.
        //
        // nb_images/count_images/count_categories/date_last/max_date_last
        // are NOT real `categories` columns -- $rollupByCatId supplies
        // them per-row below, computed per-branch since each identity's
        // forbidden-categories value differs (see each branch's own
        // comment).
        $rollupByCatId = [];
        $forbiddenCategoryIds = [];
        $publicOnly = false;

        if ($params['public']) {
            $publicOnly = true;

            $repr_user_id = $this->currentConfig->guestId;
            // UserService::getUserData() computes the same effective
            // (widened) forbidden-categories value for any given user id
            // that CurrentUser::forbiddenCategories already holds for the
            // current request's own user -- reused here since the guest
            // identity isn't CurrentUser. Also reused (unmodified) below
            // for the rollup: it's the same canonical value any other
            // guest-facing consumer of CategoryTreeCache already
            // computes/caches for this same user id, so feeding it back
            // into that same cache pool here cannot desync it.
            $guest_userdata = $this->userService->getUserData(UserId::from($repr_user_id));
            $guest_forbidden_categories = $guest_userdata['forbidden_categories'] ?? '0';
            $guest_forbidden_categories = is_string($guest_forbidden_categories) ? $guest_forbidden_categories : '0';
            $forbiddenCategoryIds = self::csvToIntList($guest_forbidden_categories);
            $rollupByCatId = $this->categoryTreeCache()
                ->getForUser($guest_userdata);
        } elseif ($this->accessControl->isAdmin()) {
            // in this very specific case, we don't want to hide empty
            // categories. Function calculate_permissions will only return
            // categories that are either locked or private and not permitted
            //
            // calculate_permissions does not consider empty categories as forbidden
            $forbidden_categories = new ForbiddenCategoriesCache($this->permissionService, $this->permissionsCachePool)
                ->getForUser($user_id, $currentUser->status->value);
            $forbiddenCategoryIds = self::csvToIntList($forbidden_categories);
            // Deliberately NOT CategoryTreeCache: that pool is keyed only
            // by user id, and this branch's forbidden_categories is the
            // narrower structural value above, not the wider "effective"
            // one CurrentUser::forbiddenCategories/EffectiveForbiddenCategoriesCache
            // compute for this same admin's user id elsewhere (e.g. while
            // browsing the site normally) -- sharing the pool would let
            // whichever computation runs first silently poison the other's
            // cache entry for up to 300s. Computed directly (uncached);
            // getComputedCategories()'s own LEFT JOIN never drops an empty
            // category, so this also satisfies the comment above.
            $admin_userdata = $currentUser->toUserArray();
            $admin_userdata['forbidden_categories'] = $forbidden_categories;
            $rollupByCatId = $categoryService->getComputedCategories($admin_userdata, null)['categories'];
        } else {
            $forbiddenCategoryIds = self::csvToIntList($currentUser->forbiddenCategories);
            // $currentUser->forbiddenCategories IS the same effective value
            // EffectiveForbiddenCategoriesCache computes for this user id --
            // the same value any other CategoryTreeCache consumer for this
            // user already relies on, safe to share.
            $rollupByCatId = $this->categoryTreeCache()
                ->getForUser($currentUser->toUserArray());
        }

        $search_term = (isset($params['search']) and $params['search'] !== '') ? $params['search'] : null;
        $catIdVo = CategoryId::tryFrom($params['cat_id']);

        $criteria = new CategoryListCriteria(
            catId: $catIdVo,
            recursive: $params['recursive'],
            forbiddenCategoryIds: $forbiddenCategoryIds,
            publicOnly: $publicOnly,
        );

        $paginated_cats = $categoryService->getListForWs(
            $criteria,
            $search_term,
            $this->currentConfig->linkedAlbumSearchLimit,
            $params['limit'],
            $catIdVo instanceof CategoryId
        );
        $rows = $paginated_cats->rows;

        if (isset($params['limit'])) {
            $result_count = $paginated_cats->total ?? 0;
            if ($catIdVo instanceof CategoryId) {
                --$result_count;
            }
            $output['limit'] = [
                'limited_to' => $params['limit'],
                'total_cats' => intval($result_count),
                'remaining_cats' => $result_count > $params['limit'] ? $result_count - $params['limit'] : 0,
            ];
        }

        // management of the album thumbnail -- starts here
        $image_ids = [];
        $categories = [];
        $user_representative_updates_for = [];
        // management of the album thumbnail -- stops here

        $cats = [];
        $urlService = $this->urlService;
        foreach ($rows as $row) {
            // The rollup columns (nb_images/count_images/count_categories/
            // date_last/max_date_last) aren't real `categories` columns --
            // merge them in from the per-branch rollup computed above,
            // keyed by cat_id, before any of the row shaping below reads
            // them. A row absent from the rollup can't happen here:
            // $rollupByCatId is computed with the same
            // $forbiddenCategoryIds value used to select $rows above, so
            // every row here also has a rollup entry -- ?? 0/null below is
            // defensive, not expected.
            $catId = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
            $rollup = $rollupByCatId[$catId] ?? [];
            $row['nb_images'] = $rollup['nb_images'] ?? 0;
            $row['total_nb_images'] = $rollup['count_images'] ?? 0;
            $row['count_images'] = $rollup['count_images'] ?? 0;
            $row['count_categories'] = $rollup['count_categories'] ?? 0;
            $row['nb_categories'] = $rollup['count_categories'] ?? 0;
            $row['date_last'] = $rollup['date_last'] ?? null;
            $row['max_date_last'] = $rollup['max_date_last'] ?? null;

            $row['url'] = $urlService->makeIndexUrl(
                [
                    'category' => $row,
                ]
            );
            foreach (['id', 'nb_images', 'total_nb_images', 'nb_categories'] as $key) {
                $row[$key] = is_numeric($row[$key] ?? null) ? (int) ($row[$key] ?? 0) : 0;
            }

            // See getCachedRepresentative()'s own docblock.
            $row['user_representative_picture_id'] = $this->getCachedRepresentative($repr_user_id, $row['id']);

            // uppercats is a NOT NULL column of the categories table --
            // asserted here (unconditionally) rather than only inside the
            // $params['fullname'] branch below, since it's also read
            // further down outside that branch (the representative-picture
            // LIKE query).
            assert(is_string($row['uppercats']));

            if ($params['fullname']) {
                $row['name'] = strip_tags($this->htmlRenderer->getCatDisplayNameCache($row['uppercats'], null));
            } else {
                $row['name_raw'] = $row['name'];

                $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', 'ws_categories_getList'));
                $row['name'] = strip_tags($nameEvent->categoryName);
            }

            $row['comment_raw'] = $row['comment'];

            $descriptionEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryDescription(is_string($row['comment']) ? $row['comment'] : null, 'ws_categories_getList'));
            $row['comment'] = $descriptionEvent->categoryDescription ?? '';

            // management of the album thumbnail -- starts here
            //
            // on branch 2.3, the algorithm is duplicated from
            // include/category_cats, but we should use a common code for Piwigo 2.4
            //
            // warning : if the API method is called with $params['public'], the
            // album thumbnail may be not accurate. The thumbnail can be viewed by
            // the connected user, but maybe not by the guest. Changing the
            // filtering method would be too complicated for now. We will simply
            // avoid to persist the user_representative_picture_id in the database
            // if $params['public']
            if (is_numeric($row['user_representative_picture_id']) && (int) $row['user_representative_picture_id'] !== 0) {
                $image_id = $row['user_representative_picture_id'];
            } elseif (is_numeric($row['representative_picture_id']) && (int) $row['representative_picture_id'] !== 0) { // if a representative picture is set, it has priority
                $image_id = $row['representative_picture_id'];
            } elseif ($this->currentConfig->allowRandomRepresentative) {
                // searching a random representant among elements in sub-categories
                $catIdVoForRandom = CategoryId::tryFrom($catId);
                $image_id = $catIdVoForRandom instanceof CategoryId
                    ? $categoryService->getRandomImageInCategory(new RandomImageCategoryQuery(
                        id: $catIdVoForRandom,
                        uppercats: $row['uppercats'],
                        countImages: $row['count_images'],
                    ))
                    : null;
            } else { // searching a random representant among representant of sub-categories
                if ($row['count_categories'] > 0 and $row['count_images'] > 0) {
                    // Same query as CategoryCatsRenderer's own identical
                    // lookup, shared via CategoryRepository::
                    // findRandomRepresentativeIdAmongSubcategories().
                    $subrow_image_id = $categoryService->getRandomRepresentativeIdAmongSubcategories(
                        $row['uppercats'],
                        $this->permissionService->getPermissionCriteria()
                    );

                    if ($subrow_image_id !== null) {
                        $image_id = $subrow_image_id;
                    }
                }
            }

            // $image_id can come from a DB column (mixed under DBAL) or
            // getRandomImageInCategory()'s ?int -- normalize to a definite
            // int here, once, since it's always an images.id PK regardless
            // of source.
            if (isset($image_id) && is_numeric($image_id)) {
                $image_id = (int) $image_id;

                // user_representative_picture_id is a cache string
                // (getCachedRepresentative()'s own return type) -- compare
                // as int against $image_id rather than the
                // (always-mismatched) string, or every row would be
                // flagged "changed" here.
                $cached_representative_id = is_numeric($row['user_representative_picture_id'])
                    ? (int) $row['user_representative_picture_id']
                    : null;

                if ($this->currentConfig->representativeCacheOnSubcats and $cached_representative_id !== $image_id) {
                    $user_representative_updates_for[$row['id']] = $image_id;
                }

                $row['representative_picture_id'] = $image_id;
                $image_ids[] = $image_id;
                $categories[] = $row;
            }
            unset($image_id);
            // management of the album thumbnail -- stops here

            if (! is_string($row['image_order']) || $row['image_order'] === '') {
                $row['image_order'] = str_replace('ORDER BY ', '', $this->currentConfig->orderBy);
            }

            $cats[] = $row;
        }
        usort($cats, CategoryService::compareByGlobalRank(...));

        // management of the album thumbnail -- starts here
        if (count($categories) > 0) {
            $thumbnail_src_of = [];
            $new_image_ids = [];

            foreach ($this->imageService->getPathsAndLevelForIds($image_ids) as $row) {
                if ($row->level <= $currentUser->level) {
                    $thumbnail_src_of[$row->id] = DerivativeImage::url($params['thumbnail_size'], $row->toArray());
                } else {
                    // problem: we must not display the thumbnail of a photo which has a
                    // higher privacy level than user privacy level
                    //
                    // * what is the represented category?
                    // * find a random photo matching user permissions
                    // * register it at user_representative_picture_id
                    // * set it as the representative_picture_id for the category
                    foreach ($categories as &$category) {
                        if ($row->id === $category['representative_picture_id']) {
                            // searching a random representant among elements in sub-categories
                            $category_id = $category['id'];
                            $categoryIdVoForRandom = is_int($category_id) ? CategoryId::tryFrom($category_id) : null;
                            $image_id = $categoryIdVoForRandom instanceof CategoryId
                                ? $categoryService->getRandomImageInCategory(new RandomImageCategoryQuery(
                                    id: $categoryIdVoForRandom,
                                    uppercats: $category['uppercats'],
                                    countImages: $category['count_images'],
                                ))
                                : null;

                            if (isset($image_id) and ! in_array($image_id, $image_ids, true)) {
                                $new_image_ids[] = $image_id;
                            }
                            if ($this->currentConfig->representativeCacheOnLevel) {
                                if (is_int($category_id)) {
                                    $user_representative_updates_for[$category_id] = $image_id;
                                }
                            }

                            $category['representative_picture_id'] = $image_id;
                        }
                    }
                    unset($category);
                }
            }

            if (count($new_image_ids) > 0) {
                foreach ($this->imageService->getPathsForFileDeletion($new_image_ids) as $row) {
                    $thumbnail_src_of[$row->id] = DerivativeImage::url($params['thumbnail_size'], $row->toArray());
                }
            }
        }

        // compared to code in include/category_cats, we only persist the new
        // user_representative if we have used $user['id'] and not the guest id,
        // or else the real guest may see thumbnail that he should not
        if (! $params['public'] and (bool) count($user_representative_updates_for)) {
            // See getCachedRepresentative()'s own docblock. $repr_user_id
            // === $user_id here unconditionally -- the enclosing
            // `! $params['public']` guard above ensures this always
            // persists against $user_id specifically, never the guest id.
            foreach ($user_representative_updates_for as $cat_id => $image_id) {
                $this->setCachedRepresentative(
                    $repr_user_id,
                    $cat_id,
                    is_scalar($image_id) ? (string) $image_id : null
                );
            }
        }

        foreach ($cats as &$cat) {
            foreach ($categories as $category) {
                if ($category['id'] === $cat['id']
                  and isset($category['representative_picture_id'])
                ) {
                    $cat['tn_url'] = $thumbnail_src_of[$category['representative_picture_id']] ?? null;
                }
            }
            // we don't want them in the output
            unset($cat['user_representative_picture_id'], $cat['count_images'], $cat['count_categories']);
        }
        unset($cat);
        // management of the album thumbnail -- stops here

        if ($params['tree_output']) {
            return $this->wsHelper->categoriesFlatlistToTree($cats);
        }

        $output['categories'] = new NamedArray(
            $cats,
            'category',
            $this->wsHelper->stdGetCategoryXmlAttributes()
        );

        return $output;
    }

    /**
     * API method
     * Returns the list of categories as you can see them in administration
     *
     * Only admin can run this method and permissions are not taken into
     * account.
     *
     * @param array{cat_id: int|null, search: string|null, recursive: bool, additional_output: string|null, ...} $params
     *   all keys have a 'default' key in ws.php's registration, so all are
     *   always present.
     * @return array<string, mixed>
     */
    public function getAdminList(array $params, Server &$service): array
    {
        if (! isset($params['additional_output'])) {
            $params['additional_output'] = '';
        }
        $params['additional_output'] = array_map(trim(...), explode(',', $params['additional_output']));

        $nb_images_of = $this->categoryService->getPhotoCountsByCategory();

        // pwg_db_real_escape_string

        $criteria = new CategoryAdminListCriteria(
            catId: CategoryId::tryFrom($params['cat_id']),
            recursive: $params['recursive'],
        );

        $search_term = (isset($params['search']) and $params['search'] !== '') ? $params['search'] : null;
        $paginated_admin_cats = $this->categoryService->getAdminListForWs(
            $criteria,
            $search_term,
            $this->currentConfig->linkedAlbumSearchLimit
        );
        $rows = $paginated_admin_cats->rows;
        $counter = $paginated_admin_cats->total ?? 0;

        $cats = [];
        foreach ($rows as $row) {
            // id/uppercats are NOT NULL columns of the categories table --
            // native int under DBAL (vs. guaranteed string under legacy
            // mysqli), so is_int()||is_string() instead of asserting string -- both
            // are valid array-key types (unlike is_numeric(), which also
            // allows float).
            $id = $row['id'];
            assert(is_int($id) || is_string($id));
            $row['nb_images'] = $nb_images_of[$id] ?? 0;

            assert(is_string($row['uppercats']));
            $cat_display_name = $this->htmlRenderer
                ->getCatDisplayNameCache(
                    $row['uppercats'],
                    'admin.php?page=album-'
                );

            $row['name_raw'] = $row['name'];

            $nameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName(is_string($row['name']) ? $row['name'] : '', 'ws_categories_getAdminList'));
            $row['name'] = strip_tags($nameEvent->categoryName);
            $row['fullname'] = strip_tags($cat_display_name);

            $row['comment_raw'] = $row['comment'];
            $adminDescriptionEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryDescription(is_string($row['comment']) ? $row['comment'] : '', 'ws_categories_getAdminList'));
            $row['comment'] = $adminDescriptionEvent->categoryDescription;

            if (! is_string($row['image_order']) || $row['image_order'] === '') {
                $row['image_order'] = str_replace('ORDER BY ', '', $this->currentConfig->orderBy);
            }

            if (in_array('full_name_with_admin_links', $params['additional_output'], true)) {
                $row['full_name_with_admin_links'] = $cat_display_name;
            }

            $cats[] = $row;
        }

        if (! $params['recursive']) {
            $cats_ids = array_column($cats, 'id');
            $nb_subcats_of = [];
            if ($cats_ids !== []) {
                $cats_ids = array_map(intval(...), array_filter($cats_ids, is_numeric(...)));

                $nb_subcats_of = $this->categoryService->getSubcategoryCountsByParent(array_values($cats_ids));
            }

            foreach ($cats as $idx => $cat) {
                $cat_id = $cat['id'];
                $cats[$idx]['nb_categories'] = is_numeric($cat_id) ? ($nb_subcats_of[(string) $cat_id] ?? 0) : 0;
            }
        }

        $limit_reached = false;
        if ($counter > $this->currentConfig->linkedAlbumSearchLimit) {
            $limit_reached = true;
        }

        usort($cats, CategoryService::compareByGlobalRank(...));
        return [
            'categories' => new NamedArray(
                $cats,
                'category',
                ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']
            ),
            'limit' => $this->currentConfig->linkedAlbumSearchLimit,
            'limit_reached' => $limit_reached,
        ];
    }

    /**
     * API method
     * Adds a category
     *
     * @param array{name: string, parent: int|null, comment: string|null, visible: bool, status: string|null, commentable: bool, position: string|null, pwg_token?: string, ...} $params
     *   name: no 'default' key in ws.php's registration -- mandatory, always
     *   present. pwg_token: WsParamFlag::OPTIONAL with no 'default' key -- may be
     *   entirely absent.
     * @return WsErrorResponse|array{info: string, id: int|string}
     */
    public function add(array $params, Server &$service): WsErrorResponse|array
    {
        if (isset($params['pwg_token']) and new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        if (! in_array($params['position'], [null, ''], true) and in_array($params['position'], ['first', 'last'], true)) {
            // In-memory override only (this request's own CurrentConfig
            // property), not a real persisted preference -- known
            // limitation, same as AlbumsPageRenderer's own POS_PREF
            // assignment.
            $this->currentConfig->newcatDefaultPosition = $params['position'];
        }

        // $params['visible']/['commentable'] are always real bools by the
        // time they reach this handler (WsParamType::BOOL) -- always set
        // on $options so pwg.categories.add's own documented visible/
        // commentable params take effect, unlike status/comment below
        // which are only applied when actually supplied.
        $options = [
            'visible' => $params['visible'],
            'commentable' => $params['commentable'],
        ];
        if (! in_array($params['status'], [null, ''], true) and in_array($params['status'], ['private', 'public'], true)) {
            $options['status'] = $params['status'];
        }

        if (! in_array($params['comment'], [null, ''], true)) {
            $options['comment'] = (! $this->currentConfig->allowHtmlDescriptions or ! isset($params['pwg_token'])) ? strip_tags($params['comment']) : $params['comment'];
        }

        $creation_output = $this->categoryService->createVirtualCategory(
            (! $this->currentConfig->allowHtmlDescriptions or ! isset($params['pwg_token'])) ? strip_tags($params['name']) : $params['name'],
            $this->activityService,
            $this->currentUser,
            $params['parent'],
            $options
        );

        if ($creation_output->error !== null) {
            return new WsErrorResponse(500, $creation_output->error);
        }

        PermissionCacheInvalidator::invalidate();

        // success()'s own contract guarantees info/id are non-null whenever
        // error is null.
        return [
            'info' => (string) $creation_output->info,
            'id' => $creation_output->id ?? 0,
        ];
    }

    /**
     * API method
     * Set the rank of a category
     *
     * @param array{category_id: array<int, int>, rank?: int, ...} $params
     *   category_id: no 'default' key -- mandatory, always present; FORCE_ARRAY
     *   always coerces to a list of positive ints. rank: WsParamFlag::OPTIONAL
     *   (explicit flag) with no 'default' key -- may be entirely absent.
     */
    public function setRank(array $params, Server &$service): ?WsErrorResponse
    {
        // does the category really exist?
        $categories = $this->categoryService->getRankInfoByIds(array_values($params['category_id']));

        if (count($categories) === 0) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        $category = $categories[0];
        $parent_id = ($category->idUppercat !== null && $category->idUppercat !== 0) ? $category->idUppercat : null;

        // check the number of category given by the user
        if (count($params['category_id']) > 1) {
            $order_new = $params['category_id'];
            $order_new_by_id = $order_new;
            sort($order_new_by_id, SORT_NUMERIC);

            $cat_asc = $this->categoryService->getIdsByParentOrderedById($parent_id);

            if (strcmp(implode(',', $cat_asc), implode(',', $order_new_by_id)) !== 0) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'you need to provide all sub-category ids for a given category');
            }
        } else {
            $params['category_id'] = implode('', $params['category_id']);

            $order_old = $this->categoryService->getSiblingIdsExcludingOrderedByRank($parent_id, (int) $params['category_id']);
            $order_new = [];
            $was_inserted = false;
            $i = 1;
            foreach ($order_old as $category_id) {
                if ($i === ($params['rank'] ?? null)) {
                    $order_new[] = $params['category_id'];
                    $was_inserted = true;
                }
                $order_new[] = $category_id;
                ++$i;
            }

            if (! $was_inserted) {
                $order_new[] = $params['category_id'];
            }
        }
        // set the global rank
        $this->categoryService->saveCategoriesOrder($order_new);

        return null;
    }

    /**
     * API method
     * Sets details of a category
     *
     * @param array{category_id: int, name: string|null, comment: string|null, status: string|null, visible: string|null, commentable: string|null, apply_commentable_to_subalbums: string|null, pwg_token?: string, ...} $params
     *   category_id: no 'default' key -- mandatory, always present, WsParamType::ID
     *   guarantees a plain int. name/comment/status/visible/commentable/
     *   apply_commentable_to_subalbums: none has a 'type' flag (visible and
     *   commentable are validated by hand against /^(true|false)$/i below, not
     *   coerced by WsParamType::BOOL) -- all have a null default so string|null,
     *   always present. pwg_token: WsParamFlag::OPTIONAL with no 'default' key --
     *   may be entirely absent.
     */
    public function setInfo(array $params, Server &$service): ?WsErrorResponse
    {

        if (isset($params['pwg_token']) and new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        // does the category really exist?
        $category = $this->categoryRepository->findById($params['category_id']);
        if (! $category instanceof Category) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        $categoryService = $this->categoryService;

        if (! in_array($params['status'], [null, ''], true)) {
            if (! in_array($params['status'], ['private', 'public'], true)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid status, only public/private');
            }

            if ($params['status'] !== $category->status) {
                $categoryService->setCatStatus([$params['category_id']], $params['status']);
            }
        }

        $update = [
            'id' => $params['category_id'],
        ];

        foreach (['visible', 'commentable'] as $param_name) {
            if (isset($params[$param_name]) and ! (bool) preg_match('/^(true|false)$/i', $params[$param_name])) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid param ' . $param_name . ' : ' . $params[$param_name]);
            }
        }

        if (! in_array($params['visible'], [null], true)
            and filter_var($params['visible'], FILTER_VALIDATE_BOOLEAN) !== $category->visible) {
            $categoryService->setCatVisible([$params['category_id']], $params['visible']);
        }

        // 'commentable' is handled separately below (same setCatX() shape
        // as 'visible' above, needing bool coercion for the tinyint
        // column), not through this generic strip_tags loop.
        $info_columns = ['name', 'comment'];

        $perform_update = false;
        foreach ($info_columns as $key) {
            if (isset($params[$key])) {
                $perform_update = true;
                $update[$key] = (! $this->currentConfig->allowHtmlDescriptions or ! isset($params['pwg_token'])) ? strip_tags($params[$key]) : $params[$key];
            }
        }

        if (isset($params['commentable']) && isset($params['apply_commentable_to_subalbums']) && (bool) $params['apply_commentable_to_subalbums']) {
            $subcats = $categoryService->getSubcatIds([$params['category_id']]);
            if (count($subcats) > 0) {
                $categoryService->setCatCommentable($subcats, $params['commentable']);
            }
        } elseif (isset($params['commentable'])
            and filter_var($params['commentable'], FILTER_VALIDATE_BOOLEAN) !== $category->commentable) {
            $categoryService->setCatCommentable([$params['category_id']], $params['commentable']);
        }

        if ($perform_update) {
            $updateFields = $update;
            unset($updateFields['id']);
            $categoryService->updateFields(CategoryId::from($params['category_id']), $updateFields);
        }

        $this->activityService->record('album', $params['category_id'], 'edit', [
            'fields' => implode(',', array_keys($update)),
        ]);

        return null;
    }

    /**
     * API method
     * Sets representative image of a category
     *
     * @param array{category_id: int, image_id: int, ...} $params neither has a
     *   'default' key -- both mandatory, always present, WsParamType::ID guarantees
     *   plain ints.
     */
    public function setRepresentative(array $params, Server &$service): ?WsErrorResponse
    {
        // does the category really exist?
        if (! $this->categoryService->existsById($params['category_id'])) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        // does the image really exist?
        if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
            return new WsErrorResponse(404, 'image_id not found');
        }

        // apply change
        $this->categoryService->setRepresentativeImage($params['category_id'], $params['image_id']);
        $this->entityManager->clear();

        // Invalidates every user's own remembered-representative cache
        // entry (CategoryTreeCachePool, see getCachedRepresentative()'s
        // own docblock) so the admin's explicit choice above takes
        // priority on the next read. PSR-6 has no per-key-prefix bulk
        // delete, so this clears the whole pool -- a rare admin action,
        // not a hot path, and the pool's own 300s TTL already treats
        // broader staleness as tolerable.
        $this->categoryTreeCachePool->clear();

        $this->activityService->record('album', $params['category_id'], 'edit', [
            'image_id' => $params['image_id'],
        ]);

        return null;
    }

    /**
     * API method
     *
     * Deletes the album thumbnail. Only possible if
     * CurrentConfig::allowRandomRepresentative() or if the album has no direct photos.
     *
     * @param array{category_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     */
    public function deleteRepresentative(array $params, Server &$service): ?WsErrorResponse
    {
        // does the category really exist?
        if (! $this->categoryService->existsById($params['category_id'])) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        $has_images = $this->categoryService->hasImages($params['category_id']);

        if (! $this->currentConfig->allowRandomRepresentative and $has_images) {
            return new WsErrorResponse(401, 'not permitted');
        }

        $this->categoryService->clearRepresentativeImage(CategoryId::from($params['category_id']));
        $this->entityManager->clear();

        $this->activityService->record('album', $params['category_id'], 'edit');

        return null;
    }

    /**
     * API method
     *
     * Find a new album thumbnail.
     *
     * @param array{category_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     * @return WsErrorResponse|array{src: string|array<int|string, mixed>, url: string} matches
     *   CategoryService::getCategoryRepresentantProperties()'s own
     *   already-precise return type (this method's only real array return)
     */
    public function refreshRepresentative(array $params, Server &$service): WsErrorResponse|array
    {
        $categoryService = $this->categoryService;

        // does the category really exist?
        if (! $categoryService->existsById($params['category_id'])) {
            return new WsErrorResponse(404, 'category_id not found');
        }

        if (! $categoryService->hasImages($params['category_id'])) {
            return new WsErrorResponse(401, 'not permitted');
        }

        $categoryService->setRandomRepresentant([$params['category_id']]);

        $this->activityService->record('album', $params['category_id'], 'edit');

        // return url of the new representative
        $category = $this->categoryRepository->findById($params['category_id']);
        // the category's existence was already verified above, and nothing
        // in between could have deleted it
        assert($category instanceof Category);

        // setRandomRepresentant() is expected to have populated
        // representative_picture_id above, but it's not a NOT NULL column, so
        // guard for real instead of assuming the update landed.
        $representative_picture_id = $category->representativePictureId;
        if ($representative_picture_id === null) {
            return new WsErrorResponse(500, 'unable to determine a new representative picture for this category');
        }

        return $categoryService->getCategoryRepresentantProperties($representative_picture_id, $this->urlService, $this->entityManager, ImageStdParams::SMALL);
    }

    /**
     * API method
     * Deletes a category
     *
     * @param array{category_id: string|array<array-key, string>, photo_deletion_mode: string, pwg_token: string, ...} $params
     *   category_id: WsParamFlag::ACCEPT_ARRAY (not FORCE), no 'default' key --
     *   mandatory, always present, accepts either a scalar or a bracket-array
     *   caller value (never null, since mandatory). photo_deletion_mode: no
     *   'type' flag, non-null default -- always a plain string. pwg_token: no
     *   'default' key, no flags -- mandatory, always present, plain string.
     */
    public function delete(array $params, Server &$service): ?WsErrorResponse
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        $modes = ['no_delete', 'delete_orphans', 'force_delete'];
        if (! in_array($params['photo_deletion_mode'], $modes, true)) {
            return new WsErrorResponse(
                500,
                '[ws_categories_delete]'
      . ' invalid parameter photo_deletion_mode "' . $params['photo_deletion_mode'] . '"'
      . ', possible values are {' . implode(', ', $modes) . '}.'
            );
        }

        if (! is_array($params['category_id'])) {
            $params['category_id'] = preg_split(
                '/[\s,;\|]/',
                $params['category_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($params['category_id'] === false) {
                throw new Exception(__FUNCTION__ . '(): preg_split() failed');
            }
        }
        $params['category_id'] = array_map(intval(...), $params['category_id']);

        $category_ids = [];
        foreach ($params['category_id'] as $category_id) {
            if ($category_id > 0) {
                $category_ids[] = $category_id;
            }
        }

        if (count($category_ids) === 0) {
            return null;
        }

        $category_ids = $this->categoryService->getExistingIds($category_ids);

        if (count($category_ids) === 0) {
            return null;
        }

        $categoryService = $this->categoryService;
        $categoryService->deleteCategories(
            $category_ids,
            $this->activityService,
            $this->urlService,
            $this->sessionService,
            $this->eventDispatcher,
            $this->entityManager,
            new PermalinkRepository($this->entityManager),
            $params['photo_deletion_mode']
        );
        $categoryService->updateGlobalRank();
        PermissionCacheInvalidator::invalidate();

        return null;
    }

    /**
     * API method
     * Moves a category
     *
     * @param array{category_id: string|array<array-key, string>, parent: int, pwg_token: string, ...} $params
     *   category_id: WsParamFlag::ACCEPT_ARRAY (not FORCE), no 'default' key --
     *   mandatory, always present, accepts either a scalar or a bracket-array
     *   caller value. parent: WsParamType::INT|WsParamType::POSITIVE, no 'default' key --
     *   mandatory, always a plain int. pwg_token: no 'default' key, no flags --
     *   mandatory, always present, plain string.
     * @return WsErrorResponse|array{new_ariane_string: string, updated_cats: array<int, array{cat_id: string, nb_sub_photos: int}>}
     */
    public function move(array $params, Server &$service): WsErrorResponse|array
    {
        if (new CsrfService($this->currentConfig)->getToken() !== $params['pwg_token']) {
            return new WsErrorResponse(403, 'Invalid security token');
        }

        if (! is_array($params['category_id'])) {
            $params['category_id'] = preg_split(
                '/[\s,;\|]/',
                $params['category_id'],
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($params['category_id'] === false) {
                throw new Exception(__FUNCTION__ . '(): preg_split() failed');
            }
        }
        $params['category_id'] = array_map(intval(...), $params['category_id']);

        $category_ids = [];
        foreach ($params['category_id'] as $category_id) {
            if ($category_id > 0) {
                $category_ids[] = $category_id;
            }
        }

        if (count($category_ids) === 0) {
            return new WsErrorResponse(403, 'Invalid category_id input parameter, no category to move');
        }

        // we can't move physical categories
        $categories_in_db = [];
        $update_cat_ids = [];

        foreach ($this->categoryService->getMoveDetailsByIds($category_ids) as $row) {
            $row_id = $row->id;
            $categories_in_db[$row_id] = $row;
            $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $row->uppercats), 0, -1));

            // we break on error at first physical category detected
            if (! in_array($row->dir, [null, '', '0'], true)) {
                $moveNameEvent = $this->eventDispatcher->dispatchChange(new RenderCategoryName($row->name, 'ws_categories_move'));
                $row_name = strip_tags($moveNameEvent->categoryName);

                return new WsErrorResponse(
                    403,
                    sprintf(
                        'Category %s (%u) is not a virtual category, you cannot move it',
                        $row_name,
                        $row_id
                    )
                );
            }
        }

        if (count($categories_in_db) !== count($category_ids)) {
            $unknown_category_ids = array_diff($category_ids, array_keys($categories_in_db));

            return new WsErrorResponse(
                403,
                sprintf(
                    'Category %u does not exist',
                    $unknown_category_ids[0]
                )
            );
        }

        // does this parent exists? This check should be made in the
        // move_categories function, not here
        // 0 as parent means "move categories at gallery root"
        if ($params['parent'] !== 0) {
            $subcat_ids = $this->categoryService->getSubcatIds([$params['parent']]);
            if (count($subcat_ids) === 0) {
                return new WsErrorResponse(403, 'Unknown parent category id');
            }
        }

        $pageState = $this->pageState;
        $pageState->infos = [];
        $pageState->errors = [];

        $this->categoryService->moveCategories(
            $category_ids,
            $this->activityService,
            $pageState,
            $params['parent']
        );
        PermissionCacheInvalidator::invalidate();

        // moveCategories() writes onto the real, constructor-injected
        // PageState directly -- reading it back through the same
        // $pageState instance reflects the mutation without needing
        // get_defined_vars(). hasErrors() (a real method call, not
        // a bare property re-read) is what stops PHPStan from treating the
        // property as still statically `[]` from the reset a few lines
        // above -- it has no visibility into moveCategories()'s internals.
        if ($pageState->hasErrors()) {
            return new WsErrorResponse(403, implode('; ', $pageState->errors));
        }

        $cat_display_name = '';
        foreach ($this->categoryService->getUppercatsColumns($category_ids) as $uppercats) {
            $cat_display_name = $this->htmlRenderer
                ->getCatDisplayNameCache(
                    $uppercats,
                    'admin.php?page=album-'
                );
            $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $uppercats), 0, -1));
        }

        $nb_photos_in = $this->categoryService->getPhotoCountsByCategory();

        $update_cats = [];
        foreach (array_unique($update_cat_ids) as $update_cat) {
            $nb_sub_photos = 0;
            $sub_cat_without_parent = array_diff($this->categoryService->getSubcatIds([$update_cat]), [$update_cat]);

            foreach ($sub_cat_without_parent as $id_sub_cat) {
                $nb_sub_photos += $nb_photos_in[$id_sub_cat] ?? 0;
            }

            $update_cats[] = [
                'cat_id' => $update_cat,
                'nb_sub_photos' => $nb_sub_photos,
            ];
        }

        return [
            'new_ariane_string' => $cat_display_name,
            'updated_cats' => $update_cats,
        ];
    }

    /**
     * API method
     * Return the number of orphan photos if an album is deleted
     * @since 12
     *
     * @param array{category_id: array<int, int>, ...} $param no 'default' key --
     *   mandatory, always present, FORCE_ARRAY always coerces to a list of
     *   positive ints.
     * @return array<int, array{nb_images_associated_outside: int, nb_images_becoming_orphan: int, nb_images_recursive: int}>
     */
    public function calculateOrphans(array $param, Server &$service): array
    {
        $category_id = $param['category_id'][0];

        $category = [];
        $category['has_images'] = $this->categoryService->hasImages($category_id);

        // number of sub-categories
        $subcat_ids = $this->categoryService->getSubcatIds([$category_id]);

        $category['nb_subcats'] = count($subcat_ids) - 1;

        // total number of images under this category (including sub-categories)
        $image_ids_recursive = $this->categoryService->getDistinctLinkedImageIds($subcat_ids);

        $category['nb_images_recursive'] = count($image_ids_recursive);

        // number of images that would become orphan on album deletion
        $category['nb_images_becoming_orphan'] = 0;
        $category['nb_images_associated_outside'] = 0;

        if ($category['nb_images_recursive'] > 0) {
            // if we don't have "too many" photos, it's faster to compute the orphans with MySQL
            if ($category['nb_images_recursive'] < 1000) {
                $image_ids_associated_outside = $this->categoryService->getNonOrphanImageIds($image_ids_recursive, $subcat_ids);
                $category['nb_images_associated_outside'] = count($image_ids_associated_outside);

                $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_associated_outside);
                $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
            }
            // else it's better to avoid sending a huge SQL request, we compute the orphan list with PHP
            else {
                // image_id is a NOT NULL column of image_category --
                // $image_ids_recursive is already list<int> (cast at
                // extraction above), safe to flip directly.
                $image_ids_recursive_keys = array_flip($image_ids_recursive);

                $image_ids_associated_outside = $this->categoryService->getImageIdsOutsideCategories($subcat_ids);
                $image_ids_not_orphan = [];

                foreach ($image_ids_associated_outside as $image_id) {
                    if (isset($image_ids_recursive_keys[$image_id])) {
                        $image_ids_not_orphan[] = $image_id;
                    }
                }

                $category['nb_images_associated_outside'] = count(array_unique($image_ids_not_orphan));
                $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_not_orphan);
                $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
            }
        }

        $output = [];
        $output[] = [
            'nb_images_associated_outside' => $category['nb_images_associated_outside'],
            'nb_images_becoming_orphan' => $category['nb_images_becoming_orphan'],
            'nb_images_recursive' => $category['nb_images_recursive'],
        ];

        return $output;
    }
}
