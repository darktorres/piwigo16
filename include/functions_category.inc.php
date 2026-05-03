<?php

declare(strict_types=1);
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * @package functions\category
 */


/**
 * Callback used for sorting by global_rank
 */
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function global_rank_compare(array $a, array $b): int
{
    return strnatcasecmp(is_scalar($a['global_rank']) ? (string) $a['global_rank'] : '', is_scalar($b['global_rank']) ? (string) $b['global_rank'] : '');
}

/**
 * Callback used for sorting by rank
 */
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function rank_compare(array $a, array $b): int
{
    return (int) ((is_numeric($a['rank']) ? (int) $a['rank'] : 0) - (is_numeric($b['rank']) ? (int) $b['rank'] : 0));
}

/**
 * Is the category accessible to the connected user ?
 * If the user is not authorized to see this category, script exits
 *
 * @param int $category_id
 */
function check_restrictions($category_id): void
{
    $forbidden = \Piwigo\Users\CurrentUser::get()->rawAttributes['forbidden_categories'] ?? '';
    // $filter['visible_categories'] and $filter['visible_images']
    // are not used because it's not necessary (filter <> restriction)
    if (in_array($category_id, explode(',', is_scalar($forbidden) ? (string) $forbidden : ''))) {
        access_denied();
    }
}

/**
 * Returns template vars for main categories menu.
 *
 * @return array[]
 */
/** @return array<mixed> */
function get_categories_menu(): array
{
    $filter = is_array($GLOBALS['filter'] ?? null) ? $GLOBALS['filter'] : [];
    $page = &$GLOBALS['page'];
    if (!is_array($page)) {
        $page = [];
    }
    $currentUser = \Piwigo\Users\CurrentUser::get();
    $userExpand = $currentUser->rawAttributes['expand'] ?? false;

    $query = '
SELECT ';
    // From CATEGORIES_TABLE
    $query .= '
  id, name, permalink, nb_images, global_rank,';
    // From USER_CACHE_CATEGORIES_TABLE
    $query .= '
  date_last, max_date_last, count_images, count_categories';

    // forbidden_categories handled by USER_CACHE_CATEGORIES_TABLE join
    $query .= '
FROM '.CATEGORIES_TABLE.' INNER JOIN '.USER_CACHE_CATEGORIES_TABLE.'
  ON id = cat_id and user_id = '.$currentUser->id;

    // Always expand when filter is activated
    if (!$userExpand and !$filter['enabled']) {
        $where = '
(id_uppercat is NULL';
        $category = is_array($page['category'] ?? null) ? $page['category'] : null;
        if ($category !== null) {
            $uppercats = is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '';
            $where .= ' OR id_uppercat IN ('.$uppercats.')';
        }
        $where .= ')';
    } else {
        $where = '
  '.get_sql_condition_FandF(
            [
              'visible_categories' => 'id',
            ],
            null,
            true
        );
    }

    $where = trigger_change(
        'get_categories_menu_sql_where',
        $where,
        $userExpand,
        $filter['enabled']
    );

    $query .= '
WHERE '.$where.'
;';

    $result = pwg_query($query);
    $cats = [];
    $selected_category = is_array($page['category'] ?? null) ? $page['category'] : null;
    while ($row = pwg_db_fetch_assoc($result)) {
        $child_date_last = @$row['max_date_last'] > @$row['date_last'];
        $row = array_merge(
            $row,
            [
            'NAME' => trigger_change(
                'render_category_name',
                $row['name'],
                'get_categories_menu'
            ),
            'TITLE' => get_display_images_count(
                (int) $row['nb_images'],
                (int) $row['count_images'],
                (int) $row['count_categories'],
                false,
                ' / '
            ),
            'URL' => make_index_url(['category' => $row]),
            'LEVEL' => substr_count((string) $row['global_rank'], '.') + 1,
            'SELECTED' => ($selected_category !== null && $selected_category['id'] == $row['id']) ? true : false,
            'IS_UPPERCAT' => ($selected_category !== null && $selected_category['id_uppercat'] == $row['id']) ? true : false,
            ]
        );
        if (\Piwigo\Config\Config::indexNewIcon()) {
            $row['icon_ts'] = get_icon(is_string($row['max_date_last']) || is_null($row['max_date_last']) ? $row['max_date_last'] : (string) $row['max_date_last'], $child_date_last);
        }
        $cats[] = $row;
        if ($selected_category !== null && $row['id'] == ($selected_category['id'] ?? null)) {
            //save the number of subcats for later optim
            $cat = is_array($page['category'] ?? null) ? $page['category'] : [];
            $cat['count_categories'] = $row['count_categories'];
            $page['category'] = $cat;
        }
    }
    usort($cats, global_rank_compare(...));

    // Update filtered data
    if (function_exists('update_cats_with_filtered_data')) {
        update_cats_with_filtered_data($cats);
    }

    return $cats;
}

