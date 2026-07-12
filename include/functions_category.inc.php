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
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\Template;

/**
 * Callback used for sorting by global_rank
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function global_rank_compare(array $a, array $b): int
{
    return CategoryService::compareByGlobalRank($a, $b);
}

/**
 * Callback used for sorting by rank
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function rank_compare(array $a, array $b): int
{
    return CategoryService::compareByRank($a, $b);
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
    $user_id_int = is_numeric($user_id) ? (int) $user_id : 0;

    // category currently displayed, if any; narrowed once here and reused
    // at every nested-offset access site below (see fix pattern #4/#7)
    $category_page_raw = $page['category'] ?? null;
    $category_page = is_array($category_page_raw) ? $category_page_raw : null;

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

    $conn = DbConnection::build();
    $rows = new CategoryRepository($conn)
        ->findMenuCategories($user_id_int, $where);

    $cats = [];
    $selected_category = $category_page;
    foreach ($rows as $row) {
        // both sides get coerced to string for comparison: $row['id'] is
        // always a DB-fetch string, but $page['category']['id'] may already
        // be an int depending on how that array was populated -- matches
        // the original's loose ==, which PHPStan disallows outright.
        $row_id = $row['id'] ?? null;
        $row_id_str = is_scalar($row_id) ? (string) $row_id : null;
        $row_global_rank = $row['global_rank'] ?? null;
        $child_date_last = @$row['max_date_last'] > @$row['date_last'];
        $selected_id = $selected_category['id'] ?? null;
        $selected_id_str = is_scalar($selected_id) ? (string) $selected_id : null;
        $selected_id_uppercat = $selected_category['id_uppercat'] ?? null;
        $selected_id_uppercat_str = is_scalar($selected_id_uppercat) ? (string) $selected_id_uppercat : null;
        $row = array_merge(
            $row,
            [
                'NAME' => trigger_change(
                    'render_category_name',
                    $row['name'],
                    'get_categories_menu'
                ),
                'TITLE' => CategoryService::getDisplayImagesCount(
                    is_numeric($row['nb_images']) ? (int) $row['nb_images'] : 0,
                    is_numeric($row['count_images']) ? (int) $row['count_images'] : 0,
                    is_numeric($row['count_categories']) ? (int) $row['count_categories'] : 0,
                    false,
                    ' / '
                ),
                'URL' => make_index_url([
                    'category' => $row,
                ]),
                'LEVEL' => substr_count(is_scalar($row_global_rank) ? (string) $row_global_rank : '', '.') + 1,
                'SELECTED' => $selected_category !== null && $selected_id_str !== null && $selected_id_str === $row_id_str,
                'IS_UPPERCAT' => $selected_category !== null && $selected_id_uppercat_str !== null && $selected_id_uppercat_str === $row_id_str,
            ]
        );
        if ((bool) $conf['index_new_icon']) {
            $max_date_last = $row['max_date_last'] ?? null;
            $row['icon_ts'] = get_icon(is_string($max_date_last) ? $max_date_last : '', $child_date_last);
        }
        $cats[] = $row;
        $category_page_id = $category_page['id'] ?? null;
        $category_page_id_str = is_scalar($category_page_id) ? (string) $category_page_id : null;
        if ($category_page !== null && $category_page_id_str !== null && $category_page_id_str === $row_id_str) { // save the number of subcats for later optim
            if (is_array($page['category'] ?? null)) {
                $page['category']['count_categories'] = $row['count_categories'] ?? null;
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
    $conn = DbConnection::build();

    return new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->getCategoryInfo($id);
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
    $conn = DbConnection::build();

    return new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->getPreferredImageOrders();
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
    // Non-numeric values are only warned about, never embedded into the
    // query -- CategoryRepository::findSubcategoryIds() binds each id as a
    // parameter, so (unlike the pre-port version, which spliced $category_id
    // straight into a REGEXP string literal after only warning about it) a
    // non-numeric value can no longer reach raw SQL.
    $validated_ids = [];
    foreach ($ids as $category_id) {
        if (is_numeric($category_id)) {
            $validated_ids[] = (int) $category_id;
            continue;
        }

        trigger_error(
            'get_subcat_ids expecting numeric, not ' . gettype($category_id),
            E_USER_WARNING
        );
    }

    return new CategoryRepository(DbConnection::build())->findSubcategoryIds($validated_ids);
}

/**
 * Finds a matching category id from a potential list of permalinks
 *
 * @param  list<string>  $permalinks
 * @param  int|null  $idx  filled with the index in $permalinks that
 *   matches, only when a match is found (same as the return value: read
 *   $idx only after checking the return isn't null)
 */
