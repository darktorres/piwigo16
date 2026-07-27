<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Doctrine\DBAL\Connection;
use Exception;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\CachePools;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Category\CategoryTreeCache;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\UserService;

/**
 * P23 batch 8e-5: relocated from include/ws_functions/pwg.categories.php.
 * `pwg.categories.*` WS methods (12 registrations) -- registered via
 * callable arrays in include/ws_default_methods.inc.php.
 */
final class PwgCategories
{
    private static function categoryService(): CategoryService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();
    }

    /**
     * Gap-closure Stage 4h: getList()'s rollup columns
     * (nb_images/count_images/count_categories/date_last/max_date_last)
     * were never real `categories` columns -- they only ever existed via
     * the now-dead user_cache_categories JOIN (verified against
     * install/piwigo_structure-mysql.sql). CategoryTreeCache is the real
     * P23 batch 3b replacement, already relied on by
     * CategoryCatsRenderer/CategoryService::getCategoriesMenu() for the
     * exact same computation -- reused here for the public/normal
     * branches, whose forbidden_categories value matches what those other
     * consumers already cache under the same user id (see getList()'s own
     * call site for why the admin branch deliberately bypasses this
     * instead).
     */
    private static function categoryTreeCache(Connection $conn): CategoryTreeCache
    {
        return new CategoryTreeCache(self::categoryService(), \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class), CachePools::categoryTree());
    }

    /**
     * Constructed identically 5 times across getImages()/getList() --
     * including once inside getList()'s per-category foreach loop -- takes
     * the caller's own $conn instead of building a fresh one per call,
     * same "shared connection passed in" precedent as
     * Ws\PwgTags::activityService(Connection $conn).
     */
    private static function permissionService(): PermissionService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::permissionService();
    }

    /**
     * Constructed identically 4 times across setInfo()/setRepresentative()/
     * deleteRepresentative()/refreshRepresentative() -- none inside a loop,
     * so (unlike permissionService() above) this builds its own connection
     * per call, same shape as Ws\PwgComments::commentService().
     */
    private static function activityService(): ActivityService
    {
        return \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService();
    }

    /**
     * Gap-closure Stage 4h (docs/plan/gap-closure-p0-p23.md): getList()'s
     * own "which categories are forbidden for the guest identity" branch
     * needs a real effective-permission computation for a user that isn't
     * CurrentUser -- UserService::getUserData() already does exactly this
     * unconditionally for any given user id (Stage 4b/4g), so this reuses
     * it rather than re-deriving forbidden_categories by hand.
     */
    private static function userService(): UserService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::userService();
    }

    /**
     * Gap-closure Stage 4h: replaces `user_cache_categories.
     * user_representative_picture_id` -- a per-user "remembered random
     * representative" override, not just a permission-visibility cache
     * (confirmed by reading getList()'s own write-back logic below before
     * assuming this table was only ever a visibility cache). Same
     * CachePools::categoryTree() pool and `'repr_' . $userId . '_' . $catId'`
     * key format as Category\CategoryCatsRenderer's own
     * getCachedRepresentative()/setCachedRepresentative() -- deliberately
     * shared, not a separate pool, so a user sees the same remembered
     * representative whether browsing the website or calling this WS
     * method.
     */
    private static function getCachedRepresentative(int $userId, int $catId): ?string
    {
        $item = CachePools::categoryTree()->getItem('repr_' . $userId . '_' . $catId);
        if (! $item->isHit()) {
            return null;
        }

        $value = $item->get();

        return is_string($value) ? $value : null;
    }

    private static function setCachedRepresentative(int $userId, int $catId, ?string $imageId): void
    {
        $pool = CachePools::categoryTree();
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
     *   registration, see WsHelper::stdImageSqlFilter()/WsHelper::stdImageSqlOrder().
     * @return PwgError|array{paging: PwgNamedStruct, images: PwgNamedArray}
     */
    public static function getImages(array $params, PwgServer &$service): PwgError|array
    {
        $conn = DbConnection::build();
        $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();

        $params['cat_id'] = array_unique($params['cat_id']);

        if (count($params['cat_id']) > 0) {
            // do the categories really exist?
            $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $params['cat_id']) . ')
;';
            $db_cat_ids = array_map(intval(...), array_filter(array_column($conn->fetchAllAssociative($query), 'id'), is_numeric(...)));
            $missing_cat_ids = array_diff($params['cat_id'], $db_cat_ids);

            if (count($missing_cat_ids) > 0) {
                return new PwgError(404, 'cat_id {' . implode(',', $missing_cat_ids) . '} not found');
            }
        }

        $images = [];
        $image_ids = [];
        $total_images = 0;

        // ------------------------------------------------- get the related categories
        $where_clauses = [];
        foreach ($params['cat_id'] as $cat_id) {
            if ($params['recursive']) {
                $where_clauses[] = 'uppercats ' . SqlDialect::DB_REGEX_OPERATOR . ' \'(^|,)' . $cat_id . '(,|$)\'';
            } else {
                $where_clauses[] = 'id=' . $cat_id;
            }
        }
        if ($where_clauses !== []) {
            $where_clauses = ['(' . implode("\n    OR ", $where_clauses) . ')'];
        }
        $where_clauses[] = self::permissionService()->getSqlConditionFandF([
            'forbidden_categories' => 'id',
        ], null, true);

        $query = '
SELECT
    id,
    image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $where_clauses) . '
