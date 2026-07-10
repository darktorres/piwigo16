<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the main page to show subcategories of a category
 * or to show recent categories or main page categories list
 */

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var \Logger $logger
 * @var array<string, mixed> $page
 * @var \Template $template
 * @var array<string, mixed> $user
 */
global $conf, $logger, $page, $template, $user;

// $user['id'] is always numeric (DB primary key, or $conf['guest_id']);
// narrow once here and reuse for every raw-SQL concatenation below.
$user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;

// $user['forbidden_categories'] including with USER_CACHE_CATEGORIES_TABLE
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
  FROM ' . CATEGORIES_TABLE . ' c
    INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . ' ucc
    ON id = cat_id
    AND user_id = ' . $user_id . '
  WHERE count_images > 0
';

if ($page['section'] == 'recent_cats') {
    $query .= '
  AND ' . get_recent_photos_sql('date_last');
} else {
    // $page['category'] is a get_cat_info()-shaped array<string, mixed> when
    // set (see include/section_init.inc.php's own is_array() re-narrowing of
    // this same global); its 'id' is the categories table primary key.
    $page_category = $page['category'] ?? null;
    $page_category_id = is_array($page_category) ? ($page_category['id'] ?? null) : null;
    $page_category_id = is_numeric($page_category_id) ? (int) $page_category_id : 0;
    $query .= '
  AND id_uppercat ' . (! isset($page['category']) ? 'is NULL' : '= ' . $page_category_id);
}

$query .= '
      ' . get_sql_condition_FandF(
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

// $conf['nb_categories_page']/$page['startcat'] are read from loosely-typed
// global bags; narrow once here and reuse for the navigation bar below.
$nb_categories_page = is_numeric($conf['nb_categories_page'] ?? null) ? (int) $conf['nb_categories_page'] : 0;
$startcat = is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0;

$query .= '
  LIMIT ' . $nb_categories_page . ' OFFSET ' . $startcat . '
;';

$filtered_query = trigger_change('loc_begin_index_category_thumbnails_query', $query);
if (is_string($filtered_query)) {
    $query = $filtered_query;
}

$result = pwg_query($query);
$total_row = pwg_db_fetch_row(pwg_query('SELECT FOUND_ROWS()'));
assert($total_row !== null);
[$page['total_categories']] = $total_row;

$categories = [];
$category_ids = [];
$image_ids = [];
$user_representative_updates_for = [];
$dates_of_category = [];

