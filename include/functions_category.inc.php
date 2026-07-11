<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * Callback used for sorting by global_rank
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function global_rank_compare(array $a, array $b): int
{
    $a_rank = $a['global_rank'];
    $b_rank = $b['global_rank'];

    return strnatcasecmp(
        is_scalar($a_rank) ? (string) $a_rank : '',
        is_scalar($b_rank) ? (string) $b_rank : ''
    );
}

/**
 * Callback used for sorting by rank
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function rank_compare(array $a, array $b): int
{
    $a_rank = $a['rank'];
    $b_rank = $b['rank'];

    return (is_numeric($a_rank) ? (int) $a_rank : 0) - (is_numeric($b_rank) ? (int) $b_rank : 0);
}

/**
 * Is the category accessible to the connected user ?
 * If the user is not authorized to see this category, script exits
 *
 * @param int $category_id
 */
function check_restrictions($category_id): void
{
    /** @var array<string, mixed> $user */
    global $user;

    // $filter['visible_categories'] and $filter['visible_images']
    // are not used because it's not necessary (filter <> restriction)
    $forbidden_categories = $user['forbidden_categories'] ?? null;
    $forbidden_categories_str = is_scalar($forbidden_categories) ? (string) $forbidden_categories : '';
    if (in_array($category_id, explode(',', $forbidden_categories_str))) {
        access_denied();
    }
}

/**
 * Returns template vars for main categories menu.
 *
 * @return array<int, array<string, mixed>>
 */