function get_cat_id_from_permalinks(array $permalinks, ?int &$idx): ?int
{
    $conn = DbConnection::build();

    return new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->findCategoryIdFromPermalinks($permalinks, $idx);
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
    return CategoryService::getDisplayImagesCount(
        $cat_nb_images,
        $cat_count_images,
        $cat_count_categories,
        $short_message,
        $separator
    );
}

/**
 * Find a random photo among all photos inside an album (including sub-albums)
 *
 * @param array<string, mixed> $category (at least id,uppercats,count_images)
 * @param bool $recursive
 */
function get_random_image_in_category(array $category, $recursive = true): ?int
{
    $conn = DbConnection::build();

    return new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->getRandomImageInCategory($category, $recursive);
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
    $conn = DbConnection::build();

    return new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->getComputedCategories($userdata, $filter_days);
}

/**
 * Removes a category from computed array of categories and updates counters.
 *
 * @param array<int|string, array<string, mixed>> $cats
 * @param array<string, mixed> $cat category to remove
 */
function remove_computed_category(array &$cats, array $cat): void
{
    CategoryService::removeComputedCategory($cats, $cat);
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
    $conn = DbConnection::build();

    return new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->getImageIdsForCategories(
        array_values(array_map(intval(...), $cat_ids)),
        $mode,
        $extra_images_where_sql,
        $order_by,
        $use_permissions
    );
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
    if ($items === []) {
        return [];
    }

    $conn = DbConnection::build();

    return new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->getCommonCategories(
        array_values(array_map(intval(...), $items)),
        $max,
        array_values(array_map(intval(...), $excluded_cat_ids)),
        $use_permissions
    );
}

/**
 * @param array<int, int|string> $items
 * @param array<int, int|string> $excluded_cat_ids
 * @return mixed[]
 */
function get_related_categories_menu($items, $excluded_cat_ids = []): array
{
    /** @var array<string, mixed> $page */
    global $page;

    $conn = DbConnection::build();
    $cats = new CategoryService(
        new CategoryRepository($conn),
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
    )->getRelatedCategoriesMenu(
        array_values(array_map(intval(...), $items)),
        array_values(array_map(intval(...), $excluded_cat_ids))
    );

    // page-URL decoration -- CategoryService::getRelatedCategoriesMenu()
    // sets 'count_images' but never 'url' (real $page coupling, L2a can't
    // depend on Url/Html), so it's added here for every row the service
    // marked as directly linked to the items (same condition it used:
    // 'count_images' present).
    //
    // NOTE: 'combined_categories' below carries $cat AFTER the service's
    // own 'render_category_name' trigger_change() already ran on 'name'
    // (the original built this array BEFORE that render), so
    // UrlService::makeIndexUrl()'s id-name style would embed the
    // *rendered* name instead of the raw one if a 'render_category_name'
    // plugin handler is ever registered (none are today -- PEM extensions
    // are unwired, trigger_change() is a same-value pass-through -- so
    // this is currently a no-op difference). Re-verify once P31 wires
    // real event handlers.
    foreach ($cats as $idx => $cat) {
        if (! isset($cat['count_images'])) {
            continue;
        }

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

    return $cats;
}
