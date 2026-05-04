<?php

declare(strict_types=1);

global $persistent_cache, $logger;

use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This file is included by the main page to show subcategories of a category
 * or to show recent categories or main page categories list
 *
 */

$template = TemplateRegistry::current();
$page = &$GLOBALS['page'];
if (!is_array($page)) {
    $page = [];
}
$currentUser = CurrentUser::get();
$user = $currentUser->rawAttributes;

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
  FROM '.CATEGORIES_TABLE.' c
    INNER JOIN '.USER_CACHE_CATEGORIES_TABLE.' ucc
    ON id = cat_id
    AND user_id = '.$currentUser->id.'
  WHERE count_images > 0
';

if ('recent_cats' == $page['section']) {
    $query .= '
  AND '.get_recent_photos_sql('date_last');
} else {
    $query .= '
  AND id_uppercat '.(!isset($page['category']) || !is_array($page['category']) ? 'is NULL' : '= '.(is_scalar($page['category']['id'] ?? null) ? (string) $page['category']['id'] : ''));
}

$query .= '
      '.get_sql_condition_FandF(
    ['visible_categories' => 'id'],
    'AND'
);

// special string to let plugins modify this query at this exact position
$query .= '
-- after conditions
';

if ('recent_cats' != $page['section']) {
    $query .= '
  ORDER BY `rank`';
}

$nb_cats_page = \Piwigo\Config\Config::nbCategoriesPage();
$query .= '
  LIMIT '.$nb_cats_page.' OFFSET '.(is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0).'
;';

$query = trigger_change('loc_begin_index_category_thumbnails_query', $query);

$conn = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class);
$catCatsRows = $conn->executeQuery($query)->fetchAllAssociative();
$page['total_categories'] = $conn->executeQuery('SELECT FOUND_ROWS()')->fetchOne();

$categories = [];
$category_ids = [];
$image_ids = [];
$user_representative_updates_for = [];

foreach ($catCatsRows as $row) {
    $row['is_child_date_last'] = ($row['max_date_last'] ?? null) > ($row['date_last'] ?? null);

    if (!empty($row['user_representative_picture_id'])) {
        $image_id = $row['user_representative_picture_id'];
    } elseif (!empty($row['representative_picture_id'])) { // if a representative picture is set, it has priority
        $image_id = $row['representative_picture_id'];
    } elseif (\Piwigo\Config\Config::allowRandomRepresentative()) { // searching a random representant among elements in sub-categories
        $image_id = get_random_image_in_category($row);
    } elseif ($row['count_categories'] > 0 and $row['count_images'] > 0) { // at this point, $row['count_images'] should always be >0 (used as condition in SQL)
        // searching a random representant among representant of sub-categories
        $query = '
SELECT representative_picture_id
  FROM '.CATEGORIES_TABLE.' INNER JOIN '.USER_CACHE_CATEGORIES_TABLE.'
  ON id = cat_id and user_id = '.$currentUser->id.'
  WHERE uppercats LIKE \''.(is_scalar($row['uppercats']) ? (string)$row['uppercats'] : '').',%\'
    AND representative_picture_id IS NOT NULL'
  .get_sql_condition_FandF(
      [
        'visible_categories' => 'id',
      ],
      "\n  AND"
  ).'
  ORDER BY '.DB_RANDOM_FUNCTION.'()
  LIMIT 1
;';
        $subval = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
            ->executeQuery($query)->fetchOne();
        if ($subval !== false) {
            $image_id = is_numeric($subval) ? (int) $subval : null;
        }
    }


    if (isset($image_id)) {
        if (\Piwigo\Config\Config::representativeCacheOnSubcats() and $row['user_representative_picture_id'] != $image_id) {
            $user_representative_updates_for[is_scalar($row['id'] ?? null) ? (string)$row['id'] : ''] = $image_id;
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
                is_numeric($row['id']) ? (int) $row['id'] : 0
            )
        );
    }
    unset($image_id);
}

