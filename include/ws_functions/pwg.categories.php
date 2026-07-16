<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;
use Piwigo\Ws\PwgServer;

/**
 * API method
 * Returns images per category
 *
 * @param array{cat_id: array<int, int>, recursive: bool, per_page: int, page: int, order: string|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
 *   cat_id: WS_PARAM_FORCE_ARRAY|WS_TYPE_INT|WS_TYPE_POSITIVE, null default
 *   -- makeArrayParam() converts the null default to [], always a list of
 *   positive ints. f_* keys: the shared $f_params block merged into this
 *   registration, see ws_std_image_sql_filter()/ws_std_image_sql_order().
 * @return PwgError|array{paging: PwgNamedStruct, images: PwgNamedArray}
 */
function ws_categories_getImages(array $params, PwgServer &$service): PwgError|array
{
    /** @var array<string, mixed> $conf */
    global $user, $conf;

    $params['cat_id'] = array_unique($params['cat_id']);

    if (count($params['cat_id']) > 0) {
        // do the categories really exist?
        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $params['cat_id']) . ')
;';
        $db_cat_ids = query2array($query, null, 'id');
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
            $where_clauses[] = 'uppercats ' . DB_REGEX_OPERATOR . ' \'(^|,)' . $cat_id . '(,|$)\'';
        } else {
            $where_clauses[] = 'id=' . $cat_id;
        }
    }
    if (! empty($where_clauses)) {
        $where_clauses = ['(' . implode("\n    OR ", $where_clauses) . ')'];
    }
    $where_clauses[] = (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
        'forbidden_categories' => 'id',
    ], null, true);

    $query = '
SELECT
    id,
    image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $where_clauses) . '
;';
    $result = pwg_query($query);

    $cats = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $row['id'] = (int) $row['id'];
        $cats[$row['id']] = $row;
    }

    // -------------------------------------------------------- get the images
    if (! empty($cats)) {
        $where_clauses = ws_std_image_sql_filter($params, 'i.');
        $where_clauses[] = 'category_id IN (' . implode(',', array_keys($cats)) . ')';
        $where_clauses[] = (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
            'visible_images' => 'i.id',
        ], null, true);

        $order_by = ws_std_image_sql_order($params, 'i.');
        if (empty($order_by)
              and count($params['cat_id']) == 1
              and isset($cats[$params['cat_id'][0]]['image_order'])
        ) {
            $order_by = $cats[$params['cat_id'][0]]['image_order'];
        }
        $order_by = empty($order_by) ? (is_string($conf['order_by']) ? $conf['order_by'] : '') : 'ORDER BY ' . $order_by;
        $favorite_ids = get_user_favorites();

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
        $result = pwg_query($query);

        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            // id is images.id, a NOT NULL primary key -- verified against
            // install/piwigo_structure-mysql.sql.
            assert(is_string($row['id']));
            $image_ids[] = $row['id'];

            $image = [];
            $image['is_favorite'] = isset($favorite_ids[$row['id']]);
            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = (int) $row[$k];
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $rendered_name = trigger_change('render_element_name', $image['name'], __FUNCTION__);
            $image['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
            $image['comment'] = trigger_change('render_element_description', $image['comment'], __FUNCTION__);

            $image = array_merge($image, ws_std_get_urls($row));

            $images[] = $image;
        }

        $row = pwg_db_fetch_row(pwg_query('SELECT FOUND_ROWS()'));
        assert($row !== null);
        [$total_images] = $row;
        $total_images = (int) $total_images;

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
    AND ' . (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
                'forbidden_categories' => 'category_id',
            ], null, true) . '
