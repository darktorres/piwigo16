<?php

declare(strict_types=1);

global $persistent_cache;

use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgNamedStruct;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * API method
 * Returns images per category
 * @param mixed[] $params
 *    @option int[] cat_id (optional)
 *    @option bool recursive
 *    @option int per_page
 *    @option int page
 *    @option string order (optional)
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_getImages(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    $raw_cat_id = is_array($params['cat_id']) ? $params['cat_id'] : [];
    /** @var int[] $cat_ids */
    $cat_ids = array_values(array_unique(array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $raw_cat_id)));

    if (count($cat_ids) > 0) {
        // do the categories really exist?
        $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $cat_ids).')
;';
        $db_cat_ids = \Piwigo\Db\QueryHelper::fetch($query, null, 'id');
        $missing_cat_ids = array_diff($cat_ids, array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $db_cat_ids));

        if (count($missing_cat_ids) > 0) {
            return new PwgError(404, 'cat_id {'.implode(',', $missing_cat_ids).'} not found');
        }
    }

    $images = [];
    $image_ids = [];
    $total_images = 0;

    //------------------------------------------------- get the related categories
    $where_clauses = [];
    foreach ($cat_ids as $cat_id_int) {
        if ($params['recursive']) {
            $where_clauses[] = 'uppercats '.DB_REGEX_OPERATOR.' \'(^|,)'.$cat_id_int.'(,|$)\'';
        } else {
            $where_clauses[] = 'id='.$cat_id_int;
        }
    }
    if (!empty($where_clauses)) {
        $where_clauses = ['('. implode("\n    OR ", $where_clauses) . ')'];
    }
    $where_clauses[] = get_sql_condition_FandF(
        ['forbidden_categories' => 'id'],
        null,
        true
    );

    $query = '
SELECT
    id,
    image_order
  FROM '. CATEGORIES_TABLE .'
  WHERE '. implode("\n    AND ", $where_clauses) .'