;';

        $cats = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $row['id'] = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $cats[$row['id']] = $row;
        }

        // -------------------------------------------------------- get the images
        if ($cats !== []) {
            $where_clauses = WsHelper::stdImageSqlFilter($params, $service, 'i.');
            $where_clauses[] = 'category_id IN (' . implode(',', array_keys($cats)) . ')';
            $where_clauses[] = self::permissionService()->getSqlConditionFandF([
                'visible_images' => 'i.id',
            ], null, true);

            $order_by = WsHelper::stdImageSqlOrder($params, 'i.');
            if ($order_by === ''
                  and count($params['cat_id']) === 1
                  and isset($cats[$params['cat_id'][0]]['image_order'])
                  and is_string($cats[$params['cat_id'][0]]['image_order'])
            ) {
                $order_by = $cats[$params['cat_id'][0]]['image_order'];
            }
            $order_by = $order_by === '' ? \Piwigo\Config\CurrentConfig::orderBy() : 'ORDER BY ' . $order_by;
            $favorite_ids = $urlService->getUserFavorites();

            $query = '
SELECT SQL_CALC_FOUND_ROWS i.*
  FROM ' . Tables::images() . ' i
    INNER JOIN ' . Tables::imageCategory() . ' ON i.id=image_id
  WHERE ' . implode("\n    AND ", $where_clauses) . '
  GROUP BY i.id
  ' . $order_by . '
  LIMIT ' . $params['per_page'] . '
  OFFSET ' . ($params['per_page'] * $params['page']) . '
;';
            // Fetched eagerly (rather than a lazy mysqli-style cursor) so the
            // SELECT FOUND_ROWS() below -- which reads THIS query's stats on
            // the SAME connection/session -- can safely run right after,
            // matching the established "one shared $conn for a
            // SQL_CALC_FOUND_ROWS/FOUND_ROWS() pair" pattern.
            $rows = $conn->fetchAllAssociative($query);

            foreach ($rows as $image_row) {
                // id is images.id, a NOT NULL primary key -- verified against
                // install/piwigo_structure-mysql.sql. Native int under DBAL
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

                $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_element_name', $image['name'], __FUNCTION__);
                $image['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
                $image['comment'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_element_description', $image['comment'], __FUNCTION__);

                $image = array_merge($image, WsHelper::stdGetUrls($image_row, $urlService));

                $images[] = $image;
            }

            $total_images_result = $conn->fetchOne('SELECT FOUND_ROWS()');
            $total_images = is_numeric($total_images_result) ? (int) $total_images_result : 0;

            // let's take care of adding the related albums to each photo
            if (count($image_ids) > 0) {
                $category_ids = [];

                // find the complete list (given permissions) of albums linked to photos
                $query = '
SELECT
    image_id,
    category_id
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $image_ids) . ')
    AND ' . self::permissionService()->getSqlConditionFandF([
                    'forbidden_categories' => 'category_id',
                ], null, true) . '
;';
                $categories_of_image = [];
                foreach ($conn->fetchAllAssociative($query) as $image_category_row) {
                    // image_id/category_id are NOT NULL columns of image_category
                    // -- verified against install/piwigo_structure-mysql.sql.
                    $row_image_id = is_numeric($image_category_row['image_id']) ? (int) $image_category_row['image_id'] : 0;
                    $row_category_id = is_numeric($image_category_row['category_id']) ? (int) $image_category_row['category_id'] : 0;
                    $category_ids[] = $row_category_id;
                    $categories_of_image[$row_image_id][] = $row_category_id;
                }

                $details_for_category = [];
                if (count($category_ids) > 0) {
                    // find details (for URL generation) about each album
                    $query = '
SELECT
    id,
    name,
    permalink
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
                    $details_for_category = array_column($conn->fetchAllAssociative($query), null, 'id');
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

                    $images[$idx]['categories'] = new PwgNamedArray(
                        $image_cats,
                        'category',
                        ['id', 'url', 'page_url']
                    );
                }
            }
        }

        return [
            'paging' => new PwgNamedStruct(
                [
                    'page' => $params['page'],
                    'per_page' => $params['per_page'],
                    'count' => count($images),
                    'total_count' => $total_images,
                ]
            ),
            'images' => new PwgNamedArray(
                $images,
                'image',
                WsHelper::stdGetImageXmlAttributes()
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
     * Ws\PwgUsers::getList()'s own client-controlled response shape.
     * @return PwgError|array<int|string, mixed>
     */
    public static function getList(array $params, PwgServer &$service): PwgError|array
    {
        $currentUser = \Piwigo\Users\CurrentUser::get();

        $categoryConn = DbConnection::build();
        $categoryService = self::categoryService();

        if (! in_array($params['thumbnail_size'], array_keys(ImageStdParams::get_defined_type_map()), true)) {
            return new PwgError(WsError::INVALID_PARAM, 'Invalid thumbnail_size');
        }

        if (! in_array($params['limit'], [null, 0], true) and $params['recursive']) {
            return new PwgError(WsError::INVALID_PARAM, 'Cannot use both recursive and limit parameters at the same time');
        }

        $output = [];
        $where = ['1=1'];
        $user_id = $currentUser->id;
        // Which user's own "remembered random representative" cache entry
        // (CachePools::categoryTree(), see below) each row's
        // user_representative_picture_id is read from/written to --
        // overridden to the guest identity in the public branch, same
        // identity the old user_cache_categories JOIN used to key on.
        $repr_user_id = $user_id;

        if (! $params['recursive']) {
            if ($params['cat_id'] > 0) {
                $where[] = '(
        id_uppercat = ' . $params['cat_id'] . '
        OR id=' . $params['cat_id'] . '
      )';
            } else {
                $where[] = 'id_uppercat IS NULL';
            }
        } elseif ($params['cat_id'] > 0) {
            $where[] = 'uppercats ' . SqlDialect::DB_REGEX_OPERATOR . ' \'(^|,)' .
              $params['cat_id'] . '(,|$)\'';
        }

        // Gap-closure Stage 4h (docs/plan/gap-closure-p0-p23.md): all 3
        // branches below now add their own explicit `id NOT IN (forbidden)`
        // condition -- a real, live regression fix, not just cleanup. The
        // user_cache_categories INNER/LEFT JOIN this used to run through
        // had gone permanently empty (Stage 4g deleted the table's only
        // remaining writer), so the "normal" and "public" branches were
        // silently returning zero categories to every non-admin caller.
        // The admin branch already had its own explicit condition (not
        // gated on the JOIN, whose LEFT type never filtered rows anyway --
        // it stays unchanged).
        // Gap-closure Stage 4h: nb_images/count_images/count_categories/
        // date_last/max_date_last are NOT real `categories` columns
        // (verified against install/piwigo_structure-mysql.sql) -- they
        // only ever existed via the JOIN removed above. $rollupByCatId
        // supplies them per-row below, computed per-branch since each
        // identity's forbidden-categories value differs (see each
        // branch's own comment).
        $rollupByCatId = [];

        if ($params['public']) {
            $where[] = 'status = "public"';
            $where[] = 'visible = 1';

            $repr_user_id = \Piwigo\Config\CurrentConfig::guestId();
            // UserService::getUserData() computes the same effective
            // (feature-1053-widened) forbidden-categories value for any
            // given user id that CurrentUser::forbiddenCategories already
            // holds for the current request's own user (Stage 4b/4g) --
            // reused here since the guest identity isn't CurrentUser. Also
            // reused (unmodified) below for the rollup: it's the same
            // canonical value any other guest-facing consumer of
            // CategoryTreeCache already computes/caches for this same
            // user id, so feeding it back into that same cache pool here
            // cannot desync it.
            $guest_userdata = self::userService()->getUserData($repr_user_id);
            $guest_forbidden_categories = $guest_userdata['forbidden_categories'] ?? '0';
            $guest_forbidden_categories = is_string($guest_forbidden_categories) ? $guest_forbidden_categories : '0';
            $where[] = 'id NOT IN (' . $guest_forbidden_categories . ')';
            $rollupByCatId = self::categoryTreeCache($categoryConn)->getForUser($guest_userdata);
        } elseif (\Piwigo\Auth\AccessControl::isAdmin()) {
            // in this very specific case, we don't want to hide empty
            // categories. Function calculate_permissions will only return
            // categories that are either locked or private and not permitted
            //
            // calculate_permissions does not consider empty categories as forbidden
            $forbidden_categories = new \Piwigo\Permission\ForbiddenCategoriesCache(self::permissionService(), \Piwigo\Cache\CachePools::permissions())
                ->getForUser($user_id, $currentUser->status->value);
            $where[] = 'id NOT IN (' . $forbidden_categories . ')';
            // Deliberately NOT CategoryTreeCache: that pool is keyed only
            // by user id, and this branch's forbidden_categories is the
            // narrower structural value above, not the wider "effective"
            // one CurrentUser::forbiddenCategories/EffectiveForbiddenCategoriesCache
            // compute for this same admin's user id elsewhere (e.g. while
            // browsing the site normally) -- sharing the pool would let
            // whichever computation runs first silently poison the other's
            // cache entry for up to 300s. Computed directly (uncached);
            // getComputedCategories()'s own LEFT JOIN never drops an empty
            // category, so this also satisfies the comment above without
            // the old JOIN's special-casing.
            $admin_userdata = $currentUser->toUserArray();
            $admin_userdata['forbidden_categories'] = $forbidden_categories;
            $rollupByCatId = $categoryService->getComputedCategories($admin_userdata, null)['categories'];
        } else {
            $where[] = 'id NOT IN (' . $currentUser->forbiddenCategories . ')';
            // $currentUser->forbiddenCategories IS the same effective value
            // EffectiveForbiddenCategoriesCache computes for this user id
            // (Stage 4b/4g) -- the same value any other CategoryTreeCache
            // consumer for this user already relies on, safe to share.
            $rollupByCatId = self::categoryTreeCache($categoryConn)->getForUser($currentUser->toUserArray());
        }

        $query = '
SELECT SQL_CALC_FOUND_ROWS
    id, name, comment, permalink, status,
    uppercats, global_rank, id_uppercat,
    representative_picture_id,
    image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $where);

        if (isset($params['search']) and $params['search'] !== '') {
            $query .= '
    AND name LIKE ' . $categoryConn->quote('%' . $params['search'] . '%');
            if (! isset($params['limit'])) {
                $query .= ' LIMIT ' . \Piwigo\Config\CurrentConfig::linkedAlbumSearchLimit();
            }
        }

        if (isset($params['limit'])) {
            $query .= '
  ORDER BY `rank` ASC
  LIMIT ' . ($params['limit'] + ($params['cat_id'] > 0 ? 1 : 0));
        }

        $query .= '
;';
        // Fetched eagerly so the SELECT FOUND_ROWS() below -- which reads
        // THIS query's stats on the SAME connection/session -- can safely
        // run right after, same pattern as getImages() above.
        $rows = $categoryConn->fetchAllAssociative($query);

        if (isset($params['limit'])) {
            $result_count = $categoryConn->fetchOne('SELECT FOUND_ROWS()');
            $result_count = is_numeric($result_count) ? (int) $result_count : 0;
            if ($params['cat_id'] > 0) {
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
        $urlService = \Piwigo\Bootstrap\PresentationAccessor::urlService();
        foreach ($rows as $row) {
            // Gap-closure Stage 4h: the rollup columns (nb_images/
            // count_images/count_categories/date_last/max_date_last)
            // aren't real `categories` columns -- merge them in from the
            // per-branch rollup computed above, keyed by cat_id, before
            // any of the row shaping below reads them. A row absent from
            // the rollup can't happen here: $rollupByCatId is computed
            // with the same forbidden_categories value that built $where
            // above, so every row that survived the WHERE clause also has
            // a rollup entry -- ?? 0/null below is defensive, not expected.
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

            // Gap-closure Stage 4h: was the user_cache_categories JOIN's own
            // user_representative_picture_id column -- see
            // getCachedRepresentative()'s own docblock.
            $row['user_representative_picture_id'] = self::getCachedRepresentative($repr_user_id, $row['id']);

            // uppercats is a NOT NULL column of the categories table --
            // verified against install/piwigo_structure-mysql.sql. Asserted
            // here (unconditionally) rather than only inside the
            // $params['fullname'] branch below, since it's also read
            // further down outside that branch (the representative-picture
            // LIKE query).
            assert(is_string($row['uppercats']));

            if ($params['fullname']) {
                $row['name'] = strip_tags(\Piwigo\Bootstrap\PresentationAccessor::htmlService()->getCatDisplayNameCache($row['uppercats'], null));
            } else {
                $row['name_raw'] = $row['name'];

                $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                    'render_category_name',
                    $row['name'],
                    'ws_categories_getList'
                );
                $row['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
            }

            $row['comment_raw'] = $row['comment'];

            $rendered_comment = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                'render_category_description',
                $row['comment'],
                'ws_categories_getList'
            );
            $row['comment'] = is_string($rendered_comment) ? $rendered_comment : '';

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
            } elseif (\Piwigo\Config\CurrentConfig::allowRandomRepresentative()) {
                // searching a random representant among elements in sub-categories
                $image_id = $categoryService->getRandomImageInCategory($row);
            } else { // searching a random representant among representant of sub-categories
                if ($row['count_categories'] > 0 and $row['count_images'] > 0) {
                    // Gap-closure Stage 4h (docs/plan/gap-closure-p0-p23.md):
                    // dropped the user_cache_categories INNER JOIN -- a real,
                    // live regression fix (Stage 4g deleted the table's only
                    // remaining writer), not just cleanup. The
                    // getSqlConditionFandF() condition already appended below
                    // was *already* a live, correctly-scoped duplicate of the
                    // same "is this category visible" check, same as
                    // CategoryRepository::findRandomRepresentativeIdAmongSubcategories().
                    $query = '
SELECT representative_picture_id
  FROM ' . Tables::categories() . '
  WHERE uppercats LIKE \'' . $row['uppercats'] . ',%\'
    AND representative_picture_id IS NOT NULL
        ' . self::permissionService()->getSqlConditionFandF([
                        'visible_categories' => 'id',
                    ], "\n  AND") . '
  ORDER BY ' . SqlDialect::DB_RANDOM_FUNCTION . '()
  LIMIT 1
;';
                    $subrow_image_id = $categoryConn->fetchOne($query);

                    if ($subrow_image_id !== false) {
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

                // Gap-closure Stage 4h: user_representative_picture_id is
                // now a cache string (getCachedRepresentative()'s own
                // return type), not the old DB column's value -- compare as
                // int against $image_id rather than the (always-mismatched)
                // string, or every row would be flagged "changed" here.
                $cached_representative_id = is_numeric($row['user_representative_picture_id'] ?? null)
                    ? (int) $row['user_representative_picture_id']
                    : null;

                if (\Piwigo\Config\CurrentConfig::representativeCacheOnSubcats() and $cached_representative_id !== $image_id) {
                    $user_representative_updates_for[$row['id']] = $image_id;
                }

                $row['representative_picture_id'] = $image_id;
                $image_ids[] = $image_id;
                $categories[] = $row;
            }
            unset($image_id);
            // management of the album thumbnail -- stops here

            if (! is_string($row['image_order']) || $row['image_order'] === '') {
                $row['image_order'] = str_replace('ORDER BY ', '', \Piwigo\Config\CurrentConfig::orderBy());
            }

            $cats[] = $row;
        }
        usort($cats, CategoryService::compareByGlobalRank(...));

        // management of the album thumbnail -- starts here
        if (count($categories) > 0) {
            $thumbnail_src_of = [];
            $new_image_ids = [];

            $query = '
SELECT id, path, representative_ext, level
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $image_ids) . ')
;';
            foreach ($categoryConn->fetchAllAssociative($query) as $row) {
                if ($row['level'] <= $currentUser->level) {
                    // id is images.id, a NOT NULL primary key -- verified
                    // against install/piwigo_structure-mysql.sql.
                    $thumbnail_src_of[is_numeric($row['id']) ? (int) $row['id'] : 0] = DerivativeImage::url($params['thumbnail_size'], $row);
                } else {
                    // problem: we must not display the thumbnail of a photo which has a
                    // higher privacy level than user privacy level
                    //
                    // * what is the represented category?
                    // * find a random photo matching user permissions
                    // * register it at user_representative_picture_id
                    // * set it as the representative_picture_id for the category
                    foreach ($categories as &$category) {
                        if (is_numeric($row['id']) and (int) $row['id'] === $category['representative_picture_id']) {
                            // searching a random representant among elements in sub-categories
                            $image_id = $categoryService->getRandomImageInCategory($category);

                            if (isset($image_id) and ! in_array($image_id, $image_ids, true)) {
                                $new_image_ids[] = $image_id;
                            }
                            if (\Piwigo\Config\CurrentConfig::representativeCacheOnLevel()) {
                                $category_id = $category['id'];
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
                $query = '
SELECT id, path, representative_ext
  FROM ' . Tables::images() . '
  WHERE id IN (' . implode(',', $new_image_ids) . ')
;';
                foreach ($categoryConn->fetchAllAssociative($query) as $row) {
                    $thumbnail_src_of[is_numeric($row['id']) ? (int) $row['id'] : 0] = DerivativeImage::url($params['thumbnail_size'], $row);
                }
            }
        }

        // compared to code in include/category_cats, we only persist the new
        // user_representative if we have used $user['id'] and not the guest id,
        // or else the real guest may see thumbnail that he should not
        if (! $params['public'] and (bool) count($user_representative_updates_for)) {
            // Gap-closure Stage 4h: was a single massUpdate() against
            // user_cache_categories -- see getCachedRepresentative()'s own
            // docblock. $repr_user_id === $user_id here unconditionally
            // (the enclosing `! $params['public']` guard above is the same
            // one the original code used to justify persisting against
            // $user_id specifically, never the guest id).
            foreach ($user_representative_updates_for as $cat_id => $image_id) {
                self::setCachedRepresentative(
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
            return WsHelper::categoriesFlatlistToTree($cats);
        }

        $output['categories'] = new PwgNamedArray(
            $cats,
            'category',
            WsHelper::stdGetCategoryXmlAttributes()
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
    public static function getAdminList(array $params, PwgServer &$service): array
    {
        $conn = DbConnection::build();

        if (! isset($params['additional_output'])) {
            $params['additional_output'] = '';
        }
        $params['additional_output'] = array_map(trim(...), explode(',', $params['additional_output']));

        $query = '
SELECT category_id, COUNT(*) AS counter
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;';
        $nb_images_of = array_column($conn->fetchAllAssociative($query), 'counter', 'category_id');

        // pwg_db_real_escape_string

        $where = ['1=1'];

        if (! $params['recursive']) {
            if ($params['cat_id'] > 0) {
                $where[] = '(
        id_uppercat = ' . $params['cat_id'] . '
        OR id=' . $params['cat_id'] . '
      )';
            } else {
                $where[] = 'id_uppercat IS NULL';
            }
        } elseif ($params['cat_id'] > 0) {
            $where[] = 'uppercats ' . SqlDialect::DB_REGEX_OPERATOR . ' \'(^|,)' .
              $params['cat_id'] . '(,|$)\'';
        }

        $query = '
SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $where);

        if (isset($params['search']) and $params['search'] !== '') {
            $query .= '
  AND name LIKE ' . $conn->quote('%' . $params['search'] . '%') . '
  LIMIT ' . \Piwigo\Config\CurrentConfig::linkedAlbumSearchLimit();
        }

        $query .= '
;';
        // Fetched eagerly so the SELECT FOUND_ROWS() below -- which reads
        // THIS query's stats on the SAME connection/session -- can safely
        // run right after, same pattern as getImages()/getList() above.
        $rows = $conn->fetchAllAssociative($query);

        $counter = $conn->fetchOne('SELECT FOUND_ROWS()');

        $cats = [];
        foreach ($rows as $row) {
            // id/uppercats are NOT NULL columns of the categories table --
            // verified against install/piwigo_structure-mysql.sql. Native
            // int under DBAL (vs. guaranteed string under legacy mysqli),
            // so is_int()||is_string() instead of asserting string -- both
            // are valid array-key types (unlike is_numeric(), which also
            // allows float).
            $id = $row['id'];
            assert(is_int($id) || is_string($id));
            $row['nb_images'] = $nb_images_of[$id] ?? 0;

            assert(is_string($row['uppercats']));
            $cat_display_name = \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->getCatDisplayNameCache(
                    $row['uppercats'],
                    'admin.php?page=album-'
                );

            $row['name_raw'] = $row['name'];

            $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                'render_category_name',
                $row['name'],
                'ws_categories_getAdminList'
            );
            $row['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
            $row['fullname'] = strip_tags($cat_display_name);

            $row['comment_raw'] = $row['comment'];
            $row['comment'] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                'render_category_description',
                $row['comment'] ?? '',
                'ws_categories_getAdminList'
            );

            if (! is_string($row['image_order']) || $row['image_order'] === '') {
                $row['image_order'] = str_replace('ORDER BY ', '', \Piwigo\Config\CurrentConfig::orderBy());
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
                $cats_ids = array_map(strval(...), array_filter($cats_ids, is_scalar(...)));

                $query = '
SELECT
    id_uppercat,
    COUNT(*) AS nb_subcats
  FROM ' . Tables::categories() . '
  WHERE id_uppercat IN (' . implode(',', $cats_ids) . ')
  GROUP BY id_uppercat
';

                $nb_subcats_of = array_column($conn->fetchAllAssociative($query), 'nb_subcats', 'id_uppercat');
            }

            foreach ($cats as $idx => $cat) {
                $cat_id = $cat['id'];
                $nb_subcats_value = is_numeric($cat_id) ? ($nb_subcats_of[(int) $cat_id] ?? 0) : 0;
                $cats[$idx]['nb_categories'] = is_numeric($nb_subcats_value) ? (int) $nb_subcats_value : 0;
            }
        }

        $limit_reached = false;
        if ($counter > \Piwigo\Config\CurrentConfig::linkedAlbumSearchLimit()) {
            $limit_reached = true;
        }

        usort($cats, CategoryService::compareByGlobalRank(...));
        return [
            'categories' => new PwgNamedArray(
                $cats,
                'category',
                ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']
            ),
            'limit' => \Piwigo\Config\CurrentConfig::linkedAlbumSearchLimit(),
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
     * @return PwgError|array{info: string, id: int|string}
     */
    public static function add(array $params, PwgServer &$service): PwgError|array
    {
        if (isset($params['pwg_token']) and new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! in_array($params['position'], [null, ''], true) and in_array($params['position'], ['first', 'last'], true)) {
            // In-memory override only (this request's own CurrentConfig
            // property), not a real persisted preference -- known
            // limitation, same as AlbumsPageRenderer's own POS_PREF
            // assignment.
            \Piwigo\Config\CurrentConfig::setNewcatDefaultPosition($params['position']);
        }

        // Docs/PLAN.md gap-closure, 2026-07-23: $params['visible']/
        // ['commentable'] (both WsParamType::BOOL, real bools by the time
        // they reach this handler) were validated by the WS param schema
        // but never actually read here -- a real, standalone bug (not
        // caused by the enum->tinyint migration, just found alongside it):
        // pwg.categories.add's own documented visible/commentable params
        // silently did nothing, every category created via this WS method
        // got the site-wide default regardless of what the caller asked for.
        $options = [
            'visible' => $params['visible'],
            'commentable' => $params['commentable'],
        ];
        if (! in_array($params['status'], [null, ''], true) and in_array($params['status'], ['private', 'public'], true)) {
            $options['status'] = $params['status'];
        }

        if (! in_array($params['comment'], [null, ''], true)) {
            $options['comment'] = (! \Piwigo\Config\CurrentConfig::allowHtmlDescriptions() or ! isset($params['pwg_token'])) ? strip_tags($params['comment']) : $params['comment'];
        }

        $categoryConn = DbConnection::build();
        $creation_output = self::categoryService()->createVirtualCategory(
            (! \Piwigo\Config\CurrentConfig::allowHtmlDescriptions() or ! isset($params['pwg_token'])) ? strip_tags($params['name']) : $params['name'],
            \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
            $params['parent'],
            $options
        );

        if (isset($creation_output['error'])) {
            return new PwgError(500, $creation_output['error']);
        }

        PermissionCacheInvalidator::invalidate();

        return $creation_output;
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
    public static function setRank(array $params, PwgServer &$service): ?PwgError
    {
        $conn = DbConnection::build();

        // does the category really exist?
        $query = '
SELECT id, id_uppercat, `rank`
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $params['category_id']) . ')
;';
        $categories = $conn->fetchAllAssociative($query);

        if (count($categories) === 0) {
            return new PwgError(404, 'category_id not found');
        }

        $category = $categories[0];

        // check the number of category given by the user
        if (count($params['category_id']) > 1) {
            $order_new = $params['category_id'];
            $order_new_by_id = $order_new;
            sort($order_new_by_id, SORT_NUMERIC);

            $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . ((is_numeric($category['id_uppercat']) && (int) $category['id_uppercat'] !== 0) ? '= ' . (string) $category['id_uppercat'] : 'IS NULL') . '
  ORDER BY `id` ASC
;';

            $cat_asc = array_map(intval(...), array_filter(array_column($conn->fetchAllAssociative($query), 'id'), is_numeric(...)));

            if (strcmp(implode(',', $cat_asc), implode(',', $order_new_by_id)) !== 0) {
                return new PwgError(WsError::INVALID_PARAM, 'you need to provide all sub-category ids for a given category');
            }
        } else {
            $params['category_id'] = implode('', $params['category_id']);

            $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . ((is_numeric($category['id_uppercat']) && (int) $category['id_uppercat'] !== 0) ? '= ' . (string) $category['id_uppercat'] : 'IS NULL') . '
    AND id != ' . $params['category_id'] . '
  ORDER BY `rank` ASC
;';

            $order_old = array_column($conn->fetchAllAssociative($query), 'id');
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
        self::categoryService()->saveCategoriesOrder($order_new);

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
    public static function setInfo(array $params, PwgServer &$service): ?PwgError
    {

        if (isset($params['pwg_token']) and new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $categoryConn = DbConnection::build();

        // does the category really exist?
        $category = \Piwigo\Db\EntityManagerFactory::build($categoryConn)->getRepository(\Piwigo\Category\CategoryEntity::class)
            ->findById($params['category_id']);
        if ($category === null) {
            return new PwgError(404, 'category_id not found');
        }

        $categoryService = self::categoryService();

        if (! in_array($params['status'], [null, ''], true)) {
            if (! in_array($params['status'], ['private', 'public'], true)) {
                return new PwgError(WsError::INVALID_PARAM, 'Invalid status, only public/private');
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
                return new PwgError(WsError::INVALID_PARAM, 'Invalid param ' . $param_name . ' : ' . $params[$param_name]);
            }
        }

        if (! in_array($params['visible'], [null, ''], true)
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
                $update[$key] = (! \Piwigo\Config\CurrentConfig::allowHtmlDescriptions() or ! isset($params['pwg_token'])) ? strip_tags($params[$key]) : $params[$key];
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
            new BatchWriter($categoryConn)
                ->singleUpdate(
                    Tables::categories(),
                    $update,
                    [
                        'id' => $update['id'],
                    ]
                );
            \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();
        }

        self::activityService()->record('album', $params['category_id'], 'edit', [
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
    public static function setRepresentative(array $params, PwgServer &$service): ?PwgError
    {
        $conn = DbConnection::build();

        // does the category really exist?
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count === 0) {
            return new PwgError(404, 'category_id not found');
        }

        // does the image really exist?
        $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
        $count = $conn->fetchOne($query);
        $count = is_numeric($count) ? (int) $count : 0;
        if ($count === 0) {
            return new PwgError(404, 'image_id not found');
        }

        // apply change
        $query = '
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = ' . $params['image_id'] . '
  WHERE id = ' . $params['category_id'] . '
;';
        $conn->executeStatement($query);
        \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();

        // Gap-closure Stage 4h: was `UPDATE user_cache_categories SET
        // user_representative_picture_id = NULL WHERE cat_id = ...` --
        // invalidates every user's own remembered-representative cache
        // entry (CachePools::categoryTree(), see getCachedRepresentative()'s
        // own docblock) so the admin's explicit choice above takes
        // priority on the next read. PSR-6 has no per-key-prefix bulk
        // delete, so this clears the whole pool -- a rare admin action,
        // not a hot path, and the pool's own 300s TTL already treats
        // broader staleness as tolerable.
        CachePools::categoryTree()->clear();

        self::activityService()->record('album', $params['category_id'], 'edit', [
            'image_id' => $params['image_id'],
        ]);

        return null;
    }

    /**
     * API method
     *
     * Deletes the album thumbnail. Only possible if
     * \Piwigo\Config\CurrentConfig::allowRandomRepresentative() or if the album has no direct photos.
     *
     * @param array{category_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     */
    public static function deleteRepresentative(array $params, PwgServer &$service): ?PwgError
    {
        $conn = DbConnection::build();

        // does the category really exist?
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
        if ($conn->fetchOne($query) === false) {
            return new PwgError(404, 'category_id not found');
        }

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category_id'] . '
;';
        $nb_images = $conn->fetchOne($query);
        $nb_images = is_numeric($nb_images) ? (int) $nb_images : 0;

        if (! \Piwigo\Config\CurrentConfig::allowRandomRepresentative() and $nb_images !== 0) {
            return new PwgError(401, 'not permitted');
        }

        $query = '
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = NULL
  WHERE id = ' . $params['category_id'] . '
;';
        $conn->executeStatement($query);
        \Piwigo\Bootstrap\InfrastructureAccessor::entityManager()->clear();

        self::activityService()->record('album', $params['category_id'], 'edit');

        return null;
    }

    /**
     * API method
     *
     * Find a new album thumbnail.
     *
     * @param array{category_id: int, ...} $params no 'default' key -- mandatory,
     *   always present, WsParamType::ID guarantees a plain int.
     * @return PwgError|array{src: string|array<int|string, mixed>, url: string} matches
     *   CategoryService::getCategoryRepresentantProperties()'s own
     *   already-precise return type (this method's only real array return)
     */
    public static function refreshRepresentative(array $params, PwgServer &$service): PwgError|array
    {
        $categoryConn = DbConnection::build();

        // does the category really exist?
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
        if ($categoryConn->fetchOne($query) === false) {
            return new PwgError(404, 'category_id not found');
        }

        $query = '
SELECT
    DISTINCT category_id
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category_id'] . '
  LIMIT 1
;';
        $has_images = $categoryConn->fetchOne($query) !== false;

        if (! $has_images) {
            return new PwgError(401, 'not permitted');
        }

        $categoryService = self::categoryService();

        $categoryService->setRandomRepresentant([$params['category_id']]);

        self::activityService()->record('album', $params['category_id'], 'edit');

        // return url of the new representative
        $category = \Piwigo\Db\EntityManagerFactory::build($categoryConn)->getRepository(\Piwigo\Category\CategoryEntity::class)
            ->findById($params['category_id']);
        // the category's existence was already verified above, and nothing
        // in between could have deleted it
        assert($category !== null);

        // setRandomRepresentant() is expected to have populated
        // representative_picture_id above, but it's not a NOT NULL column, so
        // guard for real instead of assuming the update landed.
        $representative_picture_id = $category->representativePictureId;
        if ($representative_picture_id === null) {
            return new PwgError(500, 'unable to determine a new representative picture for this category');
        }

        return $categoryService->getCategoryRepresentantProperties($representative_picture_id, \Piwigo\Bootstrap\PresentationAccessor::urlService(), ImageStdParams::SMALL);
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
    public static function delete(array $params, PwgServer &$service): ?PwgError
    {
        $categoryConn = DbConnection::build();

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $modes = ['no_delete', 'delete_orphans', 'force_delete'];
        if (! in_array($params['photo_deletion_mode'], $modes, true)) {
            return new PwgError(
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

        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
        // id is a NOT NULL primary key -- verified against
        // install/piwigo_structure-mysql.sql.
        $category_ids = array_map(intval(...), array_filter(array_column($categoryConn->fetchAllAssociative($query), 'id'), is_numeric(...)));

        if (count($category_ids) === 0) {
            return null;
        }

        $categoryService = self::categoryService();
        $categoryService->deleteCategories(
            $category_ids,
            \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
            \Piwigo\Bootstrap\PresentationAccessor::urlService(),
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
     * @return PwgError|array{new_ariane_string: string, updated_cats: array<int, array{cat_id: string, nb_sub_photos: int}>}
     */
    public static function move(array $params, PwgServer &$service): PwgError|array
    {
        $categoryConn = DbConnection::build();

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
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
            return new PwgError(403, 'Invalid category_id input parameter, no category to move');
        }

        // we can't move physical categories
        $categories_in_db = [];
        $update_cat_ids = [];

        $query = '
SELECT id, name, dir, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
        foreach ($categoryConn->fetchAllAssociative($query) as $row) {
            // id is a NOT NULL primary key -- verified against
            // install/piwigo_structure-mysql.sql.
            $row_id = is_numeric($row['id']) ? (int) $row['id'] : 0;
            $categories_in_db[$row_id] = $row;
            $row_uppercats = is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '';
            $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $row_uppercats), 0, -1));

            // we break on error at first physical category detected
            if (! in_array($row['dir'], [null, '', '0'], true)) {
                $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange(
                    'render_category_name',
                    $row['name'],
                    'ws_categories_move'
                );
                $row_name = strip_tags(is_string($rendered_name) ? $rendered_name : '');

                return new PwgError(
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

            return new PwgError(
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
            $subcat_ids = self::categoryService()->getSubcatIds([$params['parent']]);
            if (count($subcat_ids) === 0) {
                return new PwgError(403, 'Unknown parent category id');
            }
        }

        $pageState = \Piwigo\Core\PageState::current();
        $pageState->infos = [];
        $pageState->errors = [];

        self::categoryService()->moveCategories(
            $category_ids,
            \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
            $params['parent']
        );
        PermissionCacheInvalidator::invalidate();

        // moveCategories() writes onto PageState::current() directly (Legacy
        // Coupling Retirement Track A batch A5) -- reading it back through
        // the same $pageState instance reflects the mutation without
        // needing get_defined_vars(). hasErrors() (a real method call, not
        // a bare property re-read) is what stops PHPStan from treating the
        // property as still statically `[]` from the reset a few lines
        // above -- it has no visibility into moveCategories()'s internals.
        if ($pageState->hasErrors()) {
            return new PwgError(403, implode('; ', $pageState->errors));
        }

        $query = '
  SELECT uppercats
    FROM ' . Tables::categories() . '
    WHERE id IN (' . implode(',', $category_ids) . ')
  ;';
        $cat_display_name = '';
        foreach ($categoryConn->fetchAllAssociative($query) as $uppercats_row) {
            // uppercats is a NOT NULL column of the categories table --
            // verified against install/piwigo_structure-mysql.sql.
            assert(is_string($uppercats_row['uppercats']));
            $cat_display_name = \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->getCatDisplayNameCache(
                    $uppercats_row['uppercats'],
                    'admin.php?page=album-'
                );
            $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $uppercats_row['uppercats']), 0, -1));
        }

        $query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;';

        $nb_photos_in = array_column($categoryConn->fetchAllAssociative($query), 'nb_photos', 'category_id');

        $update_cats = [];
        foreach (array_unique($update_cat_ids) as $update_cat) {
            $nb_sub_photos = 0;
            $sub_cat_without_parent = array_diff(self::categoryService()->getSubcatIds([$update_cat]), [$update_cat]);

            foreach ($sub_cat_without_parent as $id_sub_cat) {
                $nb_photos_for_sub_cat = $nb_photos_in[$id_sub_cat] ?? 0;
                $nb_sub_photos += is_numeric($nb_photos_for_sub_cat) ? (int) $nb_photos_for_sub_cat : 0;
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
    public static function calculateOrphans(array $param, PwgServer &$service): array
    {
        $conn = DbConnection::build();

        $category_id = $param['category_id'][0];

        $query = '
SELECT DISTINCT
    category_id
  FROM
    ' . Tables::imageCategory() . '
  WHERE
    category_id = ' . $category_id . '
  LIMIT 1';
        $category = [];
        $category['has_images'] = $conn->fetchOne($query) !== false;

        // number of sub-categories
        $subcat_ids = self::categoryService()->getSubcatIds([$category_id]);

        $category['nb_subcats'] = count($subcat_ids) - 1;

        // total number of images under this category (including sub-categories)
        $query = '
SELECT DISTINCT
    (image_id)
  FROM
    ' . Tables::imageCategory() . '
  WHERE
    category_id IN (' . implode(',', $subcat_ids) . ')
  ;';
        $image_ids_recursive = array_map(intval(...), array_filter(array_column($conn->fetchAllAssociative($query), 'image_id'), is_numeric(...)));

        $category['nb_images_recursive'] = count($image_ids_recursive);

        // number of images that would become orphan on album deletion
        $category['nb_images_becoming_orphan'] = 0;
        $category['nb_images_associated_outside'] = 0;

        if ($category['nb_images_recursive'] > 0) {
            // if we don't have "too many" photos, it's faster to compute the orphans with MySQL
            if ($category['nb_images_recursive'] < 1000) {
                $query = '
  SELECT DISTINCT
      (image_id)
    FROM
      ' . Tables::imageCategory() . '
    WHERE
      category_id
    NOT IN
      (' . implode(',', $subcat_ids) . ')
    AND
      image_id
    IN
      (' . implode(',', $image_ids_recursive) . ')
  ;';

                $image_ids_associated_outside = array_map(intval(...), array_filter(array_column($conn->fetchAllAssociative($query), 'image_id'), is_numeric(...)));
                $category['nb_images_associated_outside'] = count($image_ids_associated_outside);

                $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_associated_outside);
                $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
            }
            // else it's better to avoid sending a huge SQL request, we compute the orphan list with PHP
            else {
                // image_id is a NOT NULL column of image_category -- verified
                // against install/piwigo_structure-mysql.sql. $image_ids_recursive
                // is already list<int> (cast at extraction above), safe to
                // flip directly.
                $image_ids_recursive_keys = array_flip($image_ids_recursive);

                $query = '
  SELECT
      image_id
    FROM
      ' . Tables::imageCategory() . '
    WHERE
      category_id
    NOT IN
      (' . implode(',', $subcat_ids) . ')
  ;';
                $image_ids_associated_outside = array_map(intval(...), array_filter(array_column($conn->fetchAllAssociative($query), 'image_id'), is_numeric(...)));
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