/**
 * Retrieves informations about a category.
 *
 * @param int $id
 * @return array|null
 */
/** @return array<mixed>|null */
function get_cat_info(int|string $id): ?array
{
    $query = '
SELECT *
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$id.'
;';
    $cat = pwg_db_fetch_assoc(pwg_query($query));
    if (empty($cat)) {
        return null;
    }

    foreach ($cat as $k => $v) {
        // If the field is true or false, the variable is transformed into a
        // boolean value.
        if ($cat[$k] == 'true' or $cat[$k] == 'false') {
            $cat[$k] = get_boolean($cat[$k]);
        }
    }

    $upper_ids = explode(',', (string) $cat['uppercats']);
    if (count($upper_ids) == 1) {// no need to make a query for level 1
        $cat['upper_names'] = [
            [
              'id' => $cat['id'],
              'name' => $cat['name'],
              'permalink' => $cat['permalink'],
              ],
          ];
    } else {
        $query = '
  SELECT id, name, permalink
    FROM '.CATEGORIES_TABLE.'
    WHERE id IN ('.$cat['uppercats'].')
  ;';
        $names = query2array($query, 'id');

        // category names must be in the same order than uppercats list
        $cat['upper_names'] = [];
        foreach ($upper_ids as $cat_id) {
            $cat['upper_names'][] = $names[$cat_id];
        }
    }
    return $cat;
}

/**
 * Returns an array of image orders available for users/visitors.
 * Each entry is an array containing
 *  0: name
 *  1: SQL ORDER command
 *  2: visiblity (true or false)
 *
 * @return array[]
 */
/** @return array<mixed> */
function get_category_preferred_image_orders(): array
{
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

    $result = trigger_change('get_category_preferred_image_orders', [
      [l10n('Default'),                        '',                     true],
      [l10n('Photo title, A &rarr; Z'),        'name ASC',             true],
      [l10n('Photo title, Z &rarr; A'),        'name DESC',            true],
      [l10n('Date created, new &rarr; old'),   'date_creation DESC',   true],
      [l10n('Date created, old &rarr; new'),   'date_creation ASC',    true],
      [l10n('Date posted, new &rarr; old'),    'date_available DESC',  true],
      [l10n('Date posted, old &rarr; new'),    'date_available ASC',   true],
      [l10n('Rating score, high &rarr; low'),  'rating_score DESC',    \Piwigo\Config\Config::rateEnabled()],
      [l10n('Rating score, low &rarr; high'),  'rating_score ASC',     \Piwigo\Config\Config::rateEnabled()],
      [l10n('Visits, high &rarr; low'),        'hit DESC',             true],
      [l10n('Visits, low &rarr; high'),        'hit ASC',              true],
      [l10n('Permissions'),                    'level DESC',           is_admin()],
      ]);
    return $result;
}

/**
 * Assign a template var useable with {html_options} from a list of categories
 *
 * @param list<array<string, float|int|string|null>> $categories
 * @param int[]|string $selecteds
 * @param string $blockname variable name in template
 * @param bool $fullname full breadcrumb or not
 */
function display_select_categories(
    array $categories,
    array|string $selecteds,
    string $blockname,
    bool|string $fullname = true
): void {
    $template = \Piwigo\Template\TemplateRegistry::current();

    $tpl_cats = [];
    foreach ($categories as $category) {
        if ($fullname) {
            $option = strip_tags(
                get_cat_display_name_cache(
                    (string) $category['uppercats'],
                    null
                )
            );
        } else {
            $option = str_repeat(
                '&nbsp;',
                (3 * substr_count(is_scalar($category['global_rank']) ? (string) $category['global_rank'] : '', '.'))
            );
            $option .= '- ';
            $option .= strip_tags(
                (string) trigger_change(
                    'render_category_name',
                    is_scalar($category['name']) ? (string) $category['name'] : '',
                    'display_select_categories'
                )
            );
        }
        $tpl_cats[ (string) $category['id'] ] = $option;
    }

    $template->assign($blockname, $tpl_cats);
    $template->assign($blockname.'_selected', $selecteds);
}