;';
    $cats = [];
    foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery($query)->fetchAllAssociative() as $row) {
        $row['id'] = is_numeric($row['id']) ? (int)$row['id'] : 0;
        $cats[ $row['id'] ] = $row;
    }

    //-------------------------------------------------------- get the images
    if (!empty($cats)) {
        /** @var string[] $where_clauses */
        $where_clauses = ws_std_image_sql_filter($params, 'i.');
        $where_clauses[] = 'category_id IN ('. implode(',', array_keys($cats)) .')';
        $where_clauses[] = get_sql_condition_FandF(['visible_images' => 'i.id'], null, true);

        $order_by = ws_std_image_sql_order($params, 'i.');
        if (empty($order_by)
              and count($cat_ids) == 1
              and isset($cats[ $cat_ids[0] ]['image_order'])
        ) {
            $order_by = is_scalar($cats[ $cat_ids[0] ]['image_order']) ? (string) $cats[ $cat_ids[0] ]['image_order'] : '';
        }
        $order_by = empty($order_by) ? \Piwigo\Config\Config::orderBy() : 'ORDER BY '.$order_by;
        $favorite_ids = get_user_favorites();

        $per_page = is_numeric($params['per_page']) ? (int) $params['per_page'] : 0;
        $page = is_numeric($params['page']) ? (int) $params['page'] : 0;

        $query = '
SELECT SQL_CALC_FOUND_ROWS i.*
  FROM '. IMAGES_TABLE .' i
    INNER JOIN '. IMAGE_CATEGORY_TABLE .' ON i.id=image_id
  WHERE '. implode("\n    AND ", $where_clauses) .'
  GROUP BY i.id
  '. $order_by .'
  LIMIT '. $per_page .'
  OFFSET '. ($per_page * $page) .'
;';
        $catImgConn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
        $catImgRows = $catImgConn->executeQuery($query)->fetchAllAssociative();

        foreach ($catImgRows as $row) {
            $image_ids[] = $row['id'];

            $image = [];
            $row_id_key = is_scalar($row['id']) ? (string) $row['id'] : '';
            $image['is_favorite'] = isset($favorite_ids[$row_id_key]);
            foreach (['id', 'width', 'height', 'hit'] as $k) {
                if (isset($row[$k])) {
                    $image[$k] = is_numeric($row[$k]) ? (int)$row[$k] : 0;
                }
            }
            foreach (['file', 'name', 'comment', 'date_creation', 'date_available'] as $k) {
                $image[$k] = $row[$k];
            }

            $image_name = is_scalar($image['name'] ?? null) ? (string) $image['name'] : '';
            $rendered_name = trigger_change('render_element_name', $image_name, __FUNCTION__);
            $image['name'] = strip_tags($rendered_name);
            $image['comment'] = trigger_change('render_element_description', $image['comment'] ?? null, __FUNCTION__);

            $image = array_merge($image, ws_std_get_urls($row));

            $images[] = $image;
        }

        $total_images_raw = $catImgConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
        $total_images = is_numeric($total_images_raw) ? (int)$total_images_raw : 0;

        // let's take care of adding the related albums to each photo
        if (count($image_ids) > 0) {
            $category_ids = [];

            // find the complete list (given permissions) of albums linked to photos
            $query = '
SELECT
    image_id,
    category_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE image_id IN ('.implode(',', array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $image_ids)).')
    AND '.get_sql_condition_FandF(['forbidden_categories' => 'category_id'], null, true).'
;';
            foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
                ->executeQuery($query)->fetchAllAssociative() as $row) {
                $category_ids[] = $row['category_id'];
                $row_image_id = is_scalar($row['image_id']) ? (string) $row['image_id'] : '';
                if ($row_image_id !== '') {
                    $categories_of_image[$row_image_id][] = $row['category_id'];
                }
            }

            $details_for_category = [];
            if (count($category_ids) > 0) {
                // find details (for URL generation) about each album
                $query = '
SELECT
    id,
    name,
    permalink
  FROM '. CATEGORIES_TABLE .'
  WHERE id IN ('. implode(',', array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $category_ids)) .')
;';
                $details_for_category = \Piwigo\Db\QueryHelper::fetch($query, 'id');
            }

            foreach ($images as $idx => $image) {
                $image_cats = [];

                // it should not be possible at this point, but let's consider a photo can be in no album
                $image_id_key = is_scalar($image['id']) ? (string) $image['id'] : '';
                if (!isset($categories_of_image[$image_id_key])) {
                    continue;
                }

                foreach ($categories_of_image[$image_id_key] as $cat_id) {
                    $cat_id_key = is_scalar($cat_id) ? (string) $cat_id : '';
                    if (!isset($details_for_category[$cat_id_key])) {
                        continue;
                    }
                    $url = make_index_url(['category' => $details_for_category[$cat_id_key]]);

                    $page_url = make_picture_url(
                        [
                        'category' => $details_for_category[$cat_id_key],
                        'image_id' => $image['id'],
                        'image_file' => $image['file'],
            ]
                    );

                    $image_cats[] = [
                      'id' => is_numeric($cat_id) ? (int)$cat_id : 0,
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
 * @param mixed[] $params
 *    @option int cat_id (optional)
 *    @option bool recursive
 *    @option bool public
 *    @option bool tree_output
 *    @option bool fullname
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_getList(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    $currentUser = \Piwigo\Users\CurrentUser::get();
    $user = $currentUser->rawAttributes;

    if (!in_array($params['thumbnail_size'], array_keys(ImageStdParams::get_defined_type_map()))) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid thumbnail_size');
    }

    if (!empty($params['limit']) and $params['recursive']) {
        return new PwgError(WS_ERR_INVALID_PARAM, 'Cannot use both recursive and limit parameters at the same time');
    }

    $output = [];
    $where = ['1=1'];
    $join_type = 'INNER';
    $join_user = $currentUser->id;

    $getlist_cat_id = is_numeric($params['cat_id']) ? (int) $params['cat_id'] : 0;

    if (!$params['recursive']) {
        if ($getlist_cat_id > 0) {
            $where[] = '(
        id_uppercat = '. $getlist_cat_id .'
        OR id='.$getlist_cat_id.'
      )';
        } else {
            $where[] = 'id_uppercat IS NULL';
        }
    } elseif ($getlist_cat_id > 0) {
        $where[] = 'uppercats '. DB_REGEX_OPERATOR .' \'(^|,)'.
          $getlist_cat_id .'(,|$)\'';
    }

    if ($params['public']) {
        $where[] = 'status = "public"';
        $where[] = 'visible = "true"';

        $join_user = \Piwigo\Config\Config::guestId();
    } elseif (is_admin()) {
        // in this very specific case, we don't want to hide empty
        // categories. Function calculate_permissions will only return
        // categories that are either locked or private and not permitted
        //
        // calculate_permissions does not consider empty categories as forbidden
        $forbidden_categories = calculate_permissions($currentUser->id, $currentUser->status);
        $where[] = 'id NOT IN ('.$forbidden_categories.')';
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
  FROM '. CATEGORIES_TABLE .'
    '.$join_type.' JOIN '. USER_CACHE_CATEGORIES_TABLE .'
    ON id=cat_id AND user_id='.$join_user.'
  WHERE '. implode("\n    AND ", $where);

    if (isset($params['search']) and '' != $params['search']) {
        $query .= '
    AND name LIKE '.get_dbal_connection()->quote('%'.(is_scalar($params['search']) ? (string) $params['search'] : '').'%');
        if (!isset($params['limit'])) {
            $query .= ' LIMIT '.\Piwigo\Config\Config::linkedAlbumSearchLimit();
        }
    }

    $limit_param = is_numeric($params['limit'] ?? null) ? (int) $params['limit'] : 0;
    $cat_id_param = is_numeric($params['cat_id'] ?? null) ? (int) $params['cat_id'] : 0;

    if (isset($params['limit'])) {
        $query .= '
  ORDER BY `rank` ASC
  LIMIT '.($limit_param + ($cat_id_param > 0 ? 1 : 0));
    }

    $query .= '
;';
    $getListConn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
    $getListRows = $getListConn->executeQuery($query)->fetchAllAssociative();

    if (isset($params['limit'])) {
        $result_count = $getListConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();
        $result_count_int = is_numeric($result_count) ? (int) $result_count : 0;
        if ($cat_id_param > 0) {
            $result_count_int = $result_count_int - 1;
        }
        $output['limit'] = [
          'limited_to' => $limit_param,
          'total_cats' => $result_count_int,
          'remaining_cats' => $result_count_int > $limit_param ? $result_count_int - $limit_param : 0,
        ];
    }

    // management of the album thumbnail -- starts here
    $image_ids = [];
    $categories = [];
    $user_representative_updates_for = [];
    // management of the album thumbnail -- stops here

    $cats = [];
    foreach ($getListRows as $row) {
        $row['url'] = make_index_url(
            [
            'category' => $row,
            ]
        );
        foreach (['id','nb_images','total_nb_images','nb_categories'] as $key) {
            $row[$key] = is_numeric($row[$key]) ? (int)$row[$key] : 0;
        }

        if ($params['fullname']) {
            $row['name'] = strip_tags(get_cat_display_name_cache(is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '', null));
        } else {
            $row['name_raw'] = $row['name'];

            $rendered_list_name = trigger_change(
                'render_category_name',
                is_scalar($row['name']) ? (string) $row['name'] : '',
                'ws_categories_getList'
            );
            $row['name'] = strip_tags($rendered_list_name);
        }

        $row['comment_raw'] = $row['comment'];

        $rendered_comment = trigger_change(
            'render_category_description',
            is_scalar($row['comment']) ? (string) $row['comment'] : '',
            'ws_categories_getList'
        );
        $row['comment'] = $rendered_comment;

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
        if (!empty($row['user_representative_picture_id'])) {
            $image_id = $row['user_representative_picture_id'];
        } elseif (!empty($row['representative_picture_id'])) { // if a representative picture is set, it has priority
            $image_id = $row['representative_picture_id'];
        } elseif (\Piwigo\Config\Config::allowRandomRepresentative()) {
            // searching a random representant among elements in sub-categories
            $image_id = get_random_image_in_category($row);
        } else { // searching a random representant among representant of sub-categories
            if ($row['count_categories'] > 0 and $row['count_images'] > 0) {
                $query = '
SELECT representative_picture_id
  FROM '. CATEGORIES_TABLE .'
    INNER JOIN '. USER_CACHE_CATEGORIES_TABLE .'
    ON id=cat_id AND user_id='.$currentUser->id.'
  WHERE uppercats LIKE \''.(is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '').',%\'
    AND representative_picture_id IS NOT NULL
        '.get_sql_condition_FandF(
                    ['visible_categories' => 'id'],
                    "\n  AND"
                ).'
  ORDER BY '. DB_RANDOM_FUNCTION .'()
  LIMIT 1
;';
                $subval = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
                    ->executeQuery($query)->fetchOne();
                if ($subval !== false) {
                    $image_id = is_numeric($subval) ? (int) $subval : null;
                }
            }
        }

        if (isset($image_id)) {
            if (\Piwigo\Config\Config::representativeCacheOnSubcats() and $row['user_representative_picture_id'] != $image_id) {
                $user_representative_updates_for[ $row['id'] ] = $image_id;
            }

            $row['representative_picture_id'] = $image_id;
            $image_ids[] = $image_id;
            $categories[] = $row;
        }
        unset($image_id);
        // management of the album thumbnail -- stops here

        if (empty($row['image_order'])) {
            $row['image_order'] = str_replace('ORDER BY ', '', \Piwigo\Config\Config::orderBy());
        }

        $cats[] = $row;
    }
    usort($cats, global_rank_compare(...));

    // management of the album thumbnail -- starts here
    if (count($categories) > 0) {
        $thumbnail_src_of = [];
        $new_image_ids = [];

        $thumbnail_size = is_scalar($params['thumbnail_size']) ? (string) $params['thumbnail_size'] : '';
        $imgRepoWsCats = \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class);
        foreach ($imgRepoWsCats->findByIds(array_map(fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, $image_ids)) as $row) {
            if ($row['level'] <= $user['level']) {
                $thumbnail_src_of[is_scalar($row['id']) ? (string) $row['id'] : ''] = DerivativeImage::url($thumbnail_size, $row);
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
                        $image_id = get_random_image_in_category($category);

                        if (isset($image_id) and !in_array($image_id, $image_ids)) {
                            $new_image_ids[] = $image_id;
                        }
                        if (\Piwigo\Config\Config::representativeCacheOnLevel()) {
                            $user_representative_updates_for[ is_numeric($category['id']) ? (int) $category['id'] : 0 ] = $image_id;
                        }

                        $category['representative_picture_id'] = $image_id;
                    }
                }
                unset($category);
            }
        }

        if (count($new_image_ids) > 0) {
            foreach ($imgRepoWsCats->findByIds(array_map('intval', $new_image_ids)) as $row) {
                $thumbnail_src_of[is_scalar($row['id']) ? (string) $row['id'] : ''] = DerivativeImage::url($thumbnail_size, $row);
            }
        }
    }

    // compared to code in include/category_cats, we only persist the new
    // user_representative if we have used $user['id'] and not the guest id,
    // or else the real guest may see thumbnail that he should not
    if (!$params['public'] and count($user_representative_updates_for)) {
        $updates = [];

        foreach ($user_representative_updates_for as $cat_id => $image_id) {
            $updates[] = [
              'user_id' => $user['id'],
              'cat_id' => $cat_id,
              'user_representative_picture_id' => $image_id,
              ];
        }

        mass_updates(
            USER_CACHE_CATEGORIES_TABLE,
            [
            'primary' => ['user_id', 'cat_id'],
            'update'  => ['user_representative_picture_id'],
            ],
            $updates
        );
    }

    foreach ($cats as &$cat) {
        foreach ($categories as $category) {
            if ($category['id'] == $cat['id'] and isset($category['representative_picture_id'])) {
                $rep_key = is_scalar($category['representative_picture_id']) ? (string) $category['representative_picture_id'] : '';
                $cat['tn_url'] = $thumbnail_src_of[$rep_key] ?? null;
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
 * @param mixed[] $params
 *
 * Only admin can run this method and permissions are not taken into
 * account.
 */
/**
 * @return array<mixed>
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_getAdminList(array $params, \Piwigo\Ws\PwgServer &$service): array
{
    if (!isset($params['additional_output'])) {
        $params['additional_output'] = '';
    }
    $params['additional_output'] = array_map(trim(...), explode(',', is_scalar($params['additional_output']) ? (string) $params['additional_output'] : ''));

    $query = '
SELECT category_id, COUNT(*) AS counter
  FROM '. IMAGE_CATEGORY_TABLE .'
  GROUP BY category_id
;';
    $nb_images_of = \Piwigo\Db\QueryHelper::fetch($query, 'category_id', 'counter');

    // pwg_db_real_escape_string

    $where = ['1=1'];

    $admin_cat_id = is_numeric($params['cat_id']) ? (int) $params['cat_id'] : 0;

    if (!$params['recursive']) {
        if ($admin_cat_id > 0) {
            $where[] = '(
        id_uppercat = '. $admin_cat_id .'
        OR id='.$admin_cat_id.'
      )';
        } else {
            $where[] = 'id_uppercat IS NULL';
        }
    } elseif ($admin_cat_id > 0) {
        $where[] = 'uppercats '. DB_REGEX_OPERATOR .' \'(^|,)'.
          $admin_cat_id .'(,|$)\'';
    }

    $query = '
SELECT SQL_CALC_FOUND_ROWS id, name, comment, uppercats, global_rank, dir, status, image_order
  FROM '. CATEGORIES_TABLE .'
  WHERE '. implode("\n    AND ", $where);

    if (isset($params['search']) and $params['search'] != '') {
        $query .= '
  AND name LIKE '.get_dbal_connection()->quote('%'.(is_scalar($params['search']) ? (string) $params['search'] : '').'%').'
  LIMIT '.\Piwigo\Config\Config::linkedAlbumSearchLimit();
    }

    $query .= '
;';
    $searchConn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
    $searchRows = $searchConn->executeQuery($query)->fetchAllAssociative();
    $counter = $searchConn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();

    $cats = [];
    foreach ($searchRows as $row) {
        $id = is_scalar($row['id']) ? (string) $row['id'] : '';
        $row['nb_images'] = $nb_images_of[$id] ?? 0;

        $cat_display_name = get_cat_display_name_cache(
            is_scalar($row['uppercats']) ? (string) $row['uppercats'] : '',
            'admin.php?page=album-'
        );

        $row['name_raw'] = $row['name'];

        $rendered_admin_name = trigger_change(
            'render_category_name',
            is_scalar($row['name']) ? (string) $row['name'] : '',
            'ws_categories_getAdminList'
        );
        $row['name'] = strip_tags($rendered_admin_name);
        $row['fullname'] = strip_tags($cat_display_name);

        $row['comment_raw'] = $row['comment'];
        $row['comment'] = trigger_change(
            'render_category_description',
            $row['comment'] ?? '',
            'ws_categories_getAdminList'
        );

        if (empty($row['image_order'])) {
            $row['image_order'] = str_replace('ORDER BY ', '', \Piwigo\Config\Config::orderBy());
        }

        if (in_array('full_name_with_admin_links', $params['additional_output'])) {
            $row['full_name_with_admin_links'] = $cat_display_name;
        }

        $cats[] = $row;
    }

    if (!$params['recursive']) {
        $cats_ids = array_column($cats, 'id');
        $nb_subcats_of = [];
        if (!empty($cats_ids)) {
            $query = '
SELECT
    id_uppercat,
    COUNT(*) AS nb_subcats
  FROM '. CATEGORIES_TABLE .'
  WHERE id_uppercat IN ('. implode(',', array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $cats_ids)) .')
  GROUP BY id_uppercat
';

            $nb_subcats_of = \Piwigo\Db\QueryHelper::fetch($query, 'id_uppercat', 'nb_subcats');
        }

        foreach ($cats as $idx => $cat) {
            $cat_id_key = is_scalar($cat['id']) ? (string) $cat['id'] : '';
            $cats[$idx]['nb_categories'] = intval($nb_subcats_of[$cat_id_key] ?? 0);
        }
    }

    $limit_reached = false;
    if ($counter > \Piwigo\Config\Config::linkedAlbumSearchLimit()) {
        $limit_reached = true;
    }

    usort($cats, global_rank_compare(...));
    return [
      'categories' => new PwgNamedArray(
          $cats,
          'category',
          ['id', 'nb_images', 'name', 'uppercats', 'global_rank', 'status', 'test']
      ),
      'limit' => \Piwigo\Config\Config::linkedAlbumSearchLimit(),
      'limit_reached' => $limit_reached,
      ];
}

/**
 * API method
 * Adds a category
 * @param mixed[] $params
 *    @option string name
 *    @option int parent (optional)
 *    @option string comment (optional)
 *    @option bool visible
 *    @option string status (optional)
 *    @option bool commentable
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_add(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if (isset($params['pwg_token']) and get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (!empty($params['position']) and in_array($params['position'], ['first','last'])) {
        // DEFERRED: persist position choice to user preferences instead of only overriding for this request.
        \Piwigo\Config\Config::override('newcat_default_position', is_scalar($params['position']) ? (string) $params['position'] : '');
    }

    $options = [];
    if (!empty($params['status']) and in_array($params['status'], ['private','public'])) {
        $options['status'] = $params['status'];
    }

    if (!empty($params['comment'])) {
        $comment_str = is_scalar($params['comment']) ? (string) $params['comment'] : '';
        $options['comment'] = (!\Piwigo\Config\Config::allowHtmlDescriptions() or !isset($params['pwg_token'])) ? strip_tags($comment_str) : $comment_str;
    }

    $cat_name = (!\Piwigo\Config\Config::allowHtmlDescriptions() or !isset($params['pwg_token'])) ? strip_tags(is_scalar($params['name']) ? (string) $params['name'] : '') : (is_scalar($params['name']) ? (string) $params['name'] : '');
    $cat_parent = is_numeric($params['parent']) ? (int) $params['parent'] : (is_string($params['parent']) ? $params['parent'] : null);

    $creation_output = create_virtual_category(
        $cat_name,
        $cat_parent,
        $options
    );

    if (isset($creation_output['error'])) {
        return new PwgError(500, is_scalar($creation_output['error']) ? (string) $creation_output['error'] : '');
    }

    invalidate_user_cache();

    return $creation_output;
}

/**
 * API method
 * Set the rank of a category
 * @param mixed[] $params
 *    @option int cat_id
 *    @option int rank
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_setRank(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    // does the category really exist?
    $raw_setrank_ids = is_array($params['category_id']) ? $params['category_id'] : [];
    /** @var int[] $setrank_category_ids */
    $setrank_category_ids = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $raw_setrank_ids);
    $query = '
SELECT id, id_uppercat, `rank`
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', $setrank_category_ids).')
;';
    $categories = \Piwigo\Db\QueryHelper::fetch($query);

    if (count($categories) == 0) {
        return new PwgError(404, 'category_id not found');
    }

    $category = $categories[0];

    //check the number of category given by the user
    if (count($setrank_category_ids) > 1) {
        $order_new = $setrank_category_ids;
        $order_new_by_id = $order_new;
        sort($order_new_by_id, SORT_NUMERIC);

        $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id_uppercat '.(empty($category['id_uppercat']) ? 'IS NULL' : '= '.(string) $category['id_uppercat']).'
  ORDER BY `id` ASC
;';

        $cat_asc = \Piwigo\Db\QueryHelper::fetch($query, null, 'id');

        $cat_asc_str = array_map(fn ($v): string => is_scalar($v) ? (string) $v : '', $cat_asc);
        $order_new_str = array_map(fn (int $v): string => (string) $v, $order_new_by_id);
        if (strcmp(implode(',', $cat_asc_str), implode(',', $order_new_str)) !== 0) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'you need to provide all sub-category ids for a given category');
        }
        $order_new = $setrank_category_ids;
    } else {
        $single_cat_id = implode('', array_map(fn (int $v): string => (string) $v, $setrank_category_ids));
        $id_uppercat_str = is_scalar($category['id_uppercat']) ? (string) $category['id_uppercat'] : '';

        $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id_uppercat '.(empty($id_uppercat_str) ? 'IS NULL' : '= '.$id_uppercat_str).'
    AND id != '.$single_cat_id.'
  ORDER BY `rank` ASC
;';

        $order_old = \Piwigo\Db\QueryHelper::fetch($query, null, 'id');
        $rank_target = is_numeric($params['rank']) ? (int) $params['rank'] : 0;
        $order_new = [];
        $was_inserted = false;
        $i = 1;
        foreach ($order_old as $category_id) {
            if ($i == $rank_target) {
                $order_new[] = $single_cat_id;
                $was_inserted = true;
            }
            $order_new[] = $category_id;
            ++$i;
        }

        if (!$was_inserted) {
            $order_new[] = $single_cat_id;
        }
    }
    // include function to set the global rank
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    save_categories_order($order_new);
    return null;
}