while ($row = pwg_db_fetch_assoc($result)) {
    $cat_id = $row['id'];
    if (! is_string($cat_id)) {
        // 'id' is the categories table primary key (NOT NULL); this should never happen
        continue;
    }

    $row['is_child_date_last'] = @$row['max_date_last'] > @$row['date_last'];

    if (! empty($row['user_representative_picture_id'])) {
        $image_id = $row['user_representative_picture_id'];
    } elseif (! empty($row['representative_picture_id'])) { // if a representative picture is set, it has priority
        $image_id = $row['representative_picture_id'];
    } elseif ($conf['allow_random_representative']) { // searching a random representant among elements in sub-categories
        $image_id = get_random_image_in_category($row);
    } elseif ($row['count_categories'] > 0 and $row['count_images'] > 0) { // at this point, $row['count_images'] should always be >0 (used as condition in SQL)
        // searching a random representant among representant of sub-categories
        $query = '
SELECT representative_picture_id
  FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . USER_CACHE_CATEGORIES_TABLE . '
  ON id = cat_id and user_id = ' . $user_id . '
  WHERE uppercats LIKE \'' . $row['uppercats'] . ',%\'
    AND representative_picture_id IS NOT NULL'
  . get_sql_condition_FandF(
      [
          'visible_categories' => 'id',
      ],
      "\n  AND"
  ) . '
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

    // every branch above sets either a raw numeric DB value (string) or the
    // int|null return of get_random_image_in_category(); normalize to a
    // numeric string once so $image_ids stays string-castable for implode()
    if (isset($image_id)) {
        $image_id = is_numeric($image_id) ? (string) $image_id : null;
    }

    if (isset($image_id)) {
        if ($conf['representative_cache_on_subcats'] and $row['user_representative_picture_id'] != $image_id) {
            $user_representative_updates_for[$cat_id] = $image_id;
        }

        $row['representative_picture_id'] = $image_id;
        $image_ids[] = $image_id;
        $categories[] = $row;
        $category_ids[] = $cat_id;
    } else {
        $logger->info(
            sprintf(
                '[%s] category #%u was listed in SQL but no image_id found, so it was skipped',
                basename(__FILE__),
                $cat_id
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
  FROM ' . IMAGE_CATEGORY_TABLE . '
    INNER JOIN ' . IMAGES_TABLE . ' ON image_id = id
  WHERE category_id IN (' . implode(',', $category_ids) . ')
' . get_sql_condition_FandF(
            [
                'visible_categories' => 'category_id',
                'visible_images' => 'id',
            ],
            'AND'
        ) . '
  GROUP BY category_id
;';
        $dates_of_category = query2array($query, 'category_id');
    }
}

if ($page['section'] == 'recent_cats') {
    usort($categories, global_rank_compare(...));
}

if (count($categories) > 0) {
    $infos_of_image = [];
    $new_image_ids = [];

    $query = '
SELECT *
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', array_filter($image_ids, 'is_string')) . ')
;';
    $result = pwg_query($query);
    while ($row = pwg_db_fetch_assoc($result)) {
        $image_row_id = $row['id'];
        if (! is_string($image_row_id)) {
            // 'id' is the images table primary key (NOT NULL); this should never happen
            continue;
        }

        if ($row['level'] <= $user['level']) {
            $infos_of_image[$image_row_id] = $row;
        } else {
            // problem: we must not display the thumbnail of a photo which has a
            // higher privacy level than user privacy level
            //
            // * what is the represented category?
            // * find a random photo matching user permissions
            // * register it at user_representative_picture_id
            // * set it as the representative_picture_id for the category

            foreach ($categories as &$category) {
                if ($image_row_id == $category['representative_picture_id']) {
                    // searching a random representant among elements in sub-categories
                    $image_id = get_random_image_in_category($category);

                    if (isset($image_id) and ! in_array($image_id, $image_ids)) {
                        $new_image_ids[] = $image_id;
                    }

                    if ($conf['representative_cache_on_level']) {
                        // 'id' is the categories table primary key (NOT NULL,
                        // always a string here, see is_string($cat_id) guard
                        // above); narrow defensively for the array key type
                        $category_id_for_update = $category['id'];
                        if (is_string($category_id_for_update)) {
                            $user_representative_updates_for[$category_id_for_update] = $image_id;
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
SELECT *
  FROM ' . IMAGES_TABLE . '
  WHERE id IN (' . implode(',', $new_image_ids) . ')
;';
        $result = pwg_query($query);
        while ($row = pwg_db_fetch_assoc($result)) {
            $new_image_row_id = $row['id'];
            if (! is_string($new_image_row_id)) {
                // 'id' is the images table primary key (NOT NULL); this should never happen
                continue;
            }

            $infos_of_image[$new_image_row_id] = $row;
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
              'user_id' => $user_id,
              'cat_id' => $cat_id,
              'user_representative_picture_id' => $image_id,
          ];
    }

    mass_updates(
        USER_CACHE_CATEGORIES_TABLE,
        [
            'primary' => ['user_id', 'cat_id'],
            'update' => ['user_representative_picture_id'],
        ],
        $updates
    );
}

if (count($categories) > 0) {
    // Update filtered data
    if (function_exists('update_cats_with_filtered_data')) {
        update_cats_with_filtered_data($categories);
    }

    $template->set_filename('index_category_thumbnails', 'mainpage_categories.tpl');

    trigger_notify('loc_begin_index_category_thumbnails', $categories);

    $tpl_thumbnails_var = [];

    foreach ($categories as $category) {
        if ($category['count_images'] == 0) {
            continue;
        }

        $rendered_category_name = trigger_change(
            'render_category_name',
            $category['name'],
            'subcatify_category_name'
        );
        $category['name'] = is_string($rendered_category_name)
            ? $rendered_category_name
            : (is_string($category['name']) ? $category['name'] : '');

        if ($page['section'] == 'recent_cats') {
            $category_uppercats = $category['uppercats'];
            $category_uppercats = is_string($category_uppercats) ? $category_uppercats : '';
            $name = get_cat_display_name_cache($category_uppercats, null);
        } else {
            $name = $category['name'];
        }

        // 'representative_picture_id' is always a numeric string or int by
        // this point (see the normalization in the loops above); narrow
        // defensively to satisfy the array key type
        $representative_picture_id = $category['representative_picture_id'];
        $representative_picture_id = (is_string($representative_picture_id) or is_int($representative_picture_id))
            ? $representative_picture_id
            : 0;
        $representative_infos = $infos_of_image[$representative_picture_id] ?? null;

        $cat_nb_images = $category['nb_images'];
        $cat_nb_images = is_numeric($cat_nb_images) ? (int) $cat_nb_images : 0;
        $cat_count_images = $category['count_images'];
        $cat_count_images = is_numeric($cat_count_images) ? (int) $cat_count_images : 0;
        $cat_count_categories = $category['count_categories'];
        $cat_count_categories = is_numeric($cat_count_categories) ? (int) $cat_count_categories : 0;

        $tpl_var = array_merge($category, [
            'ID' => $category['id'] /* obsolete */,
            'representative' => $representative_infos,
            'TN_ALT' => strip_tags($category['name']),

            'URL' => make_index_url(
                [
                    'category' => $category,
                ]
            ),
            'CAPTION_NB_IMAGES' => get_display_images_count(
                $cat_nb_images,
                $cat_count_images,
                $cat_count_categories,
                true,
                '<br>'
            ),
            'DESCRIPTION' => trigger_change(
                'render_category_literal_description',
                trigger_change(
                    'render_category_description',
                    @$category['comment'],
                    'subcatify_category_description'
                )
            ),
            'NAME' => $name,
        ]);
        if ($conf['index_new_icon']) {
            $category_max_date_last = $category['max_date_last'];
            $category_max_date_last = is_string($category_max_date_last) ? $category_max_date_last : '';
            $category_is_child_date_last = $category['is_child_date_last'];
            $category_is_child_date_last = is_bool($category_is_child_date_last) ? $category_is_child_date_last : false;
            $tpl_var['icon_ts'] = get_icon($category_max_date_last, $category_is_child_date_last);
        }

        if ($conf['display_fromto']) {
            // 'id' is the categories table primary key (NOT NULL, always a
            // string here); narrow defensively for the array key type
            $category_id_key = $category['id'];
            $category_id_key = (is_string($category_id_key) or is_int($category_id_key)) ? $category_id_key : 0;
            if (isset($dates_of_category[$category_id_key])) {
                $from = $dates_of_category[$category_id_key]['from'];
                $to = $dates_of_category[$category_id_key]['to'];
                $to = is_string($to) ? $to : '';

                if (! empty($from)) {
                    $tpl_var['INFO_DATES'] = format_fromto($from, $to);
                }
            }
        }

        $tpl_thumbnails_var[] = $tpl_var;
    }

    // pagination
    $tpl_thumbnails_var_selection = $tpl_thumbnails_var;

    $derivative_params = trigger_change('get_index_album_derivative_params', ImageStdParams::get_by_type(IMG_THUMB));
    $tpl_thumbnails_var_selection = trigger_change('loc_end_index_category_thumbnails', $tpl_thumbnails_var_selection);
    $template->assign([
        'maxRequests' => $conf['max_requests'],
        'category_thumbnails' => $tpl_thumbnails_var_selection,
        'derivative_params' => $derivative_params,
    ]);

    $template->assign_var_from_handle('CATEGORIES', 'index_category_thumbnails');

    // navigation bar
    $page['cats_navigation_bar'] = [];
    $total_categories = is_numeric($page['total_categories'] ?? null) ? (int) $page['total_categories'] : 0;
    if ($total_categories > $nb_categories_page) {
        $page['cats_navigation_bar'] = create_navigation_bar(
            duplicate_index_url([], ['startcat']),
            $total_categories,
            $startcat,
            $nb_categories_page,
            true,
            'startcat'
        );
    }

    $template->assign('cats_navbar', $page['cats_navigation_bar']);
}

pwg_debug('end include/category_cats.inc.php');