if (\Piwigo\Config\Config::displayFromto()) {
    if (count($category_ids) > 0) {
        $query = '
SELECT
    category_id,
    MIN(date_creation) AS `from`,
    MAX(date_creation) AS `to`
  FROM '.IMAGE_CATEGORY_TABLE.'
    INNER JOIN '.IMAGES_TABLE.' ON image_id = id
  WHERE category_id IN ('.implode(',', $category_ids).')
'.get_sql_condition_FandF(
            [
              'visible_categories' => 'category_id',
              'visible_images' => 'id',
            ],
            'AND'
        ).'
  GROUP BY category_id
;';
        $dates_of_category = \Piwigo\Db\QueryHelper::fetch($query, 'category_id');
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
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $image_ids).')
;';
    foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery($query)->fetchAllAssociative() as $row) {
        if ($row['level'] <= $user['level']) {
            $infos_of_image[is_scalar($row['id'] ?? null) ? (string)$row['id'] : ''] = $row;
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
                        $user_representative_updates_for[is_scalar($category['id'] ?? null) ? (string)$category['id'] : ''] = $image_id;
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
  FROM '.IMAGES_TABLE.'
  WHERE id IN ('.implode(',', $new_image_ids).')
;';
        foreach (\Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
            ->executeQuery($query)->fetchAllAssociative() as $row) {
            $infos_of_image[is_scalar($row['id'] ?? null) ? (string)$row['id'] : ''] = $row;
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

    mass_updates(
        USER_CACHE_CATEGORIES_TABLE,
        [
        'primary' => ['user_id', 'cat_id'],
        'update'  => ['user_representative_picture_id'],
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
        if (0 == $category['count_images']) {
            continue;
        }

        $category['name'] = trigger_change(
            'render_category_name',
            is_scalar($category['name'] ?? null) ? (string)$category['name'] : '',
            'subcatify_category_name'
        );

        if ($page['section'] == 'recent_cats') {
            $name = get_cat_display_name_cache(is_scalar($category['uppercats'] ?? null) ? (string)$category['uppercats'] : '', null);
        } else {
            $name = $category['name'];
        }

        $representative_infos = is_array($infos_of_image[$category['representative_picture_id'] ?? null] ?? null) ? $infos_of_image[$category['representative_picture_id']] : [];

        $tpl_var = array_merge($category, [
              'ID'    => $category['id'] /*obsolete*/,
              'representative'   => $representative_infos,
              'TN_ALT'   => strip_tags($category['name']),

              'URL'   => make_index_url(
                  [
                  'category' => $category,
                  ]
              ),
              'CAPTION_NB_IMAGES' => get_display_images_count(
                  is_numeric($category['nb_images'] ?? null) ? (int)$category['nb_images'] : 0,
                  is_numeric($category['count_images']) ? (int)$category['count_images'] : 0,
                  is_numeric($category['count_categories'] ?? null) ? (int)$category['count_categories'] : 0,
                  true,
                  '<br>'
              ),
              'DESCRIPTION' =>
                trigger_change(
                    'render_category_literal_description',
                    trigger_change(
                        'render_category_description',
                        $category['comment'] ?? null,
                        'subcatify_category_description'
                    )
                ),
              'NAME'  => $name,
            ]);
        if (\Piwigo\Config\Config::indexNewIcon()) {
            $tpl_var['icon_ts'] = get_icon(
                is_scalar($category['max_date_last'] ?? null) ? (string)$category['max_date_last'] : null,
                (bool)($category['is_child_date_last'] ?? false)
            );
        }

        if (\Piwigo\Config\Config::displayFromto()) {
            if (isset($dates_of_category[ $category['id'] ])) {
                $from = $dates_of_category[ $category['id'] ]['from'];
                $to   = $dates_of_category[ $category['id'] ]['to'];

                if (!empty($from)) {
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
      'maxRequests' => \Piwigo\Config\Config::maxRequests(),
      'category_thumbnails' => $tpl_thumbnails_var_selection,
      'derivative_params' => $derivative_params,
      ]);

    $template->assign_var_from_handle('CATEGORIES', 'index_category_thumbnails');

    // navigation bar
    $page['cats_navigation_bar'] = [];
    if ($page['total_categories'] > \Piwigo\Config\Config::nbCategoriesPage()) {
        $page['cats_navigation_bar'] = create_navigation_bar(
            duplicate_index_url([], ['startcat']),
            is_numeric($page['total_categories']) ? (int)$page['total_categories'] : 0,
            is_numeric($page['startcat'] ?? null) ? (int) $page['startcat'] : 0,
            \Piwigo\Config\Config::nbCategoriesPage(),
            true,
            'startcat'
        );
    }

    $template->assign('cats_navbar', $page['cats_navigation_bar']);
}

pwg_debug('end include/category_cats.inc.php');