/**
 * API method
 * Sets details of a category
 * @param mixed[] $params
 *    @option int cat_id
 *    @option string name (optional)
 *    @option string status (optional)
 *    @option bool visible (optional)
 *    @option string comment (optional)
 *    @option bool commentable (optional)
 *    @option bool apply_commentable_to_subalbums (optional)
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_setInfo(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (isset($params['pwg_token']) and get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $category_id = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;

    // does the category really exist?
    $query = '
SELECT *
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$category_id.'
;';
    $categories = \Piwigo\Db\QueryHelper::fetch($query);
    if (count($categories) == 0) {
        return new PwgError(404, 'category_id not found');
    }

    $category = $categories[0];

    if (!empty($params['status'])) {
        if (!in_array($params['status'], ['private','public'])) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid status, only public/private');
        }

        if ($params['status'] != $category['status']) {
            include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
            set_cat_status([$category_id], is_scalar($params['status']) ? (string) $params['status'] : '');
        }
    }

    $update = [
      'id' => $category_id,
      ];

    foreach (['visible', 'commentable'] as $param_name) {
        $param_val_str = is_scalar($params[$param_name] ?? null) ? (string) $params[$param_name] : '';
        if (isset($params[$param_name]) and !preg_match('/^(true|false)$/i', $param_val_str)) {
            return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid param '.$param_name.' : '.$param_val_str);
        }
    }

    if (!empty($params['visible']) and ($params['visible'] != $category['visible'])) {
        include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
        set_cat_visible([$category_id], is_string($params['visible']) ? $params['visible'] : (is_bool($params['visible']) ? $params['visible'] : false));
    }

    $info_columns = ['name', 'comment','commentable'];

    $perform_update = false;
    foreach ($info_columns as $key) {
        if (isset($params[$key])) {
            $perform_update = true;
            $key_val_str = is_scalar($params[$key]) ? (string) $params[$key] : '';
            $update[$key] = (!\Piwigo\Config\Config::allowHtmlDescriptions() or !isset($params['pwg_token'])) ? strip_tags($key_val_str) : $key_val_str;
        }
    }

    if (isset($params['commentable']) && isset($params['apply_commentable_to_subalbums']) && $params['apply_commentable_to_subalbums']) {
        $subcats = get_subcat_ids([$category_id]);
        if (count($subcats) > 0) {
            $commentableVal = is_scalar($params['commentable']) ? (string) $params['commentable'] : 'false';
            \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
                ->setCommentable(array_map('intval', $subcats), $commentableVal === 'true');
        }
    }

    if ($perform_update) {
        single_update(
            CATEGORIES_TABLE,
            $update,
            ['id' => $update['id']]
        );
    }

    pwg_activity('album', $category_id, 'edit', ['fields' => implode(',', array_keys($update))]);
    return null;
}

/**
 * API method
 * Sets representative image of a category
 * @param mixed[] $params
 *    @option int category_id
 *    @option int image_id
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_setRepresentative(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    $category_id = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;
    $image_id = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;

    $catRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class);
    $imgRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Image\ImageRepository::class);

    // does the category really exist?
    if (!$catRepo->existsById($category_id)) {
        return new PwgError(404, 'category_id not found');
    }

    // does the image really exist?
    if (!$imgRepo->existsById($image_id)) {
        return new PwgError(404, 'image_id not found');
    }

    // apply change
    $catRepo->setRepresentativePicture([$category_id], $image_id);

    \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
        ->clearUserRepresentativeForCategory($category_id);

    pwg_activity('album', $category_id, 'edit', ['image_id' => $image_id]);
    return null;
}

/**
 * API method
 *
 * Deletes the album thumbnail. Only possible if
 * \Piwigo\Config\Config::allowRandomRepresentative() or if the album has no direct photos.
 *
 * @param mixed[] $params
 *    @option int category_id
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_deleteRepresentative(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    $category_id = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;

    $catRepo2 = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class);

    // does the category really exist?
    if (!$catRepo2->existsById($category_id)) {
        return new PwgError(404, 'category_id not found');
    }

    $nb_images = $catRepo2->countImagesByCategoryId($category_id);

    if (!\Piwigo\Config\Config::allowRandomRepresentative() and $nb_images != 0) {
        return new PwgError(401, 'not permitted');
    }

    $catRepo2->clearRepresentatives([$category_id]);

    pwg_activity('album', $category_id, 'edit');
    return null;
}

/**
 * API method
 *
 * Find a new album thumbnail.
 *
 * @param mixed[] $params
 *    @option int category_id
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_refreshRepresentative(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    $category_id = is_numeric($params['category_id']) ? (int) $params['category_id'] : 0;

    $catRepo3 = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class);

    // does the category really exist?
    if (!$catRepo3->existsById($category_id)) {
        return new PwgError(404, 'category_id not found');
    }

    if (!$catRepo3->hasCategoryImages($category_id)) {
        return new PwgError(401, 'not permitted');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    set_random_representant([$category_id]);

    pwg_activity('album', $category_id, 'edit');

    // return url of the new representative
    $category = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
        ->findCategoryById($category_id);

    $rep_id = isset($category['representative_picture_id']) ? (is_scalar($category['representative_picture_id']) ? (string) $category['representative_picture_id'] : '') : '';
    return get_category_representant_properties($rep_id, IMG_SMALL);
}

/**
 * API method
 * Deletes a category
 * @param mixed[] $params
 *    @option string|int[] category_id
 *    @option string photo_deletion_mode
 *    @option string pwg_token
 */
