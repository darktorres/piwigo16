<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * This included page checks section related parameter and provides
 * following information:
 *
 * - $page['title']
 *
 * - $page['items']: ordered list of items to display
 */

// "index.php?/category/12-foo/start-24" or
// "index.php/category/12-foo/start-24"
// must return :
//
// array(
//   'section'  => 'categories',
//   'category' => array('id'=>12, ...),
//   'start'    => 24
//   );

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_calendar;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_search;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_tag;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

$page['items'] = [];
$page['start'] = $page['startcat'] = 0;

// some ISPs set PATH_INFO to empty string or to SCRIPT_FILENAME while in the
// default apache implementation it is not set
if ($conf['question_mark_in_urls'] == false and
    isset($_SERVER['PATH_INFO']) and
    ! empty($_SERVER['PATH_INFO'])
) {
    $rewritten = $_SERVER['PATH_INFO'];
    $rewritten = str_replace('//', '/', $rewritten);
    $path_count = count(explode('/', $rewritten));
    $page['root_path'] = './' . str_repeat('../', $path_count - 1);
} else {
    $rewritten = '';

    foreach (array_keys($_GET) as $keynum => $key) {
        $rewritten = $key;
        break;
    }

    // the $_GET keys are not protected in inc/common.php, only the values
    $rewritten = functions_mysqli::pwg_db_real_escape_string($rewritten);
    $page['root_path'] = './';
}

if (strncmp($page['root_path'], './', 2) == 0) {
    $page['root_path'] = substr($page['root_path'], 2);
}

// deleting first "/" if displayed
$tokens = explode('/', ltrim($rewritten, '/'));
// $tokens = array(
//   0 => category,
//   1 => 12-foo,
//   2 => start-24
//   );

$next_token = 0;

// +-----------------------------------------------------------------------+
// |                             picture page                              |
// +-----------------------------------------------------------------------+
// the first token must be the identifier for the picture
if (functions::script_basename() == 'picture') {
    $token = $tokens[$next_token];
    $next_token++;

    if (is_numeric($token)) {
        $page['image_id'] = $token;

        if ($page['image_id'] == 0) {
            functions_html::bad_request('invalid picture identifier');
        }
    } else {
        preg_match('/^(\d+-)?(.*)?$/', $token, $matches);

        if (isset($matches[1]) and
            is_numeric($matches[1] = rtrim($matches[1], '-'))
        ) {
            $page['image_id'] = $matches[1];

            if (! empty($matches[2])) {
                $page['image_file'] = $matches[2];
            }
        } else {
            $page['image_id'] = 0; // more work in picture.php

            if (! empty($matches[2])) {
                $page['image_file'] = $matches[2];
            } else {
                functions_html::bad_request('picture identifier is missing');
            }
        }
    }
}

$page = array_merge($page, functions_url::parse_section_url($tokens, $next_token));

if (! isset($page['section'])) {
    $page['section'] = 'categories';

    switch (functions::script_basename()) {
        case 'picture':
            break;

        case 'index':
            // No section defined, go to random url
            if (! empty($conf['random_index_redirect']) and
                empty($tokens[$next_token])
            ) {
                $random_index_redirect = [];

                foreach ($conf['random_index_redirect'] as $random_url => $random_url_condition) {
                    if (empty($random_url_condition) or
                        eval($random_url_condition)
                    ) {
                        $random_index_redirect[] = $random_url;
                    }
                }

                if (! empty($random_index_redirect)) {
                    functions::redirect($random_index_redirect[mt_rand(0, count($random_index_redirect) - 1)]);
                }
            }

            $page['is_homepage'] = true;
            break;

        default:
            trigger_error(
                'script_basename "' . functions::script_basename() . '" unknown',
                E_USER_WARNING
            );
    }
}

$page = array_merge($page, functions_url::parse_well_known_params_url($tokens, $next_token));

//access a picture only by id, file or id-file without given section
if (functions::script_basename() == 'picture' and
    $page['section'] == 'categories' and
    ! isset($page['category']) and
    ! isset($page['chronology_field'])
) {
    $page['flat'] = true;
}

// $page['nb_image_page'] is the number of picture to display on this page
// By default, it is the same as the $user['nb_image_page']
$page['nb_image_page'] = $user['nb_image_page'];

// if flat mode is active, we must consider the image set as a standard set
// and not as a category set because we can't use the #image_category.rank :
// displayed images are not directly linked to the displayed category
if ($page['section'] == 'categories' and
    ! isset($page['flat'])
) {
    $conf['order_by'] = $conf['order_by_inside_category'];
}