/**
 * Same as display_select_categories but categories are ordered by rank
 * @see display_select_categories()
 * @param int[]|string $selecteds
 */
function display_select_cat_wrapper(
    string $query,
    array|string $selecteds,
    string $blockname,
    bool|string $fullname = true
): void {
    $categories = query2array($query);
    usort($categories, global_rank_compare(...));
    display_select_categories($categories, $selecteds, $blockname, $fullname);
}

/**
 * Returns all subcategory identifiers of given category ids
 *
 * @param array<int|string>|int|string $ids
 * @return array<int>
 */
function get_subcat_ids(array|int|string $ids): array
{
    if (!is_array($ids)) {
        $ids = [$ids];
    }
    $query = '
SELECT DISTINCT(id)
  FROM '.CATEGORIES_TABLE.'
  WHERE ';
    foreach ($ids as $num => $category_id) {
        is_numeric($category_id)
          or trigger_error(
              'get_subcat_ids expecting numeric, not '.gettype($category_id),
              E_USER_WARNING
          );
        if ($num > 0) {
            $query .= '
    OR ';
        }
        $query .= 'uppercats '.DB_REGEX_OPERATOR.' \'(^|,)'.$category_id.'(,|$)\'';
    }
    $query .= '
;';
    return array_map(fn ($v) => (int) $v, query2array($query, null, 'id'));
}

/**
 * Finds a matching category id from a potential list of permalinks
 *
 * @param string[] $permalinks
 * @param int &$idx filled with the index in $permalinks that matches
 * @return int|null
 */
function get_cat_id_from_permalinks(array $permalinks, &$idx)
{
    $in = '';
    foreach ($permalinks as $permalink) {
        if (!empty($in)) {
            $in .= ', ';
        }
        $in .= '\''.$permalink.'\'';
    }
    $query = '
SELECT cat_id AS id, permalink, 1 AS is_old
  FROM '.OLD_PERMALINKS_TABLE.'
  WHERE permalink IN ('.$in.')
UNION
SELECT id, permalink, 0 AS is_old
  FROM '.CATEGORIES_TABLE.'
  WHERE permalink IN ('.$in.')
;';
    $perma_hash = query2array($query, 'permalink');

    if (empty($perma_hash)) {
        return null;
    }
    for ($i = count($permalinks) - 1; $i >= 0; $i--) {
        if (isset($perma_hash[ $permalinks[$i] ])) {
            $idx = $i;
            $cat_id = (int) $perma_hash[ $permalinks[$i] ]['id'];
            if ($perma_hash[ $permalinks[$i] ]['is_old']) {
                $query = '
UPDATE '.OLD_PERMALINKS_TABLE.' SET last_hit=NOW(), hit=hit+1
  WHERE permalink=\''.$permalinks[$i].'\' AND cat_id='.$cat_id.'
  LIMIT 1';
                pwg_query($query);
            }
            return $cat_id;
        }
    }
    return null;
}

/**
 * Returns display text for images counter of category
 *
 * @param int $cat_nb_images nb images directly in category
 * @param int $cat_count_images nb images in category (including subcats)
 * @param int $cat_count_categories nb subcats
 * @param bool $short_message if true append " in this album"
 */
function get_display_images_count($cat_nb_images, $cat_count_images, $cat_count_categories, $short_message = true, string $separator = '\n'): string
{
    $display_text = '';

    if ($cat_count_images > 0) {
        if ($cat_nb_images > 0 and $cat_nb_images < $cat_count_images) {
            $display_text .= get_display_images_count($cat_nb_images, $cat_nb_images, 0, $short_message, $separator).$separator;
            $cat_count_images -= $cat_nb_images;
            $cat_nb_images = 0;
        }

        //at least one image direct or indirect
        $display_text .= l10n_dec('%d photo', '%d photos', $cat_count_images);

        if ($cat_count_categories == 0 or $cat_nb_images == $cat_count_images) {
            //no descendant categories or descendants do not contain images
            if (!$short_message) {
                $display_text .= ' '.l10n('in this album');
            }
        } else {
            $display_text .= ' '.l10n_dec('in %d sub-album', 'in %d sub-albums', $cat_count_categories);
        }
    }

    return $display_text;
}