/**
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_delete(array $params, \Piwigo\Ws\PwgServer &$service): mixed
{
    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $photo_deletion_mode = is_scalar($params['photo_deletion_mode']) ? (string) $params['photo_deletion_mode'] : '';
    $modes = ['no_delete', 'delete_orphans', 'force_delete'];
    if (!in_array($photo_deletion_mode, $modes)) {
        return new PwgError(
            500,
            '[ws_categories_delete]'
      .' invalid parameter photo_deletion_mode "'.$photo_deletion_mode.'"'
      .', possible values are {'.implode(', ', $modes).'}.'
        );
    }

    if (!is_array($params['category_id'])) {
        $params['category_id'] = preg_split(
            '/[\s,;\|]/',
            is_scalar($params['category_id']) ? (string) $params['category_id'] : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }
    $params['category_id'] = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);

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
  FROM '. CATEGORIES_TABLE .'
  WHERE id IN ('. implode(',', $category_ids) .')
;';
    $raw_category_ids = \Piwigo\Db\QueryHelper::fetch($query, null, 'id');

    if (count($raw_category_ids) == 0) {
        return null;
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    delete_categories(array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $raw_category_ids), $photo_deletion_mode);
    update_global_rank();
    invalidate_user_cache();
    return null;
}

/**
 * API method
 * Moves a category
 * @param mixed[] $params
 *    @option string|int[] category_id
 *    @option int parent
 *    @option string pwg_token
 */
