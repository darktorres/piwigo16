<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Cache\CategoryTreeCachePool;
use Piwigo\Cache\PermissionsCachePool;
use Piwigo\Category\CategoryListCriteria;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryTreeCache;
use Piwigo\Category\Projection\RandomImageCategoryQuery;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Event\Template\RenderCategoryDescription;
use Piwigo\Event\Template\RenderCategoryName;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Permission\ForbiddenCategoriesCache;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserService;
use Piwigo\Ws\NamedArray;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.categories.getList` -- returns a list of categories.
 */
final readonly class GetListHandler implements WsAction
{
    public function __construct(
        private CategoryService $categoryService,
        private PermissionService $permissionService,
        private UserService $userService,
        private ImageService $imageService,
        private CategoryRepository $categoryRepository,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private CurrentConfig $currentConfig,
        private CurrentUser $currentUser,
        private AccessControl $accessControl,
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
     * same user id (see this method's own call site for why the admin
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
     * permission-visibility cache -- see this method's own write-back logic
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
     * Genuinely dynamic response shape: tree_output controls whether
     * categories nest recursively or come back flat, same rationale as
     * Ws\Users::getList()'s own client-controlled response shape.
     *
     * @param array<mixed> $params
     * @return WsErrorResponse|array<int|string, mixed>
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        $input = GetListParams::fromArray($params);
        $currentUser = $this->currentUser->get();

        $categoryService = $this->categoryService;

        if (! in_array($input->thumbnailSize, array_keys($this->imageStdParams->getDefinedTypeMap()), true)) {
            return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid thumbnail_size');
        }

        if (! in_array($input->limit, [null, 0], true) and $input->recursive) {
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

        if ($input->public) {
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

        $search_term = ($input->search !== null and $input->search !== '') ? $input->search : null;
        $catIdVo = CategoryId::tryFrom($input->catId);

        $criteria = new CategoryListCriteria(
            catId: $catIdVo,
            recursive: $input->recursive,
            forbiddenCategoryIds: $forbiddenCategoryIds,
            publicOnly: $publicOnly,
        );

        $paginated_cats = $categoryService->getListForWs(
            $criteria,
            $search_term,
            $this->currentConfig->linkedAlbumSearchLimit,
            $input->limit,
            $catIdVo instanceof CategoryId
        );
        $rows = $paginated_cats->rows;

        if ($input->limit !== null) {
            $result_count = $paginated_cats->total ?? 0;
            if ($catIdVo instanceof CategoryId) {
                --$result_count;
            }
            $output['limit'] = [
                'limited_to' => $input->limit,
                'total_cats' => intval($result_count),
                'remaining_cats' => $result_count > $input->limit ? $result_count - $input->limit : 0,
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
            // $input->fullname branch below, since it's also read
            // further down outside that branch (the representative-picture
            // LIKE query).
            assert(is_string($row['uppercats']));

            if ($input->fullname) {
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
            // warning : if the API method is called with $input->public, the
            // album thumbnail may be not accurate. The thumbnail can be viewed by
            // the connected user, but maybe not by the guest. Changing the
            // filtering method would be too complicated for now. We will simply
            // avoid to persist the user_representative_picture_id in the database
            // if $input->public
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
                $row['image_order'] = $this->currentConfig->orderBy->toSqlBody();
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
                    $thumbnail_src_of[$row->id] = DerivativeImage::url($input->thumbnailSize, $row->toArray());
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
                    $thumbnail_src_of[$row->id] = DerivativeImage::url($input->thumbnailSize, $row->toArray());
                }
            }
        }

        // compared to code in include/category_cats, we only persist the new
        // user_representative if we have used $user['id'] and not the guest id,
        // or else the real guest may see thumbnail that he should not
        if (! $input->public and (bool) count($user_representative_updates_for)) {
            // See getCachedRepresentative()'s own docblock. $repr_user_id
            // === $user_id here unconditionally -- the enclosing
            // `! $input->public` guard above ensures this always
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

        if ($input->treeOutput) {
            return $this->wsHelper->categoriesFlatlistToTree($cats);
        }

        $output['categories'] = new NamedArray(
            $cats,
            'category',
            $this->wsHelper->stdGetCategoryXmlAttributes()
        );

        return $output;
    }
}
