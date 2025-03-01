<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_filter;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Piwigo\inc\SrcImage;

/**
 * This file is included by the main page to show subcategories of a category
 * or to show recent categories or main page categories list
 */

// $user['forbidden_categories'] including with user_cache_categories
$query = '
SELECT SQL_CALC_FOUND_ROWS
    c.*,
    user_representative_picture_id,
    nb_images,
    date_last,
    max_date_last,
    count_images,
    nb_categories,
    count_categories
  FROM categories c
    INNER JOIN user_cache_categories ucc
    ON id = cat_id
    AND user_id = ' . $user['id'] . '
  WHERE count_images > 0
';

if ($page['section'] == 'recent_cats') {
    $query .= '
  AND ' . functions_user::get_recent_photos_sql('date_last');
} else {
    $query .= '
  AND id_uppercat ' . (! isset($page['category']) ? 'is NULL' : '= ' . $page['category']['id']);
}

$query .= '
      ' . functions_user::get_sql_condition_FandF(
    [
        'visible_categories' => 'id',
    ],
    'AND'
);

// special string to let plugins modify this query at this exact position
$query .= '
-- after conditions
';

if ($page['section'] != 'recent_cats') {
    $query .= '
  ORDER BY `rank`';
}

$query .= '
  LIMIT ' . $conf['nb_categories_page'] . ' OFFSET ' . ($page['startcat'] ?? 0) . '
;';

$query = functions_plugins::trigger_change('loc_begin_index_category_thumbnails_query', $query);

$result = functions_mysqli::pwg_query($query);
list($page['total_categories']) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT FOUND_ROWS()'));

$categories = [];
$category_ids = [];
$image_ids = [];
$user_representative_updates_for = [];

while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
    $row['is_child_date_last'] = @$row['max_date_last'] > @$row['date_last'];

    if (! empty($row['user_representative_picture_id'])) {
        $image_id = $row['user_representative_picture_id'];
    } elseif (! empty($row['representative_picture_id'])) { // if a representative picture is set, it has priority
        $image_id = $row['representative_picture_id'];
    } elseif ($conf['allow_random_representative']) { // searching a random representative among elements in sub-categories
        $image_id = functions_category::get_random_image_in_category($row);
    } elseif ($row['count_categories'] > 0 and
              $row['count_images'] > 0
    ) { // at this point, $row['count_images'] should always be >0 (used as condition in SQL)
        // searching a random representative among representative of sub-categories
        $query = '
SELECT representative_picture_id
  FROM categories INNER JOIN user_cache_categories
  ON id = cat_id and user_id = ' . $user['id'] . '
  WHERE uppercats LIKE \'' . $row['uppercats'] . ',%\'
    AND representative_picture_id IS NOT NULL'
  . functions_user::get_sql_condition_FandF(
      [
          'visible_categories' => 'id',
      ],
      "\n  AND"
  ) . '
  ORDER BY ' . functions_mysqli::DB_RANDOM_FUNCTION . '()
  LIMIT 1
;';
        $subresult = functions_mysqli::pwg_query($query);

        if (functions_mysqli::pwg_db_num_rows($subresult) > 0) {
            list($image_id) = functions_mysqli::pwg_db_fetch_row($subresult);
        }
    }

    if (isset($image_id)) {
        if ($conf['representative_cache_on_subcats'] and
            $row['user_representative_picture_id'] != $image_id
        ) {
            $user_representative_updates_for[$row['id']] = $image_id;
        }

        $row['representative_picture_id'] = $image_id;
        $image_ids[] = $image_id;
        $categories[] = $row;
        $category_ids[] = $row['id'];
    } else {
        $logger->info(
            sprintf(
                '[%s] category #%u was listed in SQL but no image_id found, so it was skipped',
                basename(__FILE__),
                $row['id']
            )
        );
    }

    unset($image_id);
}

if ($conf['display_fromto']) {
    if (count($category_ids) > 0) {
        $query = '
SELECT
    category_id,
    MIN(date_creation) AS `from`,
    MAX(date_creation) AS `to`
  FROM image_category
    INNER JOIN images ON image_id = id
  WHERE category_id IN (' . implode(',', $category_ids) . ')
' . functions_user::get_sql_condition_FandF(
            [
                'visible_categories' => 'category_id',
                'visible_images' => 'id',
            ],
            'AND'
        ) . '
  GROUP BY category_id
;';
        $dates_of_category = functions_mysqli::query2array($query, 'category_id');
    }
}

if ($page['section'] == 'recent_cats') {
    usort($categories, functions_category::global_rank_compare(...));
}