/**
 * @return array<mixed>|\Piwigo\Ws\PwgError
 * @param array<mixed> $params
 * @param array<mixed> $params
 */function ws_categories_move(array $params, \Piwigo\Ws\PwgServer &$service): PwgError|array
{
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (!is_array($params['category_id'])) {
        $params['category_id'] = preg_split(
            '/[\s,;\|]/',
            is_scalar($params['category_id']) ? (string) $params['category_id'] : '',
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
    }
    $params['category_id'] = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $params['category_id']);

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
  FROM '. CATEGORIES_TABLE .'
  WHERE id IN ('. implode(',', $category_ids) .')
;';
    $parent_id = is_numeric($params['parent']) ? (int) $params['parent'] : 0;

    foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
        ->findByIds(array_map('intval', $category_ids)) as $row) {
        $row_id = is_scalar($row['id']) ? (string) $row['id'] : '';
        $categories_in_db[$row_id] = $row;
        $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', is_scalar($row['uppercats']) ? (string) $row['uppercats'] : ''), 0, -1));

        // we break on error at first physical category detected
        if (!empty($row['dir'])) {
            $rendered_move_name = trigger_change(
                'render_category_name',
                is_scalar($row['name']) ? (string) $row['name'] : '',
                'ws_categories_move'
            );
            $row['name'] = strip_tags($rendered_move_name);

            return new PwgError(
                403,
                sprintf(
                    'Category %s (%u) is not a virtual category, you cannot move it',
                    $row['name'],
                    is_numeric($row['id']) ? (int) $row['id'] : 0
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
                (int) $unknown_category_ids[0]
            )
        );
    }

    // does this parent exists? This check should be made in the
    // move_categories function, not here
    // 0 as parent means "move categories at gallery root"
    if (0 != $parent_id) {
        $subcat_ids = get_subcat_ids([$parent_id]);
        if (count($subcat_ids) == 0) {
            return new PwgError(403, 'Unknown parent category id');
        }
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
    move_categories($category_ids, $parent_id);
    invalidate_user_cache();

    $query = '
  SELECT uppercats
    FROM '. CATEGORIES_TABLE .'
    WHERE id IN ('. implode(',', $category_ids) .')
  ;';
    $cat_display_name = '';
    foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
        ->findUppercatsByIds(array_map('intval', $category_ids)) as $uppercats_str) {
        $cat_display_name = get_cat_display_name_cache(
            $uppercats_str,
            'admin.php?page=album-'
        );
        $update_cat_ids = array_merge($update_cat_ids, array_slice(explode(',', $uppercats_str), 0, -1));
    }

    $query = '
SELECT
    category_id,
    COUNT(*) AS nb_photos
  FROM '.IMAGE_CATEGORY_TABLE.'
  GROUP BY category_id
;';

    $nb_photos_in = \Piwigo\Db\QueryHelper::fetch($query, 'category_id', 'nb_photos');

    $update_cats = [];
    foreach (array_unique($update_cat_ids) as $update_cat) {
        $nb_sub_photos = 0;
        $sub_cat_without_parent = array_diff(get_subcat_ids([$update_cat]), [$update_cat]);

        foreach ($sub_cat_without_parent as $id_sub_cat) {
            $nb_sub_photos += $nb_photos_in[(string) $id_sub_cat] ?? 0;
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
 */
/**
 * @param array<mixed> $param
 * @param array<mixed> $param
 */function ws_categories_calculateOrphans(array $param, \Piwigo\Ws\PwgServer &$service): mixed
{
    $param_cat_id = is_array($param['category_id']) ? $param['category_id'] : [];
    $category_id = is_numeric($param_cat_id[0] ?? null) ? (int) $param_cat_id[0] : 0;

    $category['has_images'] = \Piwigo\Core\ServiceLocator::get(\Piwigo\Category\CategoryRepository::class)
        ->hasCategoryImages($category_id);

    // number of sub-categories
    $subcat_ids = get_subcat_ids([$category_id]);

    $category['nb_subcats'] = count($subcat_ids) - 1;

    // total number of images under this category (including sub-categories)
    $query = '
SELECT DISTINCT
    (image_id)
  FROM 
    '.IMAGE_CATEGORY_TABLE.'
  WHERE 
    category_id IN ('.implode(',', $subcat_ids).')
  ;';
    $image_ids_recursive = \Piwigo\Db\QueryHelper::fetch($query, null, 'image_id');

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
      '.IMAGE_CATEGORY_TABLE.'
    WHERE 
      category_id 
    NOT IN 
      ('.implode(',', $subcat_ids).')
    AND 
      image_id 
    IN 
      ('.implode(',', $image_ids_recursive).')
  ;';

            $image_ids_associated_outside = \Piwigo\Db\QueryHelper::fetch($query, null, 'image_id');
            $category['nb_images_associated_outside'] = count($image_ids_associated_outside);

            $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_associated_outside);
            $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
        }
        // else it's better to avoid sending a huge SQL request, we compute the orphan list with PHP
        else {
            $image_ids_recursive_keys = array_flip(array_map('strval', $image_ids_recursive));

            $query = '
  SELECT
      image_id
    FROM 
      '.IMAGE_CATEGORY_TABLE.'
    WHERE 
      category_id 
    NOT IN 
      ('.implode(',', $subcat_ids).')
  ;';
            $image_ids_associated_outside = \Piwigo\Db\QueryHelper::fetch($query, null, 'image_id');
            $image_ids_not_orphan = [];

            foreach ($image_ids_associated_outside as $image_id) {
                if (isset($image_ids_recursive_keys[is_scalar($image_id) ? (string) $image_id : ''])) {
                    $image_ids_not_orphan[] = $image_id;
                }
            }

            $category['nb_images_associated_outside'] = count(array_unique($image_ids_not_orphan));
            $image_ids_becoming_orphan = array_diff($image_ids_recursive, $image_ids_not_orphan);
            $category['nb_images_becoming_orphan'] = count($image_ids_becoming_orphan);
        }
    }

    $output[] = [
      'nb_images_associated_outside' => $category['nb_images_associated_outside'],
      'nb_images_becoming_orphan' => $category['nb_images_becoming_orphan'],
      'nb_images_recursive' => $category['nb_images_recursive'],
    ];

    return $output;
}