/**
 * Find a random photo among all photos inside an album (including sub-albums)
 *
 * @param array $category (at least id,uppercats,count_images)
 * @param bool $recursive
 * @return int|null
 */
/** @param array<string, bool|float|int|string|null> $category */
function get_random_image_in_category(array $category, bool $recursive = true): ?int
{
    $image_id = null;
    if ($category['count_images'] > 0) {
        $query = '
SELECT image_id
  FROM '.CATEGORIES_TABLE.' AS c
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.category_id = c.id
  WHERE ';
        if ($recursive) {
            $query .= '
    (c.id='.(int) $category['id'].' OR uppercats LIKE \''.addslashes((string) $category['uppercats']).',%\')';
        } else {
            $query .= '
    c.id='.(int) $category['id'];
        }
        $query .= '
    '.get_sql_condition_FandF(
            [
              'forbidden_categories' => 'c.id',
              'visible_categories' => 'c.id',
              'visible_images' => 'image_id',
            ],
            "\n  AND"
        ).'
  ORDER BY '.DB_RANDOM_FUNCTION.'()
  LIMIT 1
;';
        $result = pwg_query($query);
        if (pwg_db_num_rows($result) > 0) {
            $row = pwg_db_fetch_row($result) ?? [null];
            $image_id = is_numeric($row[0]) ? (int) $row[0] : null;
        }
    }

    return $image_id;
}

/**
 * Get computed array of categories, that means cache data of all categories
 * available for the current user (count_categories, count_images, etc.).
 *
 * @param int $filter_days number of recent days to filter on or null
 */
/**
 * @param array<string, mixed> $userdata
 * @return array<string, array<string, mixed>>
 */
function get_computed_categories(array &$userdata, ?int $filter_days = null): array
{
    $query = 'SELECT c.id AS cat_id, id_uppercat';
    $query .= ', global_rank';
    // Count by date_available to avoid count null
    $query .= ',
  MAX(date_available) AS date_last, COUNT(date_available) AS nb_images
FROM '.CATEGORIES_TABLE.' as c
  LEFT JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.category_id = c.id
  LEFT JOIN '.IMAGES_TABLE.' AS i
    ON ic.image_id = i.id
      AND i.level<='.(is_numeric($userdata['level']) ? (int) $userdata['level'] : 0);

    if (isset($filter_days)) {
        $query .= ' AND i.date_available > '.pwg_db_get_recent_period_expression($filter_days);
    }

    if (!empty($userdata['forbidden_categories'])) {
        $query .= '
  WHERE c.id NOT IN ('.(is_scalar($userdata['forbidden_categories']) ? (string) $userdata['forbidden_categories'] : '').')';
    }

    $query .= '
  GROUP BY c.id';

    $result = pwg_query($query);

    $userdata['last_photo_date'] = null;
    $cats = [];
    while ($row = pwg_db_fetch_assoc($result)) {
        $row['user_id'] = is_scalar($userdata['id']) ? $userdata['id'] : 0;
        $row['nb_categories'] = 0;
        $row['count_categories'] = 0;
        $row['count_images'] = is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0;
        $row['max_date_last'] = $row['date_last'];
        if ($row['date_last'] > $userdata['last_photo_date']) {
            $userdata['last_photo_date'] = $row['date_last'];
        }

        $cats[is_scalar($row['cat_id']) ? (string) $row['cat_id'] : ''] = $row;
    }

    // it is important to logically sort the albums because some operations
    // (like removal) rely on this logical order. Child album doesn't always
    // have a bigger id than its parent (if it was moved afterwards).
    uasort($cats, global_rank_compare(...));

    foreach ($cats as $cat) {
        if (!isset($cat['id_uppercat'])) {
            continue;
        }

        // Piwigo before 2.5.3 may have generated inconsistent permissions, ie
        // private album A1/A2 permitted to user U1 but private album A1 not
        // permitted to U1.
        //
        // TODO 2.7: add an upgrade script to repair permissions and remove this
        // test
        $cat_uppercat_key = (string) $cat['id_uppercat'];
        if (!isset($cats[$cat_uppercat_key])) {
            continue;
        }

        $parent = & $cats[$cat_uppercat_key];
        $parent['nb_categories']++;

        do {
            $parent['count_images'] += is_numeric($cat['nb_images']) ? (int) $cat['nb_images'] : 0;
            $parent['count_categories']++;

            if ((empty($parent['max_date_last'])) or ($parent['max_date_last'] < $cat['date_last'])) {
                $parent['max_date_last'] = $cat['date_last'];
            }

            if (!isset($parent['id_uppercat'])) {
                break;
            }
            $parent = & $cats[(string) $parent['id_uppercat']];
        } while (true);
        unset($parent);
    }

    if (isset($filter_days)) {
        foreach ($cats as $category) {
            if (empty($category['max_date_last'])) {
                remove_computed_category($cats, $category);
            }
        }
    }

    return $cats;
}