if (functions_session::pwg_get_session_var('image_order', 0) > 0) {
    $image_order_id = functions_session::pwg_get_session_var('image_order');

    $orders = functions_category::get_category_preferred_image_orders();

    // the current session stored image_order might be not compatible with
    // current image set, for example if the current image_order is the rank
    // and that we are displaying images related to a tag.
    //
    // In case of incompatibility, the session stored image_order is removed.
    if ($orders[$image_order_id][2]) {
        $conf['order_by'] = str_replace(
            'ORDER BY ',
            'ORDER BY ' . $orders[$image_order_id][1] . ',',
            $conf['order_by']
        );
        $page['super_order_by'] = true;
    } else {
        functions_session::pwg_unset_session_var('image_order');
        $page['super_order_by'] = false;
    }
}

$forbidden = functions_user::get_sql_condition_FandF(
    [
        'forbidden_categories' => 'category_id',
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
    ],
    'AND'
);

// +-----------------------------------------------------------------------+
// |                              category                                 |
// +-----------------------------------------------------------------------+
if ($page['section'] == 'categories') {
    if (isset($page['combined_categories'])) {
        $page['title'] = functions_html::get_combined_categories_content_title();
    } elseif (isset($page['category'])) {
        $page = array_merge(
            $page,
            [
                'comment' => functions_plugins::trigger_change(
                    'render_category_description',
                    $page['category']['comment'],
                    'main_page_category_description'
                ),
                'title' => functions_html::get_cat_display_name($page['category']['upper_names'], ''),
            ]
        );
    } else {
        $page['title'] = ''; // will be set later
    }

    // GET IMAGES LIST
    if (isset($page['combined_categories'])) {
        $cat_ids = [$page['category']['id']];

        foreach ($page['combined_categories'] as $category) {
            $cat_ids[] = $category['id'];
        }

        $page['items'] = functions_category::get_image_ids_for_categories($cat_ids);
    } elseif ($page['startcat'] == 0 and
             (! isset($page['chronology_field'])) and // otherwise the calendar will requery all subitems
             (isset($page['category']) or isset($page['flat']))
    ) {
        if (! empty($page['category']['image_order']) and
            ! isset($page['super_order_by'])
        ) {
            $conf['order_by'] = ' ORDER BY ' . $page['category']['image_order'];
        }

        // flat categories mode
        if (isset($page['flat'])) {
            // get all allowed sub-categories
            if (isset($page['category'])) {
                $sql_condition = functions_user::get_sql_condition_FandF(
                    [
                        'forbidden_categories' => 'id',
                        'visible_categories' => 'id',
                    ],
                    'AND'
                );

                $query = <<<SQL
                    SELECT id
                    FROM categories
                    WHERE uppercats LIKE '{$page['category']['uppercats']},%'
                        {$sql_condition};
                    SQL;

                $subcat_ids = functions_mysqli::query2array($query, null, 'id');
                $subcat_ids[] = $page['category']['id'];
                $where_sql = 'category_id IN (' . implode(', ', $subcat_ids) . ')';
                // remove categories from forbidden because just checked above
                $forbidden = functions_user::get_sql_condition_FandF(
                    [
                        'visible_images' => 'id',
                    ],
                    'AND'
                );
            } else {
                $cache_key = $persistent_cache->make_key('all_iids' . $user['id'] . $user['cache_update_time'] . $conf['order_by']);
                unset($page['is_homepage']);
                $where_sql = '1 = 1';
            }
        }
        // normal mode
        else {
            $where_sql = "category_id = {$page['category']['id']}";
        }

        if (! isset($cache_key) ||
            ! $persistent_cache->get($cache_key, $page['items'])
        ) {
            // main query
            $query = <<<SQL
                SELECT DISTINCT image_id
                FROM image_category
                INNER JOIN images ON id = image_id
                WHERE {$where_sql} {$forbidden}
                {$conf['order_by']};
                SQL;

            $page['items'] = functions_mysqli::query2array($query, null, 'image_id');

            if (isset($cache_key)) {
                $persistent_cache->set($cache_key, $page['items']);
            }
        }
    }
}
// special sections
else {
    // +-----------------------------------------------------------------------+
    // |                            tags section                               |
    // +-----------------------------------------------------------------------+
    if ($page['section'] == 'tags') {
        $page['tag_ids'] = [];

        foreach ($page['tags'] as $tag) {
            $page['tag_ids'][] = $tag['id'];
        }

        $items = functions_tag::get_image_ids_for_tags($page['tag_ids']);

        if (count($items) == 0) {
            $logger->info(
                'attempt to see the name of the tag #' . implode(', #', $page['tag_ids'])
        . ' from the address : ' . $_SERVER['REMOTE_ADDR']
            );
            functions_html::access_denied();
        }

        $page = array_merge(
            $page,
            [
                'title' => functions_html::get_tags_content_title(),
                'items' => $items,
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                           search section                              |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'search') {
        $search_result = functions_search::get_search_results($page['search'], ($page['super_order_by'] ?? null));

        //save the details of the query search
        if (isset($search_result['qs'])) {
            $page['qsearch_details'] = $search_result['qs'];
        } elseif (isset($search_result['search_details'])) {
            $page['search_details'] = $search_result['search_details'];
        }

        $page = array_merge(
            $page,
            [
                'items' => $search_result['items'],
                'title' => '<a href="' . functions_url::duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . functions::l10n('Search results') . '</a>',
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                           favorite section                            |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'favorites') {
        functions_user::check_user_favorites();

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . functions_url::duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . functions::l10n('Favorites') . '</a>',
            ]
        );

        if (! empty($_GET['action']) &&
           ($_GET['action'] == 'remove_all_from_favorites')
        ) {
            $query = <<<SQL
                DELETE FROM favorites
                WHERE user_id = {$user['id']};
                SQL;
            functions_mysqli::pwg_query($query);
            functions::redirect(functions_url::make_index_url([
                'section' => 'favorites',
            ]));
        } else {
            $sql_condition = functions_user::get_sql_condition_FandF(
                [
                    'visible_images' => 'id',
                ],
                'AND'
            );

            $query = <<<SQL
                SELECT image_id
                FROM favorites
                INNER JOIN images ON image_id = id
                WHERE user_id = '{$user['id']}'
                    {$sql_condition}
                {$conf['order_by']};
                SQL;

            $page = array_merge(
                $page,
                [
                    'items' => functions_mysqli::query2array($query, null, 'image_id'),
                ]
            );

            if (count($page['items']) > 0) {
                $template->assign(
                    'favorite',
                    [
                        'U_FAVORITE' => functions_url::add_url_params(
                            functions_url::make_index_url([
                                'section' => 'favorites',
                            ]),
                            [
                                'action' => 'remove_all_from_favorites',
                            ]
                        ),
                    ]
                );
            }
        }
    }
    // +-----------------------------------------------------------------------+
    // |                       recent pictures section                         |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'recent_pics') {
        if (! isset($page['super_order_by'])) {
            $conf['order_by'] = str_replace(
                'ORDER BY ',
                'ORDER BY date_available DESC, ',
                $conf['order_by']
            );
        }

        $recent_photos_sql = functions_user::get_recent_photos_sql('date_available');
        $query = <<<SQL
            SELECT DISTINCT id
            FROM images
            INNER JOIN image_category AS ic ON id = ic.image_id
            WHERE {$recent_photos_sql}
                {$forbidden}
            {$conf['order_by']};
            SQL;

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . functions_url::duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . functions::l10n('Recent photos') . '</a>',
                'items' => functions_mysqli::query2array($query, null, 'id'),
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                 recently updated categories section                   |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'recent_cats') {
        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . functions_url::duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . functions::l10n('Recent albums') . '</a>',
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                        most visited section                           |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'most_visited') {
        $page['super_order_by'] = true;
        $conf['order_by'] = ' ORDER BY hit DESC, id DESC';

        $query = <<<SQL
            SELECT DISTINCT id
            FROM images
            INNER JOIN image_category AS ic ON id = ic.image_id
            WHERE hit > 0 {$forbidden}
            {$conf['order_by']}
            LIMIT {$conf['top_number']};
            SQL;

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . functions_url::duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . $conf['top_number'] . ' ' . functions::l10n('Most visited') . '</a>',
                'items' => functions_mysqli::query2array($query, null, 'id'),
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                          best rated section                           |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'best_rated') {
        $page['super_order_by'] = true;
        $conf['order_by'] = ' ORDER BY rating_score DESC, id DESC';

        $query = <<<SQL
            SELECT DISTINCT id
            FROM images
            INNER JOIN image_category AS ic ON id = ic.image_id
            WHERE rating_score IS NOT NULL
                {$forbidden}
            {$conf['order_by']}
            LIMIT {$conf['top_number']};
            SQL;

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . functions_url::duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . $conf['top_number'] . ' ' . functions::l10n('Best rated') . '</a>',
                'items' => functions_mysqli::query2array($query, null, 'id'),
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                             list section                              |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'list') {
        $image_ids = implode(', ', $page['list']);
        $query = <<<SQL
            SELECT DISTINCT id
            FROM images
            INNER JOIN image_category AS ic ON id = ic.image_id
            WHERE image_id IN ({$image_ids})
                {$forbidden}
            {$conf['order_by']};
            SQL;

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . functions_url::duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . functions::l10n('Random photos') . '</a>',
                'items' => functions_mysqli::query2array($query, null, 'id'),
            ]
        );
    }
}

