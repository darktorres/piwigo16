<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Cache\PersistentCache;
use Piwigo\Core\Logger;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * This included page checks section related parameter and provides
 * following informations:
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

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $filter
 * @var array<string, mixed> $page
 * @var array<string, mixed> $user
 * @var Logger $logger
 * @var Template $template
 */
global $conf, $filter, $logger, $page, $persistent_cache, $template, $user;
if (! $persistent_cache instanceof PersistentCache) {
    fatal_error('persistent cache not initialized');
}

$page['items'] = [];
$page['start'] = $page['startcat'] = 0;

// some ISPs set PATH_INFO to empty string or to SCRIPT_FILENAME while in the
// default apache implementation it is not set
if ($conf['question_mark_in_urls'] == false and
     isset($_SERVER['PATH_INFO']) and ! empty($_SERVER['PATH_INFO'])) {
    $rewritten = $_SERVER['PATH_INFO'];
    // $_SERVER values are typed mixed by PHPStan (PATH_INFO is a string in
    // practice, but the superglobal's declared value type doesn't say so)
    $rewritten = is_string($rewritten) ? $rewritten : '';
    $rewritten = str_replace('//', '/', $rewritten);
    $path_count = count(explode('/', $rewritten));
    $page['root_path'] = PHPWG_ROOT_PATH . str_repeat('../', $path_count - 1);
} else {
    $rewritten = '';
    foreach (array_keys($_GET) as $keynum => $key) {
        $rewritten = $key;
        break;
    }

    // the $_GET keys are not protected in include/common.inc.php, only the values
    $rewritten = pwg_db_real_escape_string($rewritten);
    $page['root_path'] = PHPWG_ROOT_PATH;
}

if (str_starts_with($page['root_path'], './')) {
    $page['root_path'] = substr($page['root_path'], 2);
}

// $_SERVER['PATH_INFO']/$_GET keys are usually strings, but PHP allows
// array-valued query-string keys (e.g. ?foo[]=1) — not a valid section
// URL, degrade to an empty path rather than crash
if (! is_string($rewritten)) {
    $rewritten = '';
}
$page['section_url'] = $rewritten;

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
if (script_basename() == 'picture') {
    $token = $tokens[$next_token];
    $next_token++;
    if (is_numeric($token)) {
        $page['image_id'] = $token;
        if ($page['image_id'] == 0) {
            bad_request('invalid picture identifier');
        }
    } else {
        preg_match('/^(\d+-)?(.*)?$/', $token, $matches);
        if (isset($matches[1]) and is_numeric($matches[1] = rtrim($matches[1], '-'))) {
            $page['image_id'] = $matches[1];
            if (! empty($matches[2])) {
                $page['image_file'] = $matches[2];
            }
        } else {
            $page['image_id'] = 0; // more work in picture.php
            if (! empty($matches[2])) {
                $page['image_file'] = $matches[2];
            } else {
                bad_request('picture identifier is missing');
            }
        }
    }
}

$page = array_merge($page, parse_section_url($tokens, $next_token));

if (! isset($page['section'])) {
    $page['section'] = 'categories';

    switch (script_basename()) {
        case 'picture':
            break;
        case 'index':

            // No section defined, go to random url
            if (! empty($conf['random_index_redirect']) and empty($tokens[$next_token])) {
                $random_index_redirect = [];
                // $conf['random_index_redirect'] is a map of URL => PHP
                // condition string (see include/config_default.inc.php); a
                // malformed local override shouldn't crash this page.
                $redirect_candidates = is_array($conf['random_index_redirect']) ? $conf['random_index_redirect'] : [];
                foreach ($redirect_candidates as $random_url => $random_url_condition) {
                    if (! is_string($random_url)) {
                        continue;
                    }
                    if (empty($random_url_condition) or (is_string($random_url_condition) and eval($random_url_condition))) {
                        $random_index_redirect[] = $random_url;
                    }
                }
                if (! empty($random_index_redirect)) {
                    redirect($random_index_redirect[random_int(0, count($random_index_redirect) - 1)]);
                }
            }
            $page['is_homepage'] = true;
            break;

        default:
            trigger_error(
                'script_basename "' . script_basename() . '" unknown',
                E_USER_WARNING
            );
    }
}

$page = array_merge($page, parse_well_known_params_url($tokens, $next_token));