/**
 * Removes a category from computed array of categories and updates counters.
 *
 * @param array $cat category to remove
 */
/**
 * @param array<string, array<string, mixed>> $cats
 * @param array<string, mixed> $cat
 */
function remove_computed_category(array &$cats, array $cat): void
{
    $idUppercat = $cat['id_uppercat'] ?? null;
    $cat_uppercat_key = is_scalar($idUppercat) ? (string) $idUppercat : '';
    if ($cat_uppercat_key !== '' && isset($cats[$cat_uppercat_key])) {
        $parent = &$cats[$cat_uppercat_key];
        $parent['nb_categories'] = (is_numeric($parent['nb_categories'] ?? null) ? (int) $parent['nb_categories'] : 0) - 1;

        do {
            $parent['count_images'] = (is_numeric($parent['count_images'] ?? null) ? (int) $parent['count_images'] : 0) - (is_numeric($cat['nb_images'] ?? null) ? (int) $cat['nb_images'] : 0);
            $parent['count_categories'] = (is_numeric($parent['count_categories'] ?? null) ? (int) $parent['count_categories'] : 0) - 1 - (is_numeric($cat['count_categories'] ?? null) ? (int) $cat['count_categories'] : 0);

            if (!isset($parent['id_uppercat'])) {
                break;
            }
            $parentUppercat = $parent['id_uppercat'];
            $parent_uppercat_key = is_scalar($parentUppercat) ? (string) $parentUppercat : '';
            if ($parent_uppercat_key === '' || !isset($cats[$parent_uppercat_key])) {
                break;
            }
            $parent = &$cats[$parent_uppercat_key];
        } while (true);
    }

    $catId = $cat['cat_id'] ?? null;
    unset($cats[is_scalar($catId) ? (string) $catId : '']);
}

/**
 * Return the list of image ids corresponding to given categories.
 * AND & OR mode supported.
 *
 * @param int[] $cat_ids
 * @param string $mode
 * @param string $extra_images_where_sql - optionally apply a sql where filter to retrieved images
 * @param string $order_by - optionally overwrite default photo order
 */
/**
 * @param int[]|int|string $cat_ids
 * @return int[]
 */
function get_image_ids_for_categories(array|int|string $cat_ids, string $mode = 'AND', ?string $extra_images_where_sql = '', string $order_by = '', bool $use_permissions = true): array
{
    if (empty($cat_ids)) {
        return [];
    }
    if (!is_array($cat_ids)) {
        $cat_ids = [$cat_ids];
    }

    $query = '
SELECT id
  FROM '.IMAGES_TABLE.' i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' ic ON id=ic.image_id
  WHERE category_id IN ('.implode(',', $cat_ids).')';

    if ($use_permissions) {
        $query .= get_sql_condition_FandF(
            [
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            'visible_images' => 'id',
            ],
            "\n  AND"
        );
    }

    $query .= (empty($extra_images_where_sql) ? '' : " \nAND (".$extra_images_where_sql.')').'
  GROUP BY id';

    if ($mode == 'AND' and count($cat_ids) > 1) {
        $query .= '
  HAVING COUNT(DISTINCT category_id)='.count($cat_ids);
    }
    $query .= "\n".(empty($order_by) ? \Piwigo\Config\Config::orderBy() : $order_by);

    return array_map(fn ($v) => (int) $v, query2array($query, null, 'id'));
}

/**
 * Return a list of categories corresponding to given items.
 *
 * @param int[] $items
 * @param int $max
 * @param int[] $excluded_cat_ids
 * @return array [id, name, counter, url_name]
 */