function get_categories_menu(): array
{
    /**
     * @var array<string, mixed> $page
     * @var array<string, mixed> $user
     * @var array<string, mixed> $filter
     * @var array<string, mixed> $conf
     */
    global $page, $user, $filter, $conf;

    $user_id = $user['id'] ?? null;
    $user_id_str = is_scalar($user_id) ? (string) $user_id : '0';

    // category currently displayed, if any; narrowed once here and reused
    // at every nested-offset access site below (see fix pattern #4/#7)
    $category_page_raw = $page['category'] ?? null;
    $category_page = is_array($category_page_raw) ? $category_page_raw : null;

    $query = '
SELECT ';
    // From Tables::categories()
    $query .= '
  id, name, permalink, nb_images, global_rank,';
    // From Tables::userCacheCategories()
    $query .= '
  date_last, max_date_last, count_images, count_categories';

    // $user['forbidden_categories'] including with Tables::userCacheCategories()
    $query .= '
FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userCacheCategories() . '
  ON id = cat_id and user_id = ' . $user_id_str;

    // Always expand when filter is activated
    if (! (bool) $user['expand'] and ! (bool) $filter['enabled']) {
        $where = '
(id_uppercat is NULL';
        if ($category_page !== null) {
            $uppercats = $category_page['uppercats'] ?? null;
            $where .= ' OR id_uppercat IN (' . (is_scalar($uppercats) ? (string) $uppercats : '') . ')';
        }
        $where .= ')';
    } else {
        $where = '
  ' . get_sql_condition_FandF(
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
        $user['expand'],
        $filter['enabled']
    );
    $where = is_string($where) ? $where : '';

    $query .= '
WHERE ' . $where . '
;';

    $result = pwg_query($query);
    $cats = [];
    $selected_category = $category_page;
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
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
                    is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0,
                    is_numeric($row['count_images']) ? (int) $row['count_images'] : 0,
                    is_numeric($row['count_categories']) ? (int) $row['count_categories'] : 0,
                    false,
                    ' / '
                ),
                'URL' => make_index_url([
                    'category' => $row,
                ]),
                'LEVEL' => substr_count((string) $row['global_rank'], '.') + 1,
                'SELECTED' => ($selected_category !== null && ($selected_category['id'] ?? null) == $row['id']) ? true : false,
                'IS_UPPERCAT' => ($selected_category !== null && ($selected_category['id_uppercat'] ?? null) == $row['id']) ? true : false,
            ]
        );
        if ((bool) $conf['index_new_icon']) {
            $max_date_last = $row['max_date_last'];
            $row['icon_ts'] = get_icon(is_string($max_date_last) ? $max_date_last : '', $child_date_last);
        }
        $cats[] = $row;
        if ($category_page !== null && ($category_page['id'] ?? null) == $row['id']) { // save the number of subcats for later optim
            if (is_array($page['category'] ?? null)) {
                $page['category']['count_categories'] = $row['count_categories'];
            }
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
 * @return array<string, mixed>|null
 */
function get_cat_info($id): ?array
{
    $query = '
SELECT *
  FROM ' . Tables::categories() . '
  WHERE id = ' . $id . '
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
    FROM ' . Tables::categories() . '
    WHERE id IN (' . $cat['uppercats'] . ')
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
 * @return array<int, array{0: string, 1: string, 2: bool}>
 */
function get_category_preferred_image_orders(): array
{
    /** @var array<string, mixed> $conf */
    global $conf, $page;

    $orders = trigger_change('get_category_preferred_image_orders', [
        [l10n('Default'),                        '',                     true],
        [l10n('Photo title, A &rarr; Z'),        'name ASC',             true],
        [l10n('Photo title, Z &rarr; A'),        'name DESC',            true],
        [l10n('Date created, new &rarr; old'),   'date_creation DESC',   true],
        [l10n('Date created, old &rarr; new'),   'date_creation ASC',    true],
        [l10n('Date posted, new &rarr; old'),    'date_available DESC',  true],
        [l10n('Date posted, old &rarr; new'),    'date_available ASC',   true],
        [l10n('Rating score, high &rarr; low'),  'rating_score DESC',    $conf['rate']],
        [l10n('Rating score, low &rarr; high'),  'rating_score ASC',     $conf['rate']],
        [l10n('Visits, high &rarr; low'),        'hit DESC',             true],
        [l10n('Visits, low &rarr; high'),        'hit ASC',              true],
        [l10n('Permissions'),                    'level DESC',           is_admin()],
    ]);

    if (! is_array($orders)) {
        return [];
    }

    $result = [];
    foreach ($orders as $order) {
        if (! is_array($order) || ! isset($order[0], $order[1], $order[2]) || ! is_string($order[0]) || ! is_string($order[1])) {
            continue;
        }
        $visible = $order[2];
        $visible = is_scalar($visible) ? (bool) $visible : true;
        $result[] = [$order[0], $order[1], $visible];
    }

    return $result;
}

/**
 * Assign a template var useable with {html_options} from a list of categories
 *
 * @param array<int, array<string, mixed>> $categories (at least id,name,global_rank,uppercats for each)
 * @param array<int, mixed> $selecteds
 * @param string $blockname variable name in template
 * @param bool $fullname full breadcrumb or not
 */
function display_select_categories(
    $categories,
    $selecteds,
    $blockname,
    $fullname = true
): void {
    /** @var Template $template */
    global $template;

    $tpl_cats = [];
    foreach ($categories as $category) {
        if ($fullname) {
            $uppercats = $category['uppercats'];
            $option = strip_tags(
                get_cat_display_name_cache(
                    is_string($uppercats) ? $uppercats : '',
                    null
                )
            );
        } else {
            $global_rank = $category['global_rank'];
            $option = str_repeat(
                '&nbsp;',
                (3 * substr_count(is_string($global_rank) ? $global_rank : '', '.'))
            );
            $option .= '- ';
            $rendered_name = trigger_change(
                'render_category_name',
                $category['name'],
                'display_select_categories'
            );
            $option .= strip_tags(is_string($rendered_name) ? $rendered_name : '');
        }
        $id = $category['id'];
        if (is_int($id) || is_string($id)) {
            $tpl_cats[$id] = $option;
        }
    }

    $template->assign($blockname, $tpl_cats);
    $template->assign($blockname . '_selected', $selecteds);
}

/**
 * Same as display_select_categories but categories are ordered by rank
 * @see display_select_categories()
 * @param array<int, mixed> $selecteds
 * @param string $blockname variable name in template
 * @param bool $fullname full breadcrumb or not
 */
function display_select_cat_wrapper(
    string $query,
    $selecteds,
    $blockname,
    $fullname = true
): void {
    $categories = query2array($query);
    usort($categories, global_rank_compare(...));
    display_select_categories($categories, $selecteds, $blockname, $fullname);
}

/**
 * Returns all subcategory identifiers of given category ids
 *
 * @param array<int|string> $ids several callers (comments.php, admin/rating.php)
 *   wrap a raw, unvalidated $_GET value directly — the is_numeric() check
 *   below is a real guard, not dead code
 * @return list<int> array_values() below always reindexes the result
 */
function get_subcat_ids($ids): array
{
    $query = '
SELECT DISTINCT(id)
  FROM ' . Tables::categories() . '
  WHERE ';
    foreach ($ids as $num => $category_id) {
        is_numeric($category_id)
          or trigger_error(
              'get_subcat_ids expecting numeric, not ' . gettype($category_id),
              E_USER_WARNING
          );
        if ($num > 0) {
            $query .= '
    OR ';
        }
        $query .= 'uppercats ' . DB_REGEX_OPERATOR . ' \'(^|,)' . $category_id . '(,|$)\'';
    }
    $query .= '
;';
    $row_ids = query2array($query, null, 'id');

    return array_values(array_map(
        static fn (string $id): int => (int) $id,
        array_filter($row_ids, is_numeric(...))
    ));
}

/**
 * Finds a matching category id from a potential list of permalinks
 *
 * @param string[] $permalinks
 * @param int $idx filled with the index in $permalinks that matches
 */
function get_cat_id_from_permalinks(array $permalinks, &$idx): ?int
{
    $in = '';
    foreach ($permalinks as $permalink) {
        if (! empty($in)) {
            $in .= ', ';
        }
        $in .= '\'' . $permalink . '\'';
    }
    $query = '
SELECT cat_id AS id, permalink, 1 AS is_old
  FROM ' . Tables::oldPermalinks() . '
  WHERE permalink IN (' . $in . ')
UNION
SELECT id, permalink, 0 AS is_old
  FROM ' . Tables::categories() . '
  WHERE permalink IN (' . $in . ')
;';
    $perma_hash = query2array($query, 'permalink');

    if (empty($perma_hash)) {
        return null;
    }
    for ($i = count($permalinks) - 1; $i >= 0; $i--) {
        if (isset($perma_hash[$permalinks[$i]])) {
            $idx = $i;
            $cat_id_raw = $perma_hash[$permalinks[$i]]['id'];
            if (! is_numeric($cat_id_raw)) {
                return null;
            }
            $cat_id = (int) $cat_id_raw;
            if ((bool) $perma_hash[$permalinks[$i]]['is_old']) {
                $query = '
UPDATE ' . Tables::oldPermalinks() . ' SET last_hit=NOW(), hit=hit+1
  WHERE permalink=\'' . $permalinks[$i] . '\' AND cat_id=' . $cat_id . '
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
 * @param string $separator
 */
function get_display_images_count($cat_nb_images, $cat_count_images, $cat_count_categories, $short_message = true, $separator = '\n'): string
{
    $display_text = '';

    if ($cat_count_images > 0) {
        if ($cat_nb_images > 0 and $cat_nb_images < $cat_count_images) {
            $display_text .= get_display_images_count($cat_nb_images, $cat_nb_images, 0, $short_message, $separator) . $separator;
            $cat_count_images -= $cat_nb_images;
            $cat_nb_images = 0;
        }

        // at least one image direct or indirect
        $display_text .= l10n_dec('%d photo', '%d photos', $cat_count_images);

        if ($cat_count_categories == 0 or $cat_nb_images == $cat_count_images) {
            // no descendant categories or descendants do not contain images
            if (! $short_message) {
                $display_text .= ' ' . l10n('in this album');
            }
        } else {
            $display_text .= ' ' . l10n_dec('in %d sub-album', 'in %d sub-albums', $cat_count_categories);
        }
    }

    return $display_text;
}

/**
 * Find a random photo among all photos inside an album (including sub-albums)
 *
 * @param array<string, mixed> $category (at least id,uppercats,count_images)
 * @param bool $recursive
 */
function get_random_image_in_category(array $category, $recursive = true): ?int
{
    $image_id = null;
    if ($category['count_images'] > 0) {
        $cat_id = $category['id'];
        $cat_id = is_numeric($cat_id) ? (int) $cat_id : 0;
        $uppercats = $category['uppercats'];
        $uppercats = is_string($uppercats) ? $uppercats : '';

        $query = '
SELECT image_id
  FROM ' . Tables::categories() . ' AS c
    INNER JOIN ' . Tables::imageCategory() . ' AS ic ON ic.category_id = c.id
  WHERE ';
        if ($recursive) {
            $query .= '
    (c.id=' . $cat_id . ' OR uppercats LIKE \'' . $uppercats . ',%\')';
        } else {
            $query .= '
    c.id=' . $cat_id;
        }
        $query .= '
    ' . get_sql_condition_FandF(
            [
                'forbidden_categories' => 'c.id',
                'visible_categories' => 'c.id',
                'visible_images' => 'image_id',
            ],
            "\n  AND"
        ) . '
  ORDER BY ' . DB_RANDOM_FUNCTION . '()
  LIMIT 1
;';
        $result = pwg_query($query);
        if (pwg_db_num_rows($result) > 0) {
            $row = pwg_db_fetch_row($result);
            if ($row !== null && is_numeric($row[0] ?? null)) {
                $image_id = (int) $row[0];
            }
        }
    }

    return $image_id;
}

/**
 * Get computed array of categories, that means cache data of all categories
 * available for the current user (count_categories, count_images, etc.).
 *
 * @param array<string, mixed> $userdata
 * @param int $filter_days number of recent days to filter on or null
 * @return array<int|string, array<string, mixed>>
 */
function get_computed_categories(array &$userdata, $filter_days = null): array
{
    $level = $userdata['level'];
    $level = is_numeric($level) ? (int) $level : 0;

    $query = 'SELECT c.id AS cat_id, id_uppercat';
    $query .= ', global_rank';
    // Count by date_available to avoid count null
    $query .= ',
  MAX(date_available) AS date_last, COUNT(date_available) AS nb_images
FROM ' . Tables::categories() . ' as c
  LEFT JOIN ' . Tables::imageCategory() . ' AS ic ON ic.category_id = c.id
  LEFT JOIN ' . Tables::images() . ' AS i
    ON ic.image_id = i.id
      AND i.level<=' . $level;

    if (isset($filter_days)) {
        $query .= ' AND i.date_available > ' . pwg_db_get_recent_period_expression($filter_days);
    }

    $forbidden_categories = $userdata['forbidden_categories'];
    if (is_string($forbidden_categories) && ! empty($forbidden_categories)) {
        $query .= '
  WHERE c.id NOT IN (' . $forbidden_categories . ')';
    }

    $query .= '
  GROUP BY c.id';

    $result = pwg_query($query);

    $userdata['last_photo_date'] = null;
    $cats = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $cat_id = is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0;
        $id_uppercat_raw = $row['id_uppercat'];
        $id_uppercat = is_numeric($id_uppercat_raw) ? (int) $id_uppercat_raw : null;
        $nb_images = is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0;
        $date_last = $row['date_last'];

        $row['cat_id'] = $cat_id;
        $row['id_uppercat'] = $id_uppercat;
        $row['nb_images'] = $nb_images;
        $row['user_id'] = $userdata['id'];
        $row['nb_categories'] = 0;
        $row['count_categories'] = 0;
        $row['count_images'] = $nb_images;
        $row['max_date_last'] = $date_last;
        if ($date_last !== null && ($userdata['last_photo_date'] === null || $date_last > $userdata['last_photo_date'])) {
            $userdata['last_photo_date'] = $date_last;
        }

        $cats[$cat_id] = $row;
    }

    // it is important to logically sort the albums because some operations
    // (like removal) rely on this logical order. Child album doesn't always
    // have a bigger id than its parent (if it was moved afterwards).
    uasort($cats, global_rank_compare(...));

    foreach ($cats as $cat) {
        // 'id_uppercat' is always int|null here, set explicitly above from
        // is_numeric($id_uppercat_raw) ? (int) $id_uppercat_raw : null.
        $id_uppercat = $cat['id_uppercat'];
        if (! is_int($id_uppercat)) {
            continue;
        }

        // Piwigo before 2.5.3 may have generated inconsistent permissions, ie
        // private album A1/A2 permitted to user U1 but private album A1 not
        // permitted to U1.
        //
        // TODO 2.7: add an upgrade script to repair permissions and remove this
        // test
        if (! isset($cats[$id_uppercat])) {
            continue;
        }

        $parent = &$cats[$id_uppercat];
        $parent['nb_categories']++;

        // 'nb_images' is always int here, set explicitly above.
        $nb_images = $cat['nb_images'];

        do {
            $parent['count_images'] += $nb_images;
            $parent['count_categories']++;

            if ((empty($parent['max_date_last'])) or ($parent['max_date_last'] < $cat['date_last'])) {
                $parent['max_date_last'] = $cat['date_last'];
            }

            // 'id_uppercat' is always int|null here, same as above.
            $parent_id_uppercat = $parent['id_uppercat'];
            if (! is_int($parent_id_uppercat)) {
                break;
            }
            $parent = &$cats[$parent_id_uppercat];
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
 * @param array<int|string, array<string, mixed>> $cats
 * @param array<string, mixed> $cat category to remove
 */
function remove_computed_category(array &$cats, array $cat): void
{
    $id_uppercat = $cat['id_uppercat'] ?? null;
    if ((is_int($id_uppercat) || is_string($id_uppercat)) && isset($cats[$id_uppercat])) {
        $parent = &$cats[$id_uppercat];

        $nb_categories = $parent['nb_categories'] ?? null;
        $parent['nb_categories'] = (is_numeric($nb_categories) ? (int) $nb_categories : 0) - 1;

        do {
            $count_images = $parent['count_images'] ?? null;
            $nb_images = $cat['nb_images'] ?? null;
            $parent['count_images'] = (is_numeric($count_images) ? (int) $count_images : 0)
                - (is_numeric($nb_images) ? (int) $nb_images : 0);

            $count_categories = $parent['count_categories'] ?? null;
            $cat_count_categories = $cat['count_categories'] ?? null;
            $parent['count_categories'] = (is_numeric($count_categories) ? (int) $count_categories : 0)
                - (1 + (is_numeric($cat_count_categories) ? (int) $cat_count_categories : 0));

            $parent_id_uppercat = $parent['id_uppercat'] ?? null;
            if (! (is_int($parent_id_uppercat) || is_string($parent_id_uppercat)) || ! isset($cats[$parent_id_uppercat])) {
                break;
            }
            $parent = &$cats[$parent_id_uppercat];
        } while (true);
    }

    $cat_id_key = $cat['cat_id'] ?? null;
    if (is_int($cat_id_key) || is_string($cat_id_key)) {
        unset($cats[$cat_id_key]);
    }
}

/**
 * Return the list of image ids corresponding to given categories.
 * AND & OR mode supported.
 *
 * @param int[] $cat_ids
 * @param string $mode
 * @param string $extra_images_where_sql - optionally apply a sql where filter to retrieved images
 * @param string $order_by - optionally overwrite default photo order
 * @return array<int, int|string>
 */
function get_image_ids_for_categories($cat_ids, $mode = 'AND', $extra_images_where_sql = '', $order_by = '', bool $use_permissions = true): array
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if (empty($cat_ids)) {
        return [];
    }

    $query = '
SELECT id
  FROM ' . Tables::images() . ' i
    INNER JOIN ' . Tables::imageCategory() . ' ic ON id=ic.image_id
  WHERE category_id IN (' . implode(',', $cat_ids) . ')';

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

    $query .= (empty($extra_images_where_sql) ? '' : " \nAND (" . $extra_images_where_sql . ')') . '
  GROUP BY id';

    if ($mode == 'AND' and count($cat_ids) > 1) {
        $query .= '
  HAVING COUNT(DISTINCT category_id)=' . count($cat_ids);
    }
    $order_by_conf = $conf['order_by'] ?? null;
    $order_by_conf_str = is_scalar($order_by_conf) ? (string) $order_by_conf : '';
    $query .= "\n" . (empty($order_by) ? $order_by_conf_str : $order_by);

    $ids = query2array($query, null, 'id');

    return array_values(array_map(
        static fn (string $id): int => (int) $id,
        array_filter($ids, is_numeric(...))
    ));
}

/**
 * Return a list of categories corresponding to given items.
 *
 * @param int[] $items
 * @param int $max
 * @param int[] $excluded_cat_ids
 * @return array<int|string, array<string, mixed>> [id, name, counter, url_name]
 */
function get_common_categories($items, $max = null, $excluded_cat_ids = [], bool $use_permissions = true): array
{
    if (empty($items)) {
        return [];
    }

    $query = '
SELECT
    c.id,
    c.uppercats,
    count(*) AS counter
  FROM ' . Tables::imageCategory() . '
    INNER JOIN ' . Tables::categories() . ' c ON category_id = id
  WHERE image_id IN (' . implode(',', $items) . ')';

    if ($use_permissions) {
        $query .= get_sql_condition_FandF(
            [
                'forbidden_categories' => 'category_id',
                'visible_categories' => 'category_id',
            ],
            "\n    AND"
        );
    }

    if (! empty($excluded_cat_ids)) {
        $query .= '
    AND category_id NOT IN (' . implode(',', $excluded_cat_ids) . ')';
    }

    $query .= '
  GROUP BY c.id
  ORDER BY ';
    if (isset($max)) {
        $query .= 'counter DESC
  LIMIT ' . $max;
    } else {
        $query .= 'NULL';
    }

    $result = pwg_query($query);
    $cats = [];
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $id = $row['id'];
        if (is_string($id)) {
            $cats[$id] = $row;
        }
    }

    return $cats;
}

/**
 * @param array<int, int|string> $items
 * @param array<int, int|string> $excluded_cat_ids
 * @return mixed[]
 */
function get_related_categories_menu($items, $excluded_cat_ids = []): array
{
    /**
     * @var array<string, mixed> $page
     * @var array<string, mixed> $conf
     */
    global $page, $conf;

    $related_albums_display_limit = $conf['related_albums_display_limit'] ?? null;
    $related_albums_display_limit_int = is_numeric($related_albums_display_limit) ? (int) $related_albums_display_limit : null;

    $common_cats = get_common_categories(
        array_map(intval(...), $items),
        $related_albums_display_limit_int,
        array_map(intval(...), $excluded_cat_ids)
    );
    // echo '<pre>'; print_r($common_cats); echo '</pre>';

    if (count($common_cats) == 0) {
        return [];
    }

    $cat_ids = [];
    // now we add the upper categories and useful values such as depth level and url
    foreach ($common_cats as $cat) {
        $uppercats = $cat['uppercats'];
        foreach (explode(',', is_string($uppercats) ? $uppercats : '') as $uppercat) {
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
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', array_keys($cat_ids)) . ')
;';
    $cats = query2array($query);
    usort($cats, global_rank_compare(...));

    $index_of_cat = [];

    foreach ($cats as $idx => $cat) {
        $cat_id = $cat['id'];
        if (is_string($cat_id)) {
            $index_of_cat[$cat_id] = $idx;
        }
        $cats[$idx]['LEVEL'] = substr_count((string) $cat['global_rank'], '.') + 1;
        $cats[$idx]['name'] = trigger_change('render_category_name', $cat['name'], $cat);

        // if the category is directly linked to the items, we add an URL + counter
        if (is_string($cat_id) && isset($common_cats[$cat_id])) {
            $cats[$idx]['count_images'] = $common_cats[$cat_id]['counter'];

            $url_params = [];
            if (isset($page['category'])) {
                $url_params['category'] = $page['category'];

                $url_params['combined_categories'] = [$cat];
                $combined_categories = $page['combined_categories'] ?? null;
                if (is_array($combined_categories)) {
                    $url_params['combined_categories'] = array_merge($combined_categories, [$cat]);
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
        if (! empty($cat['id_uppercat']) and @$cats[$idx]['count_images'] > 0) {
            foreach (array_slice(explode(',', (string) $cat['uppercats']), 0, -1) as $uppercat_id) {
                $parent_idx = $index_of_cat[$uppercat_id] ?? null;
                if (! is_int($parent_idx)) {
                    continue;
                }
                $count_categories = $cats[$parent_idx]['count_categories'] ?? null;
                $cats[$parent_idx]['count_categories'] = (is_numeric($count_categories) ? (int) $count_categories : 0) + 1;
            }
        }
    }

    return $cats;
}