// +-----------------------------------------------------------------------+
// |                             chronology                                |
// +-----------------------------------------------------------------------+
if (isset($page['chronology_field'])) {
    unset($page['is_homepage']);
    functions_calendar::initialize_calendar();
}

// title update
if (isset($page['title'])) {
    $page['section_title'] = '<a href="' . functions_url::get_gallery_home_url() . '">' . functions::l10n('Home') . '</a>';

    if (! empty($page['title'])) {
        $page['section_title'] .= $conf['level_separator'] . $page['title'];
    } else {
        $page['title'] = $page['section_title'];
    }
}

// add meta robots noindex, nofollow to avoid unnecessary robot crawls
$page['meta_robots'] = [];

if (isset($page['chronology_field']) or
   (isset($page['flat']) and isset($page['category'])) or
    $page['section'] == 'list' or
    $page['section'] == 'recent_pics'
) {
    $page['meta_robots'] = [
        'noindex' => 1,
        'nofollow' => 1,
    ];
} elseif ($page['section'] == 'tags') {
    if (count($page['tag_ids']) > 1) {
        $page['meta_robots'] = [
            'noindex' => 1,
            'nofollow' => 1,
        ];
    }
} elseif ($page['section'] == 'recent_cats') {
    $page['meta_robots']['noindex'] = 1;
} elseif ($page['section'] == 'search') {
    $page['meta_robots']['nofollow'] = 1;
}