// access a picture only by id, file or id-file without given section
if (script_basename() == 'picture' and $page['section'] == 'categories' and
      ! isset($page['category']) and ! isset($page['chronology_field'])) {
    $page['flat'] = true;
}

// $page['nb_image_page'] is the number of picture to display on this page
// By default, it is the same as the $user['nb_image_page']
$page['nb_image_page'] = $user['nb_image_page'];

// if flat mode is active, we must consider the image set as a standard set
// and not as a category set because we can't use the #image_category.rank :
// displayed images are not directly linked to the displayed category
if ($page['section'] == 'categories' and ! isset($page['flat'])) {
    $conf['order_by'] = $conf['order_by_inside_category'];
}

if (pwg_get_session_var('image_order', 0) > 0) {
    $image_order_id = pwg_get_session_var('image_order');
    // pwg_get_session_var() is declared to return mixed; index.php is the
    // only writer of the 'image_order' session var and always stores an
    // int, but that isn't visible through the session accessor's signature
    $image_order_id = is_numeric($image_order_id) ? (int) $image_order_id : -1;

    $orders = get_category_preferred_image_orders();

    // the current session stored image_order might be not compatible with
    // current image set, for example if the current image_order is the rank
    // and that we are displaying images related to a tag.
    //
    // In case of incompatibility, the session stored image_order is removed.
    if ($orders[$image_order_id][2]) {
        $conf['order_by'] = str_replace(
            'ORDER BY ',
            'ORDER BY ' . $orders[$image_order_id][1] . ',',
            is_string($conf['order_by']) ? $conf['order_by'] : ''
        );
        $page['super_order_by'] = true;
    } else {
        pwg_unset_session_var('image_order');
        $page['super_order_by'] = false;
    }
}

$forbidden = get_sql_condition_FandF(
    [
        'forbidden_categories' => 'category_id',
        'visible_categories' => 'category_id',
        'visible_images' => 'id',
    ],
    'AND'
);

// parse_section_url()'s own return type is the generic array<string, mixed>
// (functions_url.inc.php), so $page['category'] loses the more precise
// array<string, mixed> shape that get_cat_info() actually returns;
// re-narrow it once here, unconditionally, so it stays defined (and
// PHPStan-visible) for every later use in this file, not just inside the
// 'categories' section branch below.
$page_category = null;
if (isset($page['category']) and is_array($page['category'])) {
    // same cross-file $page[...] false-narrowing as the tag_ids block below
    // -- PHPStan infers list<string> for $page['category'] from an
    // unrelated write elsewhere in the codebase, but get_cat_info() (this
    // key's real, original source) returns array<string, mixed>, which is
    // what every downstream $page_category['...'] read in this file relies
    // on (see the file-level comment above).
    /** @var array<string, mixed> $page_category */
    // @phpstan-ignore varTag.type
    $page_category = $page['category'];
}