if (count($categories) > 0) {
    $infos_of_image = [];
    $new_image_ids = [];

    $query = '
SELECT *
  FROM images
  WHERE id IN (' . implode(',', $image_ids) . ')
;';
    $result = functions_mysqli::pwg_query($query);

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        if ($row['level'] <= $user['level']) {
            $infos_of_image[$row['id']] = $row;
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
                    // searching a random representative among elements in sub-categories
                    $image_id = functions_category::get_random_image_in_category($category);

                    if (isset($image_id) and
                        ! in_array($image_id, $image_ids)
                    ) {
                        $new_image_ids[] = $image_id;
                    }

                    if ($conf['representative_cache_on_level']) {
                        $user_representative_updates_for[$category['id']] = $image_id;
                    }

                    $category['representative_picture_id'] = $image_id;
                }
            }

            unset($category);
        }
    }

    if (count($new_image_ids) > 0) {
        $query = '
SELECT *
  FROM images
  WHERE id IN (' . implode(',', $new_image_ids) . ')
;';
        $result = functions_mysqli::pwg_query($query);

        while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
            $infos_of_image[$row['id']] = $row;
        }
    }

    foreach ($infos_of_image as &$info) {
        $info['src_image'] = new SrcImage($info);
    }

    unset($info);
}

if (count($user_representative_updates_for)) {
    $updates = [];

    foreach ($user_representative_updates_for as $cat_id => $image_id) {
        $updates[] =
          [
              'user_id' => $user['id'],
              'cat_id' => $cat_id,
              'user_representative_picture_id' => $image_id,
          ];
    }

    functions_mysqli::mass_updates(
        'user_cache_categories',
        [
            'primary' => ['user_id', 'cat_id'],
            'update' => ['user_representative_picture_id'],
        ],
        $updates
    );
}

if (count($categories) > 0) {
    // Update filtered data
    if (function_exists('\Piwigo\inc\functions_filter::update_cats_with_filtered_data')) {
        functions_filter::update_cats_with_filtered_data($categories);
    }

    $template->set_filename('index_category_thumbnails', 'mainpage_categories.tpl');

    functions_plugins::trigger_notify('loc_begin_index_category_thumbnails', $categories);

    $tpl_thumbnails_var = [];

    foreach ($categories as $category) {
        if ($category['count_images'] == 0) {
            continue;
        }

        $category['name'] = functions_plugins::trigger_change(
            'render_category_name',
            $category['name'],
            'subcatify_category_name'
        );

        if ($page['section'] == 'recent_cats') {
            $name = functions_html::get_cat_display_name_cache($category['uppercats'], null);
        } else {
            $name = $category['name'];
        }

        $representative_infos = $infos_of_image[$category['representative_picture_id']];

        $tpl_var = array_merge($category, [
            'ID' => $category['id'] /*obsolete*/,
            'representative' => $representative_infos,
            'TN_ALT' => strip_tags($category['name']),

            'URL' => functions_url::make_index_url(
                [
                    'category' => $category,
                ]
            ),
            'CAPTION_NB_IMAGES' => functions_category::get_display_images_count(
                $category['nb_images'],
                $category['count_images'],
                $category['count_categories'],
                true,
                '<br>'
            ),
            'DESCRIPTION' =>
              functions_plugins::trigger_change(
                  'render_category_literal_description',
                  functions_plugins::trigger_change(
                      'render_category_description',
                      @$category['comment'],
                      'subcatify_category_description'
                  )
              ),
            'NAME' => $name,
        ]);

        if ($conf['index_new_icon']) {
            $tpl_var['icon_ts'] = functions::get_icon($category['max_date_last'], $category['is_child_date_last']);
        }

        if ($conf['display_fromto']) {
            if (isset($dates_of_category[$category['id']])) {
                $from = $dates_of_category[$category['id']]['from'];
                $to = $dates_of_category[$category['id']]['to'];

                if (! empty($from)) {
                    $tpl_var['INFO_DATES'] = functions::format_fromto($from, $to);
                }
            }
        }

        $tpl_thumbnails_var[] = $tpl_var;
    }

    // pagination
    $tpl_thumbnails_var_selection = $tpl_thumbnails_var;

    $derivative_params = functions_plugins::trigger_change('get_index_album_derivative_params', ImageStdParams::get_by_type(derivative_std_params::IMG_THUMB));
    $tpl_thumbnails_var_selection = functions_plugins::trigger_change('loc_end_index_category_thumbnails', $tpl_thumbnails_var_selection);
    $template->assign([
        'maxRequests' => $conf['max_requests'],
        'category_thumbnails' => $tpl_thumbnails_var_selection,
        'derivative_params' => $derivative_params,
    ]);

    $template->assign_var_from_handle('CATEGORIES', 'index_category_thumbnails');

    // navigation bar
    $page['cats_navigation_bar'] = [];

    if ($page['total_categories'] > $conf['nb_categories_page']) {
        $page['cats_navigation_bar'] = functions::create_navigation_bar(
            functions_url::duplicate_index_url([], ['startcat']),
            $page['total_categories'],
            $page['startcat'],
            $conf['nb_categories_page'],
            true,
            'startcat'
        );
    }

    $template->assign('cats_navbar', $page['cats_navigation_bar']);
}

functions::pwg_debug('end inc/category_cats.php');