;';
            $result = pwg_query($query);
            $categories_of_image = [];
            while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                $category_ids[] = $row['category_id'];
                // image_id/category_id are NOT NULL columns of image_category
                // -- verified against install/piwigo_structure-mysql.sql.
                $categories_of_image[(int) $row['image_id']][] = (int) $row['category_id'];
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
                $details_for_category = query2array($query, 'id');
            }

            foreach ($images as $idx => $img) {
                $image_cats = [];

                $image_id = $img['id'];
                if (! is_int($image_id)) {
                    continue;
                }

                // it should not be possible at this point, but let's consider a photo can be in no album
                if (! isset($categories_of_image[$image_id])) {
                    continue;
                }

                foreach ($categories_of_image[$image_id] as $cat_id) {
                    $url = make_index_url([
                        'category' => $details_for_category[$cat_id],
                    ]);

                    $page_url = make_picture_url(
                        [
                            'category' => $details_for_category[$cat_id],
                            'image_id' => $image_id,
                            'image_file' => $img['file'],
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
            ws_std_get_image_xml_attributes()
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
 * @return PwgError|array<int|string, mixed>
 */
function ws_categories_getList(array $params, PwgServer &$service): PwgError|array
{
    /**
     * @var array<string, mixed> $user
     * @var array<string, mixed> $conf
     */
    global $user, $conf;

    $categoryConn = DbConnection::build();
    $categoryService = new CategoryService(
        new CategoryRepository($categoryConn),
        new PermissionService(new PermissionRepository($categoryConn), new GroupRepository($categoryConn))
    );

    if (! in_array($params['thumbnail_size'], array_keys(ImageStdParams::get_defined_type_map()))) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid thumbnail_size');
    }

    if (! empty($params['limit']) and $params['recursive']) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Cannot use both recursive and limit parameters at the same time');
    }

    $output = [];
    $where = ['1=1'];
    $join_type = 'INNER';
    // narrowed once and reused everywhere below instead of re-reading the
    // mixed $user['id'] offset at each site.
    $user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;
    $join_user = $user_id;

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
        $where[] = 'uppercats ' . DB_REGEX_OPERATOR . ' \'(^|,)' .
          $params['cat_id'] . '(,|$)\'';
    }

    if ($params['public']) {
        $where[] = 'status = "public"';
        $where[] = 'visible = "true"';

        $join_user = is_numeric($conf['guest_id']) ? (int) $conf['guest_id'] : 0;
    } elseif (\Piwigo\Auth\AccessControl::isAdmin()) {
        // in this very specific case, we don't want to hide empty
        // categories. Function calculate_permissions will only return
        // categories that are either locked or private and not permitted
        //
        // calculate_permissions does not consider empty categories as forbidden
        $user_status = is_string($user['status']) ? $user['status'] : '';
        $forbidden_categories = (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getForbiddenCategories($user_id, $user_status);
        $where[] = 'id NOT IN (' . $forbidden_categories . ')';
        $join_type = 'LEFT';
    }

    $query = '
SELECT SQL_CALC_FOUND_ROWS
    id, name, comment, permalink, status,
    uppercats, global_rank, id_uppercat,
    nb_images, count_images AS total_nb_images,
    representative_picture_id, user_representative_picture_id, count_images, count_categories,
    date_last, max_date_last, count_categories AS nb_categories,
    image_order
  FROM ' . Tables::categories() . '
    ' . $join_type . ' JOIN ' . Tables::userCacheCategories() . '
    ON id=cat_id AND user_id=' . $join_user . '
  WHERE ' . implode("\n    AND ", $where);

    if (isset($params['search']) and $params['search'] != '') {
        $query .= '
    AND name LIKE \'%' . pwg_db_real_escape_string($params['search']) . '%\'';
        if (! isset($params['limit'])) {
            $query .= ' LIMIT ' . (is_numeric($conf['linked_album_search_limit']) ? (int) $conf['linked_album_search_limit'] : 0);
        }
    }

    if (isset($params['limit'])) {
        $query .= '
  ORDER BY `rank` ASC
  LIMIT ' . ($params['limit'] + ($params['cat_id'] > 0 ? 1 : 0));
    }

    $query .= '
;';
    $result = pwg_query($query);

    if (isset($params['limit'])) {
        $row = pwg_db_fetch_row(pwg_query('SELECT FOUND_ROWS()'));
        assert($row !== null);
        [$result_count] = $row;
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
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $row['url'] = make_index_url(
            [
                'category' => $row,
            ]
        );
        foreach (['id', 'nb_images', 'total_nb_images', 'nb_categories'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        if ($params['fullname']) {
            // uppercats is a NOT NULL column of the categories table --
            // verified against install/piwigo_structure-mysql.sql.
            assert(is_string($row['uppercats']));
            $row['name'] = strip_tags(get_cat_display_name_cache($row['uppercats'], null));
        } else {
            $row['name_raw'] = $row['name'];

            $rendered_name = trigger_change(
                'render_category_name',
                $row['name'],
                'ws_categories_getList'
            );
            $row['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
        }

        $row['comment_raw'] = $row['comment'];

        $rendered_comment = trigger_change(
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
        if (! empty($row['user_representative_picture_id'])) {
            $image_id = $row['user_representative_picture_id'];
        } elseif (! empty($row['representative_picture_id'])) { // if a representative picture is set, it has priority
            $image_id = $row['representative_picture_id'];
        } elseif ((bool) $conf['allow_random_representative']) {
            // searching a random representant among elements in sub-categories
            $image_id = $categoryService->getRandomImageInCategory($row);
        } else { // searching a random representant among representant of sub-categories
            if ($row['count_categories'] > 0 and $row['count_images'] > 0) {
                $query = '
SELECT representative_picture_id
  FROM ' . Tables::categories() . '
    INNER JOIN ' . Tables::userCacheCategories() . '
    ON id=cat_id AND user_id=' . $user_id . '
  WHERE uppercats LIKE \'' . $row['uppercats'] . ',%\'
    AND representative_picture_id IS NOT NULL
        ' . (new \Piwigo\Permission\PermissionService(new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build())))->getSqlConditionFandF([
                    'visible_categories' => 'id',
                ], "\n  AND") . '
  ORDER BY ' . DB_RANDOM_FUNCTION . '()
  LIMIT 1
;';
                $subresult = pwg_query($query);

                if (pwg_db_num_rows($subresult) > 0) {
                    $subrow = pwg_db_fetch_row($subresult);
                    assert($subrow !== null);
                    [$image_id] = $subrow;
                }
            }
        }

        if (isset($image_id)) {
            if ((bool) $conf['representative_cache_on_subcats'] and $row['user_representative_picture_id'] != $image_id) {
                $user_representative_updates_for[$row['id']] = $image_id;
            }

            $row['representative_picture_id'] = $image_id;
            $image_ids[] = $image_id;
            $categories[] = $row;
        }
        unset($image_id);
        // management of the album thumbnail -- stops here

        if (empty($row['image_order'])) {
            $row['image_order'] = str_replace('ORDER BY ', '', is_string($conf['order_by']) ? $conf['order_by'] : '');
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
        $result = pwg_query($query);

        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            if ($row['level'] <= $user['level']) {
                // id is images.id, a NOT NULL primary key -- verified
                // against install/piwigo_structure-mysql.sql.
                $thumbnail_src_of[(int) $row['id']] = DerivativeImage::url($params['thumbnail_size'], $row);
            } else {
                // problem: we must not display the thumbnail of a photo which has a
                // higher privacy level than user privacy level
                //
                // * what is the represented category?
                // * find a random photo matching user permissions
                // * register it at user_representative_picture_id
                // * set it as the representative_picture_id for the category
                foreach ($categories as &$category) {
                    if ($row['id'] == $category['representative_picture_id']) {
                        // searching a random representant among elements in sub-categories
                        $image_id = $categoryService->getRandomImageInCategory($category);

                        if (isset($image_id) and ! in_array($image_id, $image_ids)) {
                            $new_image_ids[] = $image_id;
                        }
                        if ((bool) $conf['representative_cache_on_level']) {
                            $category_id = $category['id'];
                            if (is_numeric($category_id)) {
                                $user_representative_updates_for[(int) $category_id] = $image_id;
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
            $result = pwg_query($query);

            while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                $thumbnail_src_of[(int) $row['id']] = DerivativeImage::url($params['thumbnail_size'], $row);
            }
        }
    }

    // compared to code in include/category_cats, we only persist the new
    // user_representative if we have used $user['id'] and not the guest id,
    // or else the real guest may see thumbnail that he should not
    if (! $params['public'] and (bool) count($user_representative_updates_for)) {
        $updates = [];

        foreach ($user_representative_updates_for as $cat_id => $image_id) {
            $updates[] = [
                'user_id' => $user_id,
                'cat_id' => $cat_id,
                'user_representative_picture_id' => $image_id,
            ];
        }

        mass_updates(
            Tables::userCacheCategories(),
            [
                'primary' => ['user_id', 'cat_id'],
                'update' => ['user_representative_picture_id'],
            ],
            $updates
        );
    }

    foreach ($cats as &$cat) {
        foreach ($categories as $category) {
            if ($category['id'] == $cat['id']
              and isset($category['representative_picture_id'])
              and is_numeric($category['representative_picture_id'])
            ) {
                $cat['tn_url'] = $thumbnail_src_of[(int) $category['representative_picture_id']] ?? null;
            }
        }
        // we don't want them in the output
        unset($cat['user_representative_picture_id'], $cat['count_images'], $cat['count_categories']);
    }
    unset($cat);
    // management of the album thumbnail -- stops here

    if ($params['tree_output']) {
        return categories_flatlist_to_tree($cats);
    }

    $output['categories'] = new PwgNamedArray(
        $cats,
        'category',
        ws_std_get_category_xml_attributes()
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
function ws_categories_getAdminList(array $params, PwgServer &$service): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (! isset($params['additional_output'])) {
        $params['additional_output'] = '';
    }
    $params['additional_output'] = array_map(trim(...), explode(',', $params['additional_output']));

    $query = '
SELECT category_id, COUNT(*) AS counter
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;';
    $nb_images_of = query2array($query, 'category_id', 'counter');

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
        $where[] = 'uppercats ' . DB_REGEX_OPERATOR . ' \'(^|,)' .
          $params['cat_id'] . '(,|$)\'';
    }

    $query = '
SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order
  FROM ' . Tables::categories() . '
  WHERE ' . implode("\n    AND ", $where);

    if (isset($params['search']) and $params['search'] != '') {
        $query .= '
  AND name LIKE \'%' . pwg_db_real_escape_string($params['search']) . '%\'
  LIMIT ' . (is_numeric($conf['linked_album_search_limit']) ? (int) $conf['linked_album_search_limit'] : 0);
    }

    $query .= '
;';
    $result = pwg_query($query);

    $row = pwg_db_fetch_row(pwg_query('SELECT FOUND_ROWS()'));
    assert($row !== null);
    [$counter] = $row;

    $cats = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // id/uppercats are NOT NULL columns of the categories table --
        // verified against install/piwigo_structure-mysql.sql.
        $id = $row['id'];
        assert(is_string($id));
        $row['nb_images'] = $nb_images_of[$id] ?? 0;

        assert(is_string($row['uppercats']));
        $cat_display_name = get_cat_display_name_cache(
            $row['uppercats'],
            'admin.php?page=album-'
        );

        $row['name_raw'] = $row['name'];

        $rendered_name = trigger_change(
            'render_category_name',
            $row['name'],
            'ws_categories_getAdminList'
        );
        $row['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');
        $row['fullname'] = strip_tags($cat_display_name);

        $row['comment_raw'] = $row['comment'];
        $row['comment'] = trigger_change(
            'render_category_description',
            $row['comment'] ?? '',
            'ws_categories_getAdminList'
        );

        if (empty($row['image_order'])) {
            $row['image_order'] = str_replace('ORDER BY ', '', is_string($conf['order_by']) ? $conf['order_by'] : '');
        }

        if (in_array('full_name_with_admin_links', $params['additional_output'])) {
            $row['full_name_with_admin_links'] = $cat_display_name;
        }

        $cats[] = $row;
    }

    if (! $params['recursive']) {
        $cats_ids = array_column($cats, 'id');
        $nb_subcats_of = [];
        if (! empty($cats_ids)) {
            $cats_ids = array_map(strval(...), array_filter($cats_ids, is_scalar(...)));

            $query = '
SELECT
    id_uppercat,
    COUNT(*) AS nb_subcats
  FROM ' . Tables::categories() . '
  WHERE id_uppercat IN (' . implode(',', $cats_ids) . ')
  GROUP BY id_uppercat
';

            $nb_subcats_of = query2array($query, 'id_uppercat', 'nb_subcats');
        }

        foreach ($cats as $idx => $cat) {
            $cat_id = $cat['id'];
            $cats[$idx]['nb_categories'] = is_numeric($cat_id) ? intval($nb_subcats_of[(int) $cat_id] ?? 0) : 0;
        }
    }

    $limit_reached = false;
    if ($counter > $conf['linked_album_search_limit']) {
        $limit_reached = true;
    }

    usort($cats, CategoryService::compareByGlobalRank(...));
    return [
        'categories' => new PwgNamedArray(
            $cats,
            'category',
            ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']
        ),
        'limit' => $conf['linked_album_search_limit'],
        'limit_reached' => $limit_reached,
    ];
}

/**
 * API method
 * Adds a category
 *
 * @param array{name: string, parent: int|null, comment: string|null, visible: bool, status: string|null, commentable: bool, position: string|null, pwg_token?: string, ...} $params
 *   name: no 'default' key in ws.php's registration -- mandatory, always
 *   present. pwg_token: WS_PARAM_OPTIONAL with no 'default' key -- may be
 *   entirely absent.
 * @return PwgError|array{info: string, id: int|string}
 */
function ws_categories_add(array $params, PwgServer &$service): PwgError|array
{
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    /** @var array<string, mixed> $conf */
    global $conf;

    if (isset($params['pwg_token']) and (new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (! empty($params['position']) and in_array($params['position'], ['first', 'last'])) {
        // TODO make persistent with user prefs
        $conf['newcat_default_position'] = $params['position'];
    }

    $options = [];
    if (! empty($params['status']) and in_array($params['status'], ['private', 'public'])) {
        $options['status'] = $params['status'];
    }

    if (! empty($params['comment'])) {
        $options['comment'] = (! (bool) $conf['allow_html_descriptions'] or ! isset($params['pwg_token'])) ? strip_tags($params['comment']) : $params['comment'];
    }

    $creation_output = create_virtual_category(
        (! (bool) $conf['allow_html_descriptions'] or ! isset($params['pwg_token'])) ? strip_tags($params['name']) : $params['name'],
        $params['parent'],
        $options
    );

    if (isset($creation_output['error'])) {
        return new PwgError(500, $creation_output['error']);
    }

    invalidate_user_cache();

    return $creation_output;
}

/**
 * API method
 * Set the rank of a category
 *
 * @param array{category_id: array<int, int>, rank?: int, ...} $params
 *   category_id: no 'default' key -- mandatory, always present; FORCE_ARRAY
 *   always coerces to a list of positive ints. rank: WS_PARAM_OPTIONAL
 *   (explicit flag) with no 'default' key -- may be entirely absent.
 */
function ws_categories_setRank(array $params, PwgServer &$service): ?PwgError
{
    // does the category really exist?
    $query = '
SELECT id, id_uppercat, `rank`
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $params['category_id']) . ')
;';
    $categories = query2array($query);

    if (count($categories) == 0) {
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
  WHERE id_uppercat ' . (empty($category['id_uppercat']) ? 'IS NULL' : '= ' . $category['id_uppercat']) . '
  ORDER BY `id` ASC
;';

        $cat_asc = query2array($query, null, 'id');

        if (strcmp(implode(',', $cat_asc), implode(',', $order_new_by_id)) !== 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'you need to provide all sub-category ids for a given category');
        }
    } else {
        $params['category_id'] = implode('', $params['category_id']);

        $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . (empty($category['id_uppercat']) ? 'IS NULL' : '= ' . $category['id_uppercat']) . '
    AND id != ' . $params['category_id'] . '
  ORDER BY `rank` ASC
;';

        $order_old = query2array($query, null, 'id');
        $order_new = [];
        $was_inserted = false;
        $i = 1;
        foreach ($order_old as $category_id) {
            if ($i == ($params['rank'] ?? null)) {
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
    // include function to set the global rank
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    save_categories_order($order_new);

    return null;
}

/**
 * API method
 * Sets details of a category
 *
 * @param array{category_id: int, name: string|null, comment: string|null, status: string|null, visible: string|null, commentable: string|null, apply_commentable_to_subalbums: string|null, pwg_token?: string, ...} $params
 *   category_id: no 'default' key -- mandatory, always present, WS_TYPE_ID
 *   guarantees a plain int. name/comment/status/visible/commentable/
 *   apply_commentable_to_subalbums: none has a 'type' flag (visible and
 *   commentable are validated by hand against /^(true|false)$/i below, not
 *   coerced by WS_TYPE_BOOL) -- all have a null default so string|null,
 *   always present. pwg_token: WS_PARAM_OPTIONAL with no 'default' key --
 *   may be entirely absent.
 */
function ws_categories_setInfo(array $params, PwgServer &$service): ?PwgError
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (isset($params['pwg_token']) and (new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    // does the category really exist?
    $query = '
SELECT *
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
    $categories = query2array($query);
    if (count($categories) == 0) {
        return new PwgError(404, 'category_id not found');
    }

    $category = $categories[0];

    if (! empty($params['status'])) {
        if (! in_array($params['status'], ['private', 'public'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid status, only public/private');
        }

        if ($params['status'] != $category['status']) {
            include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
            set_cat_status([$params['category_id']], $params['status']);
        }
    }

    $update = [
        'id' => $params['category_id'],
    ];

    foreach (['visible', 'commentable'] as $param_name) {
        if (isset($params[$param_name]) and ! (bool) preg_match('/^(true|false)$/i', $params[$param_name])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid param ' . $param_name . ' : ' . $params[$param_name]);
        }
    }

    if (! empty($params['visible']) and ($params['visible'] != $category['visible'])) {
        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
        set_cat_visible([$params['category_id']], $params['visible']);
    }

    $info_columns = ['name', 'comment', 'commentable'];

    $perform_update = false;
    foreach ($info_columns as $key) {
        if (isset($params[$key])) {
            $perform_update = true;
            $update[$key] = (! (bool) $conf['allow_html_descriptions'] or ! isset($params['pwg_token'])) ? strip_tags($params[$key]) : $params[$key];
        }
    }

    if (isset($params['commentable']) && isset($params['apply_commentable_to_subalbums']) && (bool) $params['apply_commentable_to_subalbums']) {
        $subcats = get_subcat_ids([$params['category_id']]);
        if (count($subcats) > 0) {
            $query = '
UPDATE ' . Tables::categories() . '
  SET commentable = \'' . $params['commentable'] . '\'
  WHERE id IN (' . implode(',', $subcats) . ')
;';
            pwg_query($query);
        }
    }

    if ($perform_update) {
        single_update(
            Tables::categories(),
            $update,
            [
                'id' => $update['id'],
            ]
        );
    }

    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('album', $params['category_id'], 'edit', [
        'fields' => implode(',', array_keys($update)),
    ]);

    return null;
}

/**
 * API method
 * Sets representative image of a category
 *
 * @param array{category_id: int, image_id: int, ...} $params neither has a
 *   'default' key -- both mandatory, always present, WS_TYPE_ID guarantees
 *   plain ints.
 */
function ws_categories_setRepresentative(array $params, PwgServer &$service): ?PwgError
{
    // does the category really exist?
    $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count == 0) {
        return new PwgError(404, 'category_id not found');
    }

    // does the image really exist?
    $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
  WHERE id = ' . $params['image_id'] . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$count] = $row;
    if ($count == 0) {
        return new PwgError(404, 'image_id not found');
    }

    // apply change
    $query = '
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = ' . $params['image_id'] . '
  WHERE id = ' . $params['category_id'] . '
;';
    pwg_query($query);

    $query = '
UPDATE ' . Tables::userCacheCategories() . '
  SET user_representative_picture_id = NULL
  WHERE cat_id = ' . $params['category_id'] . '
;';
    pwg_query($query);

    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('album', $params['category_id'], 'edit', [
        'image_id' => $params['image_id'],
    ]);

    return null;
}

/**
 * API method
 *
 * Deletes the album thumbnail. Only possible if
 * $conf['allow_random_representative'] or if the album has no direct photos.
 *
 * @param array{category_id: int, ...} $params no 'default' key -- mandatory,
 *   always present, WS_TYPE_ID guarantees a plain int.
 */
function ws_categories_deleteRepresentative(array $params, PwgServer &$service): ?PwgError
{
    /** @var array<string, mixed> $conf */
    global $conf;

    // does the category really exist?
    $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
    $result = pwg_query($query);
    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'category_id not found');
    }

    $query = '
SELECT COUNT(*)
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category_id'] . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$nb_images] = $row;

    if (! (bool) $conf['allow_random_representative'] and $nb_images != 0) {
        return new PwgError(401, 'not permitted');
    }

    $query = '
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = NULL
  WHERE id = ' . $params['category_id'] . '
;';
    pwg_query($query);

    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('album', $params['category_id'], 'edit');

    return null;
}

/**
 * API method
 *
 * Find a new album thumbnail.
 *
 * @param array{category_id: int, ...} $params no 'default' key -- mandatory,
 *   always present, WS_TYPE_ID guarantees a plain int.
 * @return PwgError|array<string, mixed>
 */
function ws_categories_refreshRepresentative(array $params, PwgServer &$service): PwgError|array
{
    global $conf;

    // does the category really exist?
    $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
    $result = pwg_query($query);
    if (pwg_db_num_rows($result) == 0) {
        return new PwgError(404, 'category_id not found');
    }

    $query = '
SELECT
    DISTINCT category_id
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $params['category_id'] . '
  LIMIT 1
;';
    $result = pwg_query($query);
    $has_images = pwg_db_num_rows($result) > 0 ? true : false;

    if (! $has_images) {
        return new PwgError(401, 'not permitted');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    set_random_representant([$params['category_id']]);

    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('album', $params['category_id'], 'edit');

    // return url of the new representative
    $query = '
SELECT *
  FROM ' . Tables::categories() . '
  WHERE id = ' . $params['category_id'] . '
;';
    $category = pwg_db_fetch_assoc(pwg_query($query));
    // the category's existence was already verified above, and nothing
    // in between could have deleted it
    assert(is_array($category));

    // set_random_representant() is expected to have populated
    // representative_picture_id above, but it's not a NOT NULL column, so
    // guard for real instead of assuming the update landed.
    $representative_picture_id = $category['representative_picture_id'];
    if (! is_string($representative_picture_id)) {
        return new PwgError(500, 'unable to determine a new representative picture for this category');
    }

    return get_category_representant_properties($representative_picture_id, IMG_SMALL);
}

/**
 * API method
 * Deletes a category
 *
 * @param array{category_id: string|array<array-key, string>, photo_deletion_mode: string, pwg_token: string, ...} $params
 *   category_id: WS_PARAM_ACCEPT_ARRAY (not FORCE), no 'default' key --
 *   mandatory, always present, accepts either a scalar or a bracket-array
 *   caller value (never null, since mandatory). photo_deletion_mode: no
 *   'type' flag, non-null default -- always a plain string. pwg_token: no
 *   'default' key, no flags -- mandatory, always present, plain string.
 */
function ws_categories_delete(array $params, PwgServer &$service): ?PwgError
{
    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $modes = ['no_delete', 'delete_orphans', 'force_delete'];
    if (! in_array($params['photo_deletion_mode'], $modes)) {
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

    if (count($category_ids) == 0) {
        return null;
    }

    $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
    // id is a NOT NULL primary key -- verified against
    // install/piwigo_structure-mysql.sql. query2array() is used directly
    // (rather than the deprecated array_from_query() wrapper) so the return
    // type stays precise enough to narrow.
    $category_ids = array_map(intval(...), array_filter(query2array($query, null, 'id'), is_numeric(...)));

    if (count($category_ids) == 0) {
        return null;
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    delete_categories($category_ids, $params['photo_deletion_mode']);
    update_global_rank();
    invalidate_user_cache();

    return null;
}

/**
 * API method
 * Moves a category
 *
 * @param array{category_id: string|array<array-key, string>, parent: int, pwg_token: string, ...} $params
 *   category_id: WS_PARAM_ACCEPT_ARRAY (not FORCE), no 'default' key --
 *   mandatory, always present, accepts either a scalar or a bracket-array
 *   caller value. parent: WS_TYPE_INT|WS_TYPE_POSITIVE, no 'default' key --
 *   mandatory, always a plain int. pwg_token: no 'default' key, no flags --
 *   mandatory, always present, plain string.
 * @return PwgError|array{new_ariane_string: string, updated_cats: array<int, array{cat_id: string, nb_sub_photos: int}>}
 */
function ws_categories_move(array $params, PwgServer &$service): PwgError|array
{
    /** @var array<string, mixed> $page */
    global $page;

    if ((new \Piwigo\Csrf\CsrfService())->getToken() != $params['pwg_token']) {
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

    if (count($category_ids) == 0) {
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
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // id is a NOT NULL primary key -- verified against
        // install/piwigo_structure-mysql.sql.
        $categories_in_db[(int) $row['id']] = $row;
        $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', (string) $row['uppercats']), 0, -1));

        // we break on error at first physical category detected
        if (! empty($row['dir'])) {
            $rendered_name = trigger_change(
                'render_category_name',
                $row['name'],
                'ws_categories_move'
            );
            $row['name'] = strip_tags(is_string($rendered_name) ? $rendered_name : '');

            return new PwgError(
                403,
                sprintf(
                    'Category %s (%u) is not a virtual category, you cannot move it',
                    $row['name'],
                    $row['id']
                )
            );
        }
    }

    if (count($categories_in_db) != count($category_ids)) {
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
    if ($params['parent'] != 0) {
        $subcat_ids = get_subcat_ids([$params['parent']]);
        if (count($subcat_ids) == 0) {
            return new PwgError(403, 'Unknown parent category id');
        }
    }

    $page['infos'] = [];
    $page['errors'] = [];

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    move_categories($category_ids, $params['parent']);
    invalidate_user_cache();

    // move_categories() mutates $page['errors'] from its own function scope
    // (global $page;) — get_defined_vars() (rather than reading $page
    // directly) keeps its real, post-call shape visible here instead of
    // appearing to still be exactly the empty array set above.
    $included_vars = get_defined_vars();
    /** @var array<string, mixed> $page */
    $page = $included_vars['page'];

    $page_errors = is_array($page['errors'] ?? null) ? $page['errors'] : [];
    if (count($page_errors) != 0) {
        return new PwgError(403, implode('; ', array_filter($page_errors, is_string(...))));
    }

    $query = '
  SELECT uppercats
    FROM ' . Tables::categories() . '
    WHERE id IN (' . implode(',', $category_ids) . ')
  ;';
    $result = pwg_query($query);
    $cat_display_name = '';
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // uppercats is a NOT NULL column of the categories table --
        // verified against install/piwigo_structure-mysql.sql.
        assert(is_string($row['uppercats']));
        $cat_display_name = get_cat_display_name_cache(
            $row['uppercats'],
            'admin.php?page=album-'
        );
        $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $row['uppercats']), 0, -1));
    }

    $query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM ' . Tables::imageCategory() . '
  GROUP BY category_id
;';

    $nb_photos_in = query2array($query, 'category_id', 'nb_photos');

    $update_cats = [];
    foreach (array_unique($update_cat_ids) as $update_cat) {
        $nb_sub_photos = 0;
        $sub_cat_without_parent = array_diff(get_subcat_ids([$update_cat]), [$update_cat]);

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
function ws_categories_calculateOrphans(array $param, PwgServer &$service): array
{
    global $conf;

    $category_id = $param['category_id'][0];

    $query = '
SELECT DISTINCT
    category_id
  FROM
    ' . Tables::imageCategory() . '
  WHERE
    category_id = ' . $category_id . '
  LIMIT 1';
    $result = pwg_query($query);
    $category = [];
    $category['has_images'] = pwg_db_num_rows($result) > 0 ? true : false;

    // number of sub-categories
    $subcat_ids = get_subcat_ids([$category_id]);

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
    $image_ids_recursive = query2array($query, null, 'image_id');

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

            $image_ids_associated_outside = query2array($query, null, 'image_id');
            $category['nb_images_associated_outside'] = count($image_ids_associated_outside);

            $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_associated_outside);
            $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
        }
        // else it's better to avoid sending a huge SQL request, we compute the orphan list with PHP
        else {
            // image_id is a NOT NULL column of image_category -- verified
            // against install/piwigo_structure-mysql.sql.
            $image_ids_recursive_keys = array_flip(array_filter($image_ids_recursive, is_string(...)));

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
            $image_ids_associated_outside = query2array($query, null, 'image_id');
            $image_ids_not_orphan = [];

            foreach ($image_ids_associated_outside as $image_id) {
                if (is_string($image_id) && isset($image_ids_recursive_keys[$image_id])) {
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