if ($filter['enabled']) {
    $page['meta_robots']['noindex'] = 1;
}

// see if we need a redirect because of a permalink
if ($page['section'] == 'categories' and
    isset($page['category']) and
    ! isset($page['combined_categories'])
) {
    $need_redirect = false;

    if (empty($page['category']['permalink'])) {
        if ($conf['category_url_style'] == 'id-name' and
            $page['hit_by']['cat_url_name'] !== functions::str2url($page['category']['name'])
        ) {
            $need_redirect = true;
        }
    } else {
        if ($page['category']['permalink'] !== $page['hit_by']['cat_permalink']) {
            $need_redirect = true;
        }
    }

    if ($need_redirect) {
        functions_category::check_restrictions($page['category']['id']);
        $redirect_url = functions::script_basename() == 'picture' ? functions_url::duplicate_picture_url() : functions_url::duplicate_index_url();

        if (! headers_sent()) { // this is a permanent redirection
            functions_html::set_status_header(301);
            functions::redirect_http($redirect_url);
        }

        functions::redirect($redirect_url);
    }

    unset($need_redirect, $page['hit_by']);
}

array_push($page['body_classes'], 'section-' . $page['section']);
$page['body_data']['section'] = $page['section'];

if ($page['section'] == 'categories' &&
    isset($page['category'])
) {
    array_push($page['body_classes'], 'category-' . $page['category']['id']);
    $page['body_data']['category_id'] = $page['category']['id'];

    if (isset($page['combined_categories'])) {
        $page['body_data']['combined_category_ids'] = [];

        foreach ($page['combined_categories'] as $combined_categories) {
            array_push($page['body_classes'], 'category-' . $combined_categories['id']);
            array_push($page['body_data']['combined_category_ids'], $combined_categories['id']);
        }
    }
} elseif (isset($page['tags'])) {
    $page['body_data']['tag_ids'] = [];

    foreach ($page['tags'] as $tag) {
        array_push($page['body_classes'], 'tag-' . $tag['id']);
        array_push($page['body_data']['tag_ids'], $tag['id']);
    }
} elseif (isset($page['search'])) {
    array_push($page['body_classes'], 'search-' . $page['search']);
    $page['body_data']['search_id'] = $page['search'];
}

if (isset($page['image_id'])) {
    array_push($page['body_classes'], 'image-' . $page['image_id']);
    $page['body_data']['image_id'] = $page['image_id'];
}

functions_plugins::trigger_notify('loc_end_section_init');