// +-----------------------------------------------------------------------+
// |                              category                                 |
// +-----------------------------------------------------------------------+
if ($page['section'] == 'categories') {
    if (isset($page['combined_categories'])) {
        $page['title'] = get_combined_categories_content_title();
    } elseif ($page_category !== null) {
        $upper_names_raw = $page_category['upper_names'] ?? null;
        if (! is_array($upper_names_raw)) {
            $upper_names_raw = [];
        }
        /** @var array<int, array<string, mixed>> $upper_names */
        $upper_names = array_filter($upper_names_raw, is_array(...));

        $page = array_merge(
            $page,
            [
                'comment' => trigger_change(
                    'render_category_description',
                    $page_category['comment'],
                    'main_page_category_description'
                ),
                'title' => get_cat_display_name($upper_names, ''),
            ]
        );
    } else {
        $page['title'] = ''; // will be set later
    }

    // GET IMAGES LIST
    if (isset($page['combined_categories'])) {
        // combined_categories is only ever set (by parse_section_url() in
        // functions_url.inc.php) after category has already been set
        assert($page_category !== null);
        $cat_ids = [];
        $first_cat_id = $page_category['id'] ?? null;
        if (is_numeric($first_cat_id)) {
            $cat_ids[] = (int) $first_cat_id;
        }
        $combined_categories_raw = is_array($page['combined_categories']) ? $page['combined_categories'] : [];
        foreach ($combined_categories_raw as $category) {
            $combined_id = is_array($category) ? ($category['id'] ?? null) : null;
            if (is_numeric($combined_id)) {
                $cat_ids[] = (int) $combined_id;
            }
        }

        $page['items'] = get_image_ids_for_categories($cat_ids);
    } elseif (
        // startcat is always set at the top of this file
        isset($page['startcat']) and
        $page['startcat'] == 0 and
        (! isset($page['chronology_field'])) and // otherwise the calendar will requery all subitems
        (
            ($page_category !== null) or
            (isset($page['flat']))
        )
    ) {
        if ($page_category !== null and ! empty($page_category['image_order']) and ! isset($page['super_order_by'])) {
            $image_order = $page_category['image_order'];
            $conf['order_by'] = ' ORDER BY ' . (is_string($image_order) ? $image_order : '');
        }

        // flat categories mode
        if (isset($page['flat'])) {
            // get all allowed sub-categories
            if ($page_category !== null) {
                $uppercats = $page_category['uppercats'] ?? null;
                $uppercats = is_string($uppercats) ? $uppercats : '';
                $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE
    uppercats LIKE \'' . $uppercats . ',%\' '
    . get_sql_condition_FandF(
        [
            'forbidden_categories' => 'id',
            'visible_categories' => 'id',
        ],
        "\n  AND"
    );

                $subcat_ids_raw = query2array($query, null, 'id');
                $subcat_ids = array_values(array_filter($subcat_ids_raw, is_string(...)));
                $cat_id = $page_category['id'] ?? null;
                if (is_scalar($cat_id)) {
                    $subcat_ids[] = (string) $cat_id;
                }
                $where_sql = 'category_id IN (' . implode(',', $subcat_ids) . ')';
                // remove categories from forbidden because just checked above
                $forbidden = get_sql_condition_FandF(
                    [
                        'visible_images' => 'id',
                    ],
                    'AND'
                );
            } else {
                $user_id_for_cache = $user['id'] ?? null;
                $user_id_for_cache = is_scalar($user_id_for_cache) ? $user_id_for_cache : '';
                $cache_update_time = $user['cache_update_time'] ?? null;
                $cache_update_time = is_scalar($cache_update_time) ? $cache_update_time : '';
                $order_by_for_cache = is_string($conf['order_by']) ? $conf['order_by'] : '';
                $cache_key = $persistent_cache->make_key('all_iids' . $user_id_for_cache . $cache_update_time . $order_by_for_cache);
                unset($page['is_homepage']);
                $where_sql = '1=1';
            }
        }
        // normal mode
        else {
            // the enclosing elseif requires isset($page['category']) or
            // isset($page['flat']); $page['flat'] isn't set in this branch
            // (see the `if (isset($page['flat']))` above), so category
            // must be the one that's set
            assert($page_category !== null);
            $normal_mode_cat_id = $page_category['id'] ?? null;
            $where_sql = 'category_id = ' . (is_scalar($normal_mode_cat_id) ? $normal_mode_cat_id : '0');
        }

        $cache_key_str = isset($cache_key) && is_string($cache_key) ? $cache_key : null;

        if ($cache_key_str === null || ! $persistent_cache->get($cache_key_str, $page['items'])) {
            // main query
            $query = '
SELECT DISTINCT(image_id)
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::images() . ' ON id = image_id
  WHERE
    ' . $where_sql . '
' . $forbidden . '
  ' . (is_string($conf['order_by']) ? $conf['order_by'] : '') . '
;';

            $page['items'] = query2array($query, null, 'image_id');

            if ($cache_key_str !== null) {
                $persistent_cache->set($cache_key_str, $page['items']);
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
        // parse_section_url() (functions_url.inc.php) always sets 'tags'
        // alongside 'section' => 'tags'
        assert(isset($page['tags']));
        $tags_raw = is_array($page['tags']) ? $page['tags'] : [];
        $tag_ids = [];
        foreach ($tags_raw as $tag) {
            // PHPStan proves $tag is always string here, apparently cross-
            // referencing some unrelated $page['tags'] write elsewhere in
            // the codebase -- but the real, original (pre-L10) behavior of
            // *this* code path is find_tags()'s row shape (array with an
            // 'id' key, confirmed via `git log -p` on this file's prior
            // revision), so this is a real defensive check, not dead code.
            // @phpstan-ignore function.impossibleType, nullCoalesce.offset
            $tag_id = is_array($tag) ? ($tag['id'] ?? null) : null;
            // @phpstan-ignore function.impossibleType
            if (is_numeric($tag_id)) {
                // same cross-file $page[...] false-narrowing as above --
                // PHPStan believes $tag_id is already int here, but the
                // real, original row shape (see comment above) can yield a
                // numeric string, so this cast is load-bearing.
                // @phpstan-ignore cast.useless
                $tag_ids[] = (int) $tag_id;
            }
        }
        $page['tag_ids'] = $tag_ids;

        $items = get_image_ids_for_tags($tag_ids);

        if (count($items) == 0) {
            $remote_addr = $_SERVER['REMOTE_ADDR'];
            $remote_addr = is_string($remote_addr) ? $remote_addr : '';
            $logger->info(
                'attempt to see the name of the tag #' . implode(', #', array_map(strval(...), $tag_ids))
        . ' from the address : ' . $remote_addr
            );
            access_denied();
        }

        $page = array_merge(
            $page,
            [
                'title' => get_tags_content_title(),
                'items' => $items,
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                           search section                              |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'search') {
        include_once PHPWG_ROOT_PATH . 'include/functions_search.inc.php';

        // parse_section_url() (functions_url.inc.php) always sets 'search'
        // alongside 'section' => 'search'; 'super_order_by' is genuinely
        // optional (get_search_results()'s 2nd param is ?bool)
        assert(isset($page['search']));
        $search_id = is_string($page['search']) ? $page['search'] : '';
        $search_super_order_by = $page['super_order_by'] ?? null;
        $search_super_order_by = is_bool($search_super_order_by) ? $search_super_order_by : null;
        $search_result = get_search_results($search_id, $search_super_order_by);

        // save the details of the query search
        if (isset($search_result['qs'])) {
            $page['qsearch_details'] = $search_result['qs'];
        } elseif (isset($search_result['search_details'])) {
            $page['search_details'] = $search_result['search_details'];
        }

        $page = array_merge(
            $page,
            [
                'items' => $search_result['items'],
                'title' => '<a href="' . duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . l10n('Search results') . '</a>',
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                           favorite section                            |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'favorites') {
        check_user_favorites();

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . l10n('Favorites') . '</a>',
            ]
        );

        $user_id_sql = $user['id'] ?? null;
        $user_id_sql = is_scalar($user_id_sql) ? $user_id_sql : 0;
        if (! empty($_GET['action']) && ($_GET['action'] == 'remove_all_from_favorites')) {
            $query = '
DELETE FROM ' . Tables::favorites() . '
  WHERE user_id = ' . $user_id_sql . '
;';
            pwg_query($query);
            redirect(make_index_url([
                'section' => 'favorites',
            ]));
        } else {
            $query = '
SELECT image_id
  FROM ' . Tables::favorites() . '
    INNER JOIN ' . Tables::images() . ' ON image_id = id
  WHERE user_id = ' . $user_id_sql . '
' . get_sql_condition_FandF(
                [
                    'visible_images' => 'id',
                ],
                'AND'
            ) . '
  ' . (is_string($conf['order_by']) ? $conf['order_by'] : '') . '
;';
            $page = array_merge(
                $page,
                [
                    'items' => query2array($query, null, 'image_id'),
                ]
            );

            if (count($page['items']) > 0) {
                $template->assign(
                    'favorite',
                    [
                        'U_FAVORITE' => add_url_params(
                            make_index_url([
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
                'ORDER BY date_available DESC,',
                is_string($conf['order_by']) ? $conf['order_by'] : ''
            );
        }

        $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE '
  . get_recent_photos_sql('date_available') . '
  ' . $forbidden
  . (is_string($conf['order_by']) ? $conf['order_by'] : '') . '
;';

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . l10n('Recent photos') . '</a>',
                'items' => query2array($query, null, 'id'),
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
                'title' => '<a href="' . duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . l10n('Recent albums') . '</a>',
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                        most visited section                           |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'most_visited') {
        $page['super_order_by'] = true;
        $conf['order_by'] = ' ORDER BY hit DESC, id DESC';

        $top_number = is_numeric($conf['top_number']) ? (int) $conf['top_number'] : 15;

        $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE hit > 0
    ' . $forbidden . '
    ' . $conf['order_by'] . '
  LIMIT ' . $top_number . '
;';

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . $top_number . ' ' . l10n('Most visited') . '</a>',
                'items' => query2array($query, null, 'id'),
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                          best rated section                           |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'best_rated') {
        $page['super_order_by'] = true;
        $conf['order_by'] = ' ORDER BY rating_score DESC, id DESC';

        $top_number = is_numeric($conf['top_number']) ? (int) $conf['top_number'] : 15;

        $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE rating_score IS NOT NULL
    ' . $forbidden . '
    ' . $conf['order_by'] . '
  LIMIT ' . $top_number . '
;';
        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . $top_number . ' ' . l10n('Best rated') . '</a>',
                'items' => query2array($query, null, 'id'),
            ]
        );
    }
    // +-----------------------------------------------------------------------+
    // |                             list section                              |
    // +-----------------------------------------------------------------------+
    elseif ($page['section'] == 'list') {
        // parse_section_url() (functions_url.inc.php) always sets 'list'
        // (a dummy [-1] or a real id list) alongside 'section' => 'list'
        assert(isset($page['list']));
        $list_ids_raw = is_array($page['list']) ? array_filter($page['list'], is_scalar(...)) : [];
        $list_ids = array_map(strval(...), $list_ids_raw);
        $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::images() . '
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON id = ic.image_id
  WHERE image_id IN (' . implode(',', $list_ids) . ')
    ' . $forbidden . '
  ' . (is_string($conf['order_by']) ? $conf['order_by'] : '') . '
;';

        $page = array_merge(
            $page,
            [
                'title' => '<a href="' . duplicate_index_url([
                    'start' => 0,
                ]) . '">'
                            . l10n('Random photos') . '</a>',
                'items' => query2array($query, null, 'id'),
            ]
        );
    }
}

// +-----------------------------------------------------------------------+
// |                             chronology                                |
// +-----------------------------------------------------------------------+
if (isset($page['chronology_field'])) {
    unset($page['is_homepage']);
    include_once PHPWG_ROOT_PATH . 'include/functions_calendar.inc.php';
    initialize_calendar();
}

// title update
if (isset($page['title'])) {
    // get_gallery_home_url() is declared to return `mixed`
    // (include/functions_url.inc.php); every real branch of its body
    // actually returns a string, so this narrows locally rather than
    // widening this concatenation's context
    $gallery_home_url = get_gallery_home_url();
    $gallery_home_url = is_string($gallery_home_url) ? $gallery_home_url : '';
    $page['section_title'] = '<a href="' . $gallery_home_url . '">' . l10n('Home') . '</a>';
    if (! empty($page['title'])) {
        $level_separator = is_string($conf['level_separator']) ? $conf['level_separator'] : ' / ';
        $title_value = is_string($page['title']) ? $page['title'] : '';
        $page['section_title'] .= $level_separator . $title_value;
    } else {
        $page['title'] = $page['section_title'];
    }
}

// add meta robots noindex, nofollow to avoid unnecesary robot crawls
$page['meta_robots'] = [];
if (isset($page['chronology_field'])
      or (isset($page['flat']) and isset($page['category']))
      or $page['section'] == 'list' or $page['section'] == 'recent_pics') {
    $page['meta_robots'] = [
        'noindex' => 1,
        'nofollow' => 1,
    ];
} elseif ($page['section'] == 'tags') {
    $page_tag_ids = $page['tag_ids'] ?? null;
    if (is_countable($page_tag_ids) and count($page_tag_ids) > 1) {
        $page['meta_robots'] = [
            'noindex' => 1,
            'nofollow' => 1,
        ];
    }
} elseif ($page['section'] == 'recent_cats') {
    $page['meta_robots']['noindex'] = 1;
} elseif ($page['section'] == 'search') {
    $page['meta_robots'] = [
        'noindex' => 1,
        'nofollow' => 1,
    ];
} elseif ($page['section'] == 'categories') {
    if (isset($page['combined_categories'])) {
        $page['meta_robots'] = [
            'noindex' => 1,
            'nofollow' => 1,
        ];
    }
}

if ((bool) $filter['enabled']) {
    $page['meta_robots']['noindex'] = 1;
}

// see if we need a redirect because of a permalink
if ($page['section'] == 'categories' and $page_category !== null and ! isset($page['combined_categories'])) {
    $need_redirect = false;
    $hit_by = is_array($page['hit_by'] ?? null) ? $page['hit_by'] : [];
    $category_permalink = $page_category['permalink'] ?? null;
    if (empty($category_permalink)) {
        $hit_by_cat_url_name = $hit_by['cat_url_name'] ?? null;
        $category_name = $page_category['name'] ?? null;
        $category_name = is_string($category_name) ? $category_name : '';
        if ($conf['category_url_style'] == 'id-name' and
            $hit_by_cat_url_name !== str2url($category_name)) {
            $need_redirect = true;
        }
    } else {
        $hit_by_cat_permalink = $hit_by['cat_permalink'] ?? null;
        if ($category_permalink !== $hit_by_cat_permalink) {
            $need_redirect = true;
        }
    }

    if ($need_redirect) {
        $redirect_category_id = $page_category['id'] ?? null;
        check_restrictions(is_numeric($redirect_category_id) ? (int) $redirect_category_id : 0);
        $redirect_url = script_basename() == 'picture' ? duplicate_picture_url() : duplicate_index_url();

        if (! headers_sent()) { // this is a permanent redirection
            set_status_header(301);
            redirect_http($redirect_url);
        }
        redirect($redirect_url);
    }
    unset($need_redirect, $page['hit_by']);
}

// $page['body_classes']/$page['body_data'] are seeded as [] in
// include/common.inc.php, but that's a different file/scope from
// PHPStan's point of view, so re-prove array-ness once here and work on
// local copies (repeatedly array_push()-ing into a mixed-typed nested
// offset defeats PHPStan's tracking even when the offset is provably an
// array at each individual call site).
$body_classes = is_array($page['body_classes'] ?? null) ? $page['body_classes'] : [];
$body_data = is_array($page['body_data'] ?? null) ? $page['body_data'] : [];

$page_section = is_string($page['section']) ? $page['section'] : '';
array_push($body_classes, 'section-' . $page_section);
$body_data['section'] = $page['section'];

if ($page['section'] == 'categories' && $page_category !== null) {
    $body_category_id = $page_category['id'] ?? null;
    $body_category_id = is_scalar($body_category_id) ? $body_category_id : '';
    array_push($body_classes, 'category-' . $body_category_id);
    $body_data['category_id'] = $body_category_id;

    if (isset($page['combined_categories'])) {
        $combined_category_ids = [];
        $combined_categories_for_body = is_array($page['combined_categories']) ? $page['combined_categories'] : [];
        foreach ($combined_categories_for_body as $combined_category) {
            $combined_body_id = is_array($combined_category) ? ($combined_category['id'] ?? null) : null;
            $combined_body_id = is_scalar($combined_body_id) ? $combined_body_id : '';
            array_push($body_classes, 'category-' . $combined_body_id);
            $combined_category_ids[] = $combined_body_id;
        }
        $body_data['combined_category_ids'] = $combined_category_ids;
    }
} elseif (isset($page['tags'])) {
    $body_tag_ids = [];
    $tags_for_body = is_array($page['tags']) ? $page['tags'] : [];
    foreach ($tags_for_body as $tag) {
        $body_tag_id = is_array($tag) ? ($tag['id'] ?? null) : null;
        $body_tag_id = is_scalar($body_tag_id) ? $body_tag_id : '';
        array_push($body_classes, 'tag-' . $body_tag_id);
        $body_tag_ids[] = $body_tag_id;
    }
    $body_data['tag_ids'] = $body_tag_ids;
} elseif (isset($page['search'])) {
    $body_search_id = is_scalar($page['search']) ? $page['search'] : '';
    array_push($body_classes, 'search-' . $body_search_id);
    $body_data['search_id'] = $body_search_id;
}

if (isset($page['image_id'])) {
    $body_image_id = is_scalar($page['image_id']) ? $page['image_id'] : '';
    array_push($body_classes, 'image-' . $body_image_id);
    $body_data['image_id'] = $body_image_id;
}

$page['body_classes'] = $body_classes;
$page['body_data'] = $body_data;

trigger_notify('loc_end_section_init');