/**
 * @param array<mixed> $items
 * @param int[] $excluded_cat_ids
 * @return array<string, array<string, float|int|string|null>>
 */
function get_common_categories(array $items, ?int $max = null, array $excluded_cat_ids = [], bool $use_permissions = true): array
{
    if (empty($items)) {
        return [];
    }

    $query = '
SELECT
    c.id,
    c.uppercats,
    count(*) AS counter
  FROM '.IMAGE_CATEGORY_TABLE.'
    INNER JOIN '.CATEGORIES_TABLE.' c ON category_id = id
  WHERE image_id IN ('.implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string)$v : '', $items)).')';

    if ($use_permissions) {
        $query .= get_sql_condition_FandF(
            [
            'forbidden_categories' => 'category_id',
            'visible_categories' => 'category_id',
            ],
            "\n    AND"
        );
    }

    if (!empty($excluded_cat_ids)) {
        $query .= '
    AND category_id NOT IN ('.implode(',', $excluded_cat_ids).')';
    }

    $query .= '
  GROUP BY c.id
  ORDER BY ';
    if (isset($max)) {
        $query .= 'counter DESC
  LIMIT '.$max;
    } else {
        $query .= 'NULL';
    }

    $result = pwg_query($query);
    $cats = [];
    while ($row = pwg_db_fetch_assoc($result)) {
        $cats[ (string) $row['id'] ] = $row;
    }

    return $cats;
}

/**
 * @return mixed[]
 */
/**
 * @param array<mixed> $items
 * @param int[] $excluded_cat_ids
 * @return array<mixed>
 */
function get_related_categories_menu(array $items, array $excluded_cat_ids = []): array
{
    $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

    $common_cats = get_common_categories($items, \Piwigo\Config\Config::relatedAlbumsDisplayLimit(), $excluded_cat_ids);
    // echo '<pre>'; print_r($common_cats); echo '</pre>';

    if (count($common_cats) == 0) {
        return [];
    }

    $cat_ids = [];
    // now we add the upper categories and useful values such as depth level and url
    foreach ($common_cats as $cat) {
        foreach (explode(',', (string) $cat['uppercats']) as $uppercat) {
            @$cat_ids[$uppercat]++;
        }
    }

    $query = '
SELECT
    id,
    name,
    permalink,
    id_uppercat,
    uppercats,
    global_rank
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', array_keys($cat_ids)).')
;';
    $cats = query2array($query);
    usort($cats, global_rank_compare(...));

    $index_of_cat = [];

    foreach ($cats as $idx => $cat) {
        $cat_id_key = (string) $cat['id'];
        $index_of_cat[$cat_id_key] = $idx;
        $cats[$idx]['LEVEL'] = substr_count(is_scalar($cat['global_rank']) ? (string) $cat['global_rank'] : '', '.') + 1;
        $cats[$idx]['name'] = trigger_change('render_category_name', is_scalar($cat['name']) ? (string) $cat['name'] : '', $cat);

        // if the category is directly linked to the items, we add an URL + counter
        if (isset($common_cats[$cat_id_key])) {
            $cats[$idx]['count_images'] = $common_cats[$cat_id_key]['counter'];

            $url_params = [];
            if (isset($page['category'])) {
                $url_params['category'] = $page['category'];

                $url_params['combined_categories'] = [$cat];
                $combined = is_array($page['combined_categories'] ?? null) ? $page['combined_categories'] : null;
                if ($combined !== null) {
                    $url_params['combined_categories'] = array_merge($combined, [$cat]);
                }
            } else {
                $url_params['category'] = $cat;
            }

            $cats[$idx]['url'] = make_index_url($url_params);
        }

        // let's find how many sub-categories we have for each category. 3 options:
        // 1. direct sub-albums
        // 2. total indirect sub-albums
        // 3. number of sub-albums containing photos
        //
        // Option 3 seems more appropriate here.
        if (!empty($cat['id_uppercat']) and @$cats[$idx]['count_images'] > 0) {
            foreach (array_slice(explode(',', (string) $cat['uppercats']), 0, -1) as $uppercat_id) {
                $upper_idx = $index_of_cat[$uppercat_id] ?? null;
                if ($upper_idx !== null) {
                    $cats[$upper_idx]['count_categories'] = ((int) ($cats[$upper_idx]['count_categories'] ?? 0)) + 1;
                }
            }
        }
    }

    return $cats;
}
