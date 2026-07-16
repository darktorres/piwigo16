<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Category\CategoryService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Users\UserRepository;

// Relocated from the now-deleted admin/photos_add.php (P23 batch 8a):
// Piwigo\Admin\CoreTabs::addCoreTabs() (formerly admin/include/
// add_core_tabs.inc.php's add_core_tabs(), P23 batch 8b-6) and
// Piwigo\Admin\PhotosAddDirectPageRenderer both read this constant, and
// this file is already include_once'd before either of them ever runs
// (PhotosAddSubController::handle() loads this file first) -- can't
// define() it in src/Piwigo/ itself (SEC-60 Arch rule).
if (! defined('PHOTOS_ADD_BASE_URL')) {
    define('PHOTOS_ADD_BASE_URL', get_root_url() . 'admin.php?page=photos_add');
}

/**
 * Deletes a site and call delete_categories for each primary category of the site
 *
 * @param int $id
 */
function delete_site($id): void
{
    // destruction of the categories of the site
    $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE site_id = ' . $id . '
;';
    $category_ids = array_map(intval(...), query2array($query, null, 'id'));
    delete_categories($category_ids);

    // destruction of the site
    $query = '
DELETE FROM ' . Tables::sites() . '
  WHERE id = ' . $id . '
;';
    pwg_query($query);
}

/**
 * Recursively deletes one or more categories.
 * It also deletes :
 *    - all the elements physically linked to the category (with delete_elements)
 *    - all the links between elements and this category
 *    - all the restrictions linked to the category
 *
 * @param array<int, int> $ids
 * @param string $photo_deletion_mode
 *    - no_delete : delete no photo, may create orphans
 *    - delete_orphans : delete photos that are no longer linked to any category
 *    - force_delete : delete photos even if they are linked to another category
 */
function delete_categories($ids, $photo_deletion_mode = 'no_delete'): void
{
    if (count($ids) == 0) {
        return;
    }

    // add sub-category ids to the given ids : if a category is deleted, all
    // sub-categories must be so
    $ids = get_subcat_ids($ids);

    $imageConn = DbConnection::build();
    $imageService = new ImageService(new ImageRepository($imageConn), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($imageConn)));

    // destruction of all photos physically linked to the category
    $query = '
SELECT id
  FROM ' . Tables::images() . '
  WHERE storage_category_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;';
    $element_ids = array_map(intval(...), query2array($query, null, 'id'));
    $imageService->deleteElements($element_ids);

    // now, should we delete photos that are virtually linked to the category?
    if ($photo_deletion_mode == 'delete_orphans' or $photo_deletion_mode == 'force_delete') {
        $query = '
SELECT
    DISTINCT(image_id)
  FROM ' . Tables::imageCategory() . '
  WHERE category_id IN (' . implode(',', $ids) . ')
;';
        $image_ids_linked = query2array($query, null, 'image_id');

        if (count($image_ids_linked) > 0) {
            if ($photo_deletion_mode == 'delete_orphans') {
                $query = '
SELECT
    DISTINCT(image_id)
  FROM ' . Tables::imageCategory() . '
  WHERE image_id IN (' . implode(',', $image_ids_linked) . ')
    AND category_id NOT IN (' . implode(',', $ids) . ')
;';
                $image_ids_not_orphans = query2array($query, null, 'image_id');
                $image_ids_to_delete = array_diff($image_ids_linked, $image_ids_not_orphans);
            }

            if ($photo_deletion_mode == 'force_delete') {
                $image_ids_to_delete = $image_ids_linked;
            }

            $imageService->deleteElements(array_map(intval(...), $image_ids_to_delete), true);
        }
    }

    // destruction of the links between images and this category
    $query = '
DELETE FROM ' . Tables::imageCategory() . '
  WHERE category_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;';
    pwg_query($query);

    // destruction of the access linked to the category
    $query = '
DELETE FROM ' . Tables::userAccess() . '
  WHERE cat_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;';
    pwg_query($query);

    $query = '
DELETE FROM ' . Tables::groupAccess() . '
  WHERE cat_id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;';
    pwg_query($query);

    // destruction of the category
    $query = '
DELETE FROM ' . Tables::categories() . '
  WHERE id IN (
' . wordwrap(implode(', ', $ids), 80, "\n") . ')
;';
    pwg_query($query);

    $query = '
DELETE FROM ' . Tables::oldPermalinks() . '
  WHERE cat_id IN (' . implode(',', $ids) . ')';
    pwg_query($query);

    $query = '
DELETE FROM ' . Tables::userCacheCategories() . '
  WHERE cat_id IN (' . implode(',', $ids) . ')';
    pwg_query($query);

    trigger_notify('delete_categories', $ids);
    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('album', $ids, 'delete', [
        'photo_deletion_mode' => $photo_deletion_mode,
    ]);
}

/**
 * Verifies that the representative picture really exists in the db and
 * picks up a random representative if possible and based on config.
 *
 * @param 'all'|int|array<int|string> $ids ws_functions/pwg.images.php passes
 *   preg_match()-validated but never int-cast category id strings; $ids only
 *   ever flows into implode()/SQL contexts below, so numeric strings work
 *   identically
 */
function update_category($ids = 'all'): ?false
{
    /** @var array<string, mixed> $conf */
    global $conf;

    if ($ids == 'all') {
        $where_cats = '1=1';
    } elseif (! is_array($ids)) {
        $where_cats = '%s=' . $ids;
    } else {
        if (count($ids) == 0) {
            return false;
        }
        $where_cats = '%s IN(' . wordwrap(implode(', ', $ids), 120, "\n") . ')';
    }

    // find all categories where the setted representative is not possible :
    // the picture does not exist
    $query = '
SELECT DISTINCT c.id
  FROM ' . Tables::categories() . ' AS c LEFT JOIN ' . Tables::images() . ' AS i
    ON c.representative_picture_id = i.id
  WHERE representative_picture_id IS NOT NULL
    AND ' . sprintf($where_cats, 'c.id') . '
    AND i.id IS NULL
;';
    $wrong_representant = query2array($query, null, 'id');

    if (count($wrong_representant) > 0) {
        $query = '
UPDATE ' . Tables::categories() . '
  SET representative_picture_id = NULL
  WHERE id IN (' . wordwrap(implode(', ', $wrong_representant), 120, "\n") . ')
;';
        pwg_query($query);
    }

    if (! (bool) $conf['allow_random_representative']) {
        // If the random representant is not allowed, we need to find
        // categories with elements and with no representant. Those categories
        // must be added to the list of categories to set to a random
        // representant.
        $query = '
SELECT DISTINCT id
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::imageCategory() . '
    ON id = category_id
  WHERE representative_picture_id IS NULL
    AND ' . sprintf($where_cats, 'category_id') . '
;';
        $to_rand = array_map(intval(...), query2array($query, null, 'id'));
        if (count($to_rand) > 0) {
            set_random_representant($to_rand);
        }
    }

    return null;
}

/**
 * Checks and repairs integrity on categories.
 * Removes all entries from related tables which correspond to a deleted category.
 */
function categories_integrity(): void
{
    $related_columns = [
        Tables::imageCategory() . '.category_id',
        Tables::userAccess() . '.cat_id',
        Tables::groupAccess() . '.cat_id',
        Tables::oldPermalinks() . '.cat_id',
        Tables::userCacheCategories() . '.cat_id',
    ];

    foreach ($related_columns as $fullcol) {
        [$table, $column] = explode('.', $fullcol);

        $query = '
SELECT
    ' . $column . '
  FROM ' . $table . '
    LEFT JOIN ' . Tables::categories() . ' ON id = ' . $column . '
  WHERE id IS NULL
;';
        $orphans = array_unique(query2array($query, null, $column));

        if (count($orphans) > 0) {
            $query = '
DELETE
  FROM ' . $table . '
  WHERE ' . $column . ' IN (' . implode(',', $orphans) . ')
;';
            pwg_query($query);
        }
    }
}

/**
 * save the rank depending on given categories order
 *
 * The list of ordered categories id is supposed to be in the same parent
 * category
 *
 * @param array<int, mixed> $categories
 */
function save_categories_order($categories): void
{
    $current_rank_for_id_uppercat = [];
    $current_rank = 0;

    $datas = [];
    foreach ($categories as $category) {
        if (is_array($category)) {
            $id = $category['id'];
            $id_uppercat = $category['id_uppercat'];
            if (! is_int($id_uppercat) && ! is_string($id_uppercat)) {
                // id_uppercat is null (or otherwise non-scalar) for top-level
                // categories; bucket them together like the '' sentinel used
                // for $current_uppercat in update_global_rank() below.
                $id_uppercat = '';
            }

            if (! isset($current_rank_for_id_uppercat[$id_uppercat])) {
                $current_rank_for_id_uppercat[$id_uppercat] = 0;
            }
            $current_rank = ++$current_rank_for_id_uppercat[$id_uppercat];
        } else {
            $id = $category;
            $current_rank++;
        }

        $datas[] = [
            'id' => $id,
            'rank' => $current_rank,
        ];
    }
    $fields = [
        'primary' => ['id'],
        'update' => ['rank'],
    ];
    mass_updates(Tables::categories(), $fields, $datas);

    update_global_rank();
}

/**
 * Orders categories (update categories.rank and global_rank database fields)
 * so that rank field are consecutive integers starting at 1 for each child.
 */
function update_global_rank(): int
{
    $query = '
SELECT id, id_uppercat, uppercats, `rank`, global_rank
  FROM ' . Tables::categories() . '
  ORDER BY id_uppercat, `rank`, name';

    global $cat_map; // used in preg_replace callback
    $cat_map = [];

    $current_rank = 0;
    $current_uppercat = '';

    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        if ($row['id_uppercat'] != $current_uppercat) {
            $current_rank = 0;
            $current_uppercat = $row['id_uppercat'];
        }
        ++$current_rank;

        $row_id = $row['id'];
        $row_uppercats = $row['uppercats'];
        // id and uppercats are NOT NULL columns in the categories table.
        assert(is_string($row_id) && is_string($row_uppercats));

        $cat =
          [
              'rank' => $current_rank,
              'rank_changed' => $current_rank != $row['rank'],
              'global_rank' => $row['global_rank'],
              'uppercats' => $row_uppercats,
          ];
        $cat_map[$row_id] = $cat;
    }

    $datas = [];

    $cat_map_callback = function (array $m) use ($cat_map): string {
        $matched_id = $m[1] ?? null;
        if (! is_string($matched_id) || ! isset($cat_map[$matched_id])) {
            return '';
        }
        return (string) $cat_map[$matched_id]['rank'];
    };

    foreach ($cat_map as $id => $cat) {
        $new_global_rank = preg_replace_callback(
            '/(\d+)/',
            $cat_map_callback,
            str_replace(',', '.', $cat['uppercats'])
        );

        if ($cat['rank_changed'] or $new_global_rank !== $cat['global_rank']) {
            $datas[] = [
                'id' => $id,
                'rank' => $cat['rank'],
                'global_rank' => $new_global_rank,
            ];
        }
    }

    unset($cat_map);

    mass_updates(
        Tables::categories(),
        [
            'primary' => ['id'],
            'update' => ['rank', 'global_rank'],
        ],
        $datas
    );
    return count($datas);
}

/**
 * Change the **visible** property on a set of categories.
 *
 * @param int[] $categories
 * @param bool|string $value
 * @param bool $unlock_child optional   default false
 */
function set_cat_visible($categories, $value, $unlock_child = false): ?false
{
    if (($value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) === null) {
        trigger_error("set_cat_visible invalid param {$value}", E_USER_WARNING);
        return false;
    }

    // unlocking a category => all its parent categories become unlocked
    if ($value) {
        $cats = get_uppercat_ids($categories);
        if ($unlock_child) {
            $cats = array_merge($cats, get_subcat_ids($categories));
        }
        $query = '
UPDATE ' . Tables::categories() . '
  SET visible = \'true\'
  WHERE id IN (' . implode(',', $cats) . ')';
        pwg_query($query);
    }
    // locking a category   => all its child categories become locked
    else {
        $subcats = get_subcat_ids($categories);
        $query = '
UPDATE ' . Tables::categories() . '
  SET visible = \'false\'
  WHERE id IN (' . implode(',', $subcats) . ')';
        pwg_query($query);
    }

    return null;
}

/**
 * Change the **status** property on a set of categories : private or public.
 *
 * @param int[] $categories
 * @param string $value
 */
function set_cat_status($categories, $value): ?false
{
    if (! in_array($value, ['public', 'private'])) {
        trigger_error("set_cat_status invalid param {$value}", E_USER_WARNING);
        return false;
    }

    // make public a category => all its parent categories become public
    if ($value == 'public') {
        $uppercats = get_uppercat_ids($categories);
        $query = '
UPDATE ' . Tables::categories() . '
  SET status = \'public\'
  WHERE id IN (' . implode(',', $uppercats) . ')
;';
        pwg_query($query);
    }

    // make a category private => all its child categories become private
    if ($value == 'private') {
        $subcats = get_subcat_ids($categories);

        $query = '
UPDATE ' . Tables::categories() . '
  SET status = \'private\'
  WHERE id IN (' . implode(',', $subcats) . ')';
        pwg_query($query);

        // We have to keep permissions consistant: a sub-album can't be
        // permitted to a user or group if its parent album is not permitted to
        // the same user or group. Let's remove all permissions on sub-albums if
        // it is not consistant. Let's take the following example:
        //
        // A1        permitted to U1,G1
        // A1/A2     permitted to U1,U2,G1,G2
        // A1/A2/A3  permitted to U3,G1
        // A1/A2/A4  permitted to U2
        // A1/A5     permitted to U4
        // A6        permitted to U4
        // A6/A7     permitted to G1
        //
        // (we consider that it can be possible to start with inconsistant
        // permission, given that public albums can have hidden permissions,
        // revealed once the album returns to private status)
        //
        // The admin selects A2,A3,A4,A5,A6,A7 to become private (all but A1,
        // which is private, which can be true if we're moving A2 into A1). The
        // result must be:
        //
        // A2 permission removed to U2,G2
        // A3 permission removed to U3
        // A4 permission removed to U2
        // A5 permission removed to U2
        // A6 permission removed to U4
        // A7 no permission removed
        //
        // 1) we must extract "top albums": A2, A5 and A6
        // 2) for each top album, decide which album is the reference for permissions
        // 3) remove all inconsistant permissions from sub-albums of each top-album

        // step 1, search top albums
        $top_categories = [];
        $parent_ids = [];

        $query = '
SELECT
    id,
    name,
    id_uppercat,
    uppercats,
    global_rank
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $categories) . ')
;';
        $all_categories = query2array($query);
        usort($all_categories, CategoryService::compareByGlobalRank(...));

        foreach ($all_categories as $cat) {
            $is_top = true;

            if (! empty($cat['id_uppercat'])) {
                foreach (explode(',', (string) $cat['uppercats']) as $id_uppercat) {
                    if (isset($top_categories[$id_uppercat])) {
                        $is_top = false;
                        break;
                    }
                }
            }

            if ($is_top) {
                $cat_id = $cat['id'];
                assert(is_string($cat_id));
                $top_categories[$cat_id] = $cat;

                if (! empty($cat['id_uppercat'])) {
                    $parent_ids[] = $cat['id_uppercat'];
                }
            }
        }

        // step 2, search the reference album for permissions
        //
        // to find the reference of each top album, we will need the parent albums
        $parent_cats = [];

        if (count($parent_ids) > 0) {
            $query = '
SELECT
    id,
    status
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $parent_ids) . ')
;';
            $parent_cats = query2array($query, 'id');
        }

        $tables = [
            Tables::userAccess() => 'user_id',
            Tables::groupAccess() => 'group_id',
        ];

        foreach ($top_categories as $top_category) {
            // what is the "reference" for list of permissions? The parent album
            // if it is private, else the album itself
            $top_category_id = $top_category['id'];
            assert(is_string($top_category_id));
            $ref_cat_id = $top_category_id;

            if (! empty($top_category['id_uppercat'])
                and isset($parent_cats[$top_category['id_uppercat']])
                and $parent_cats[$top_category['id_uppercat']]['status'] == 'private') {
                $ref_cat_id = $top_category['id_uppercat'];
            }

            $subcats = get_subcat_ids([$top_category_id]);

            foreach ($tables as $table => $field) {
                // what are the permissions user/group of the reference album
                $query = '
SELECT ' . $field . '
  FROM ' . $table . '
  WHERE cat_id = ' . $ref_cat_id . '
;';
                $ref_access = query2array($query, null, $field);

                if (count($ref_access) == 0) {
                    $ref_access[] = -1;
                }

                // step 3, remove the inconsistant permissions from sub-albums
                $query = '
DELETE
  FROM ' . $table . '
  WHERE ' . $field . ' NOT IN (' . implode(',', $ref_access) . ')
    AND cat_id IN (' . implode(',', $subcats) . ')
;';
                pwg_query($query);
            }
        }
    }

    return null;
}

/**
 * Returns all uppercats category ids of the given category ids.
 *
 * @param int[] $cat_ids
 * @return int[]
 */
function get_uppercat_ids($cat_ids): array
{
    if (count($cat_ids) < 1) {
        return [];
    }

    $uppercats = [];

    $query = '
SELECT uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $cat_ids) . ')
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $uppercats = array_merge(
            $uppercats,
            array_map(intval(...), explode(',', (string) $row['uppercats']))
        );
    }
    $uppercats = array_unique($uppercats);

    return $uppercats;
}

/**
 * @return array{src: string|array<int|string, mixed>, url: string}
 */
function get_category_representant_properties(int|string $image_id, ?string $size = null): array
{
    $query = '
SELECT id,representative_ext,path
  FROM ' . Tables::images() . '
  WHERE id = ' . $image_id . '
;';

    $row = pwg_db_fetch_assoc(pwg_query($query));
    if ($row === false || $row === null) {
        throw new Exception("get_category_representant_properties(): image {$image_id} does not exist (stale representative_picture_id?)");
    }
    if ($size == null) {
        $src = DerivativeImage::thumb_url($row);
    } else {
        $src = DerivativeImage::url($size, $row);
    }
    $url = get_root_url() . 'admin.php?page=photo-' . $image_id;

    return [
        'src' => $src,
        'url' => $url,
    ];
}

/**
 * Set a new random representant to the categories.
 *
 * @param int[] $categories
 */
function set_random_representant($categories): void
{
    $datas = [];
    foreach ($categories as $category_id) {
        $query = '
SELECT image_id
  FROM ' . Tables::imageCategory() . '
  WHERE category_id = ' . $category_id . '
  ORDER BY ' . DB_RANDOM_FUNCTION . '()
  LIMIT 1
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        $representative = $row !== null ? $row[0] : null;

        $datas[] = [
            'id' => $category_id,
            'representative_picture_id' => $representative,
        ];
    }

    mass_updates(
        Tables::categories(),
        [
            'primary' => ['id'],
            'update' => ['representative_picture_id'],
        ],
        $datas
    );
}

/**
 * Returns the fulldir for each given category id.
 *
 * @param int[] $cat_ids intcat_ids
 * @return string[]
 */
function get_fulldirs($cat_ids): array
{
    if (count($cat_ids) == 0) {
        return [];
    }

    // caching directories of existing categories
    global $cat_dirs; // used in preg_replace callback
    $query = '
SELECT id, dir
  FROM ' . Tables::categories() . '
  WHERE dir IS NOT NULL
;';
    // dir is filtered to NOT NULL above; drop any stray null defensively so
    // $cat_dirs is safe to use as a string lookup in the callback below.
    $cat_dirs = array_filter(query2array($query, 'id', 'dir'), is_string(...));

    // caching galleries_url
    $query = '
SELECT id, galleries_url
  FROM ' . Tables::sites() . '
;';
    // galleries_url is NOT NULL default '' in the schema; filter defensively.
    $galleries_url = array_filter(query2array($query, 'id', 'galleries_url'), is_string(...));

    // categories : id, site_id, uppercats
    $query = '
SELECT id, uppercats, site_id
  FROM ' . Tables::categories() . '
  WHERE dir IS NOT NULL
    AND id IN (
' . wordwrap(implode(', ', $cat_ids), 80, "\n") . ')
;';
    $categories = query2array($query);

    // filling $cat_fulldirs
    $cat_dirs_callback = function (array $m) use ($cat_dirs): string {
        $matched_id = $m[1] ?? null;
        return (is_string($matched_id) && isset($cat_dirs[$matched_id])) ? $cat_dirs[$matched_id] : '';
    };

    $cat_fulldirs = [];
    foreach ($categories as $category) {
        $cat_id = $category['id'];
        $site_id = $category['site_id'];
        $category_uppercats = $category['uppercats'];
        // id and uppercats are NOT NULL columns; site_id is always populated
        // when a category is created (defaults to the local site).
        assert(is_string($cat_id) && is_string($site_id) && is_string($category_uppercats));

        $uppercats = str_replace(',', '/', $category_uppercats);
        $cat_fulldirs[$cat_id] = $galleries_url[$site_id];
        $cat_fulldirs[$cat_id] .= preg_replace_callback(
            '/(\d+)/',
            $cat_dirs_callback,
            $uppercats
        );
    }

    unset($cat_dirs);

    return $cat_fulldirs;
}

/**
 * Synchronize base users list and related users list.
 *
 * Compares and synchronizes base users table (Tables::users()) with its child
 * tables (Tables::userInfos(), USER_ACCESS, USER_CACHE, USER_GROUP) : each
 * base user must be present in child tables, users in child tables not
 * present in base table must be deleted.
 */
function sync_users(): void
{
    /** @var array<string, mixed> $conf */
    global $conf;

    $user_fields = $conf['user_fields'];
    $user_id_field = is_array($user_fields) && is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';
    $query = '
SELECT ' . $user_id_field . ' AS id
  FROM ' . Tables::users() . '
;';
    $base_users = array_map(intval(...), query2array($query, null, 'id'));

    $query = '
SELECT user_id
  FROM ' . Tables::userInfos() . '
;';
    $infos_users = array_map(intval(...), query2array($query, null, 'user_id'));

    // users present in $base_users and not in $infos_users must be added
    $to_create = array_diff($base_users, $infos_users);

    if (count($to_create) > 0) {
        (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))))->createUserInfos($to_create);
    }

    // users present in user related tables must be present in the base user
    // table
    $tables = [
        Tables::userMailNotification(),
        Tables::userFeed(),
        Tables::userInfos(),
        Tables::userAccess(),
        Tables::userCache(),
        Tables::userCacheCategories(),
        Tables::userGroup(),
    ];

    foreach ($tables as $table) {
        $query = '
SELECT DISTINCT user_id
  FROM ' . $table . '
;';
        $to_delete = array_diff(
            query2array($query, null, 'user_id'),
            $base_users
        );

        if (count($to_delete) > 0) {
            $query = '
DELETE
  FROM ' . $table . '
  WHERE user_id in (' . implode(',', $to_delete) . ')
;';
            pwg_query($query);
        }
    }
}

/**
 * Updates categories.uppercats field based on categories.id + categories.id_uppercat
 */
function update_uppercats(): void
{
    $query = '
SELECT id, id_uppercat, uppercats
  FROM ' . Tables::categories() . '
;';
    $cat_map = query2array($query, 'id');

    $datas = [];
    foreach ($cat_map as $id => $cat) {
        $upper_list = [];

        $uppercat = $id;
        while ((bool) $uppercat) {
            $upper_list[] = $uppercat;
            $uppercat = $cat_map[$uppercat]['id_uppercat'];
        }

        $new_uppercats = implode(',', array_reverse($upper_list));
        if ($new_uppercats != $cat['uppercats']) {
            $datas[] = [
                'id' => $id,
                'uppercats' => $new_uppercats,
            ];
        }
    }
    $fields = [
        'primary' => ['id'],
        'update' => ['uppercats'],
    ];
    mass_updates(Tables::categories(), $fields, $datas);
}

/**
 * Update images.path field base on images.file and storage categories fulldirs.
 */
function update_path(): void
{
    $query = '
SELECT DISTINCT(storage_category_id)
  FROM ' . Tables::images() . '
  WHERE storage_category_id IS NOT NULL
;';
    $cat_ids = array_map(intval(...), query2array($query, null, 'storage_category_id'));
    $fulldirs = get_fulldirs($cat_ids);

    foreach ($cat_ids as $cat_id) {
        $query = '
UPDATE ' . Tables::images() . '
  SET path = ' . pwg_db_concat(["'" . $fulldirs[$cat_id] . "/'", 'file']) . '
  WHERE storage_category_id = ' . $cat_id . '
;';
        pwg_query($query);
    }
}

/**
 * Change the parent category of the given categories. The categories are
 * supposed virtual.
 *
 * @param array<int, int> $category_ids
 * @param int $new_parent (-1 for root)
 */
function move_categories($category_ids, $new_parent = -1): void
{
    /** @var array<string, mixed> $page */
    global $page;

    // $page['errors']/$page['infos'] are always initialized to an array by
    // common.inc.php, but that isn't visible across the include() boundary
    // -- narrow them once here so the appends below type-check.
    $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];
    $page['infos'] = is_array($page['infos'] ?? null) ? $page['infos'] : [];

    if (count($category_ids) == 0) {
        return;
    }

    $new_parent = $new_parent < 1 ? 'NULL' : $new_parent;

    $categories = [];

    $query = '
SELECT id, id_uppercat, status, uppercats
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        $row_id = $row['id'];
        assert(is_string($row_id));

        $categories[$row_id] =
          [
              'parent' => empty($row['id_uppercat']) ? 'NULL' : $row['id_uppercat'],
              'status' => $row['status'],
              'uppercats' => $row['uppercats'],
          ];
    }

    // is the movement possible? The movement is impossible if you try to move
    // a category in a sub-category or itself
    if ($new_parent != 'NULL') {
        $query = '
SELECT uppercats
  FROM ' . Tables::categories() . '
  WHERE id = ' . $new_parent . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$new_parent_uppercats] = $row;
        assert(is_string($new_parent_uppercats));

        foreach ($categories as $category) {
            // technically, you can't move a category with uppercats 12,125,13,14
            // into a new parent category with uppercats 12,125,13,14,24
            if ((bool) preg_match('/^' . $category['uppercats'] . '(,|$)/', $new_parent_uppercats)) {
                $page['errors'][] = l10n('You cannot move an album in its own sub album');
                return;
            }
        }
    }

    $tables = [
        Tables::userAccess() => 'user_id',
        Tables::groupAccess() => 'group_id',
    ];

    $query = '
UPDATE ' . Tables::categories() . '
  SET id_uppercat = ' . $new_parent . '
  WHERE id IN (' . implode(',', $category_ids) . ')
;';
    pwg_query($query);

    update_uppercats();
    update_global_rank();

    // status and related permissions management
    if ($new_parent == 'NULL') {
        $parent_status = 'public';
    } else {
        $query = '
SELECT status
  FROM ' . Tables::categories() . '
  WHERE id = ' . $new_parent . '
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        assert($row !== null);
        [$parent_status] = $row;
    }

    if ($parent_status == 'private') {
        set_cat_status(array_map(intval(...), array_keys($categories)), 'private');
    }

    $page['infos'][] = l10n_dec(
        '%d album moved',
        '%d albums moved',
        count($categories)
    );

    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('album', $category_ids, 'move', [
        'parent' => $new_parent,
    ]);
}

/**
 * Create a virtual category.
 *
 * @param string $category_name
 * @param int|string|null $parent_id ws_categories_add() passes null by
 *   default (WS_TYPE_INT param, unset by the caller), admin/cat_list.php
 *   passes a raw, unvalidated $_GET['parent_id'] string
 * @param array{commentable?: mixed, visible?: mixed, status?: mixed, comment?: mixed, inherit?: mixed} $options
 *   values are validated internally (is_bool()/==), not trusted from callers
 * @return array{error: string}|array{info: string, id: int|string}
 */
function create_virtual_category($category_name, $parent_id = null, array $options = []): array
{
    /**
     * @var array<string, mixed> $conf
     * @var array<string, mixed> $user
     */
    global $conf, $user;

    // is the given category name only containing blank spaces ?
    if ((bool) preg_match('/^\s*$/', $category_name)) {
        return [
            'error' => l10n('The name of an album must not be empty'),
        ];
    }

    $rank = 0;
    if ($conf['newcat_default_position'] == 'last') {
        // what is the current higher rank for this parent?
        $query = '
SELECT MAX(`rank`) AS max_rank
  FROM ' . Tables::categories() . '
  WHERE id_uppercat ' . (empty($parent_id) ? 'IS NULL' : '= ' . $parent_id) . '
;';
        $row = pwg_db_fetch_assoc(pwg_query($query));

        if ($row !== false && $row !== null && is_numeric($row['max_rank'])) {
            $rank = $row['max_rank'] + 1;
        }
    }

    $insert = [
        'name' => $category_name,
        'rank' => $rank,
        'global_rank' => 0,
        // Otherwise relies on the schema's own DEFAULT CURRENT_TIMESTAMP,
        // which reads the real DB-server clock -- invisible to pwg_now()'s
        // PIWIGO_TEST_NOW freeze.
        'lastmodified' => pwg_now()
            ->format('Y-m-d H:i:s'),
    ];

    // is the album commentable?
    if (isset($options['commentable']) and is_bool($options['commentable'])) {
        $insert['commentable'] = $options['commentable'];
    } else {
        $insert['commentable'] = $conf['newcat_default_commentable'];
    }
    $insert['commentable'] = boolean_to_string($insert['commentable']);

    // is the album temporarily locked? (only visible by administrators,
    // whatever permissions) (may be overwritten if parent album is not
    // visible)
    if (isset($options['visible']) and is_bool($options['visible'])) {
        $insert['visible'] = $options['visible'];
    } else {
        $insert['visible'] = $conf['newcat_default_visible'];
    }
    $insert['visible'] = boolean_to_string($insert['visible']);

    // is the album private? (may be overwritten if parent album is private)
    if (isset($options['status']) and $options['status'] == 'private') {
        $insert['status'] = 'private';
    } else {
        $insert['status'] = $conf['newcat_default_status'];
    }

    // any description for this album?
    if (isset($options['comment'])) {
        $comment = is_scalar($options['comment']) ? (string) $options['comment'] : '';
        $insert['comment'] = ((bool) $conf['allow_html_descriptions']) ? $options['comment'] : strip_tags($comment);
    }

    if (! empty($parent_id) and is_numeric($parent_id)) {
        $query = '
SELECT id, uppercats, global_rank, visible, status
  FROM ' . Tables::categories() . '
  WHERE id = ' . $parent_id . '
;';
        $parent = pwg_db_fetch_assoc(pwg_query($query));
        if ($parent === false || $parent === null) {
            return [
                'error' => l10n('The parent album does not exist'),
            ];
        }

        $insert['id_uppercat'] = $parent['id'];
        $insert['global_rank'] = $parent['global_rank'] . '.' . $insert['rank'];

        // at creation, must a category be visible or not ? Warning : if the
        // parent category is invisible, the category is automatically create
        // invisible. (invisible = locked)
        if ($parent['visible'] == 'false') {
            $insert['visible'] = 'false';
        }

        // at creation, must a category be public or private ? Warning : if the
        // parent category is private, the category is automatically create
        // private.
        if ($parent['status'] == 'private') {
            $insert['status'] = 'private';
        }

        $uppercats_prefix = $parent['uppercats'] . ',';
    } else {
        $uppercats_prefix = '';
    }

    // we have then to add the virtual category
    single_insert(Tables::categories(), $insert);
    $inserted_id = pwg_db_insert_id();

    single_update(
        Tables::categories(),
        [
            'uppercats' => $uppercats_prefix . $inserted_id,
            // This UPDATE is an unconditional, immediate follow-up to the
            // INSERT above (needs the auto-generated id first) -- part of
            // the same logical "create category" operation, not a later,
            // independent edit. Re-set explicitly, since ON UPDATE
            // CURRENT_TIMESTAMP would otherwise silently overwrite the
            // INSERT's own frozen lastmodified with the real DB-server
            // clock the moment this UPDATE runs.
            'lastmodified' => pwg_now()
                ->format('Y-m-d H:i:s'),
        ],
        [
            'id' => $inserted_id,
        ]
    );

    update_global_rank();

    if ($insert['status'] == 'private' and ! empty($insert['id_uppercat']) and ((isset($options['inherit']) and (bool) $options['inherit']) or (bool) $conf['inheritance_by_default'])) {
        $query = '
      SELECT group_id
      FROM ' . Tables::groupAccess() . '
      WHERE cat_id = ' . $insert['id_uppercat'] . '
    ;';
        $granted_grps = query2array($query, null, 'group_id');
        $inserts = [];
        foreach ($granted_grps as $granted_grp) {
            $inserts[] = [
                'group_id' => $granted_grp,
                'cat_id' => $inserted_id,
            ];
        }
        mass_inserts(Tables::groupAccess(), ['group_id', 'cat_id'], $inserts);

        $query = '
      SELECT user_id
      FROM ' . Tables::userAccess() . '
      WHERE cat_id = ' . $insert['id_uppercat'] . '
    ;';
        $granted_users = array_map(intval(...), query2array($query, null, 'user_id'));
        $conn = DbConnection::build();
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
            ->addPermissionOnCategory((int) $inserted_id, $granted_users);
    } elseif ($insert['status'] == 'private') {
        $current_user_id = $user['id'];
        $current_user_id = is_numeric($current_user_id) ? (int) $current_user_id : 0;
        $conn = DbConnection::build();
        $admin_ids = new UserRepository($conn)
            ->findAdminIds();
        new PermissionService(new PermissionRepository($conn), new GroupRepository($conn))
            ->addPermissionOnCategory((int) $inserted_id, array_unique(array_merge($admin_ids, [$current_user_id])));
    }

    trigger_notify('create_virtual_category', array_merge([
        'id' => $inserted_id,
    ], $insert));
    (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('album', $inserted_id, 'add');

    return [
        'info' => l10n('Album added'),
        'id' => $inserted_id,
    ];
}

/**
 * Is the category accessible to the (Admin) user ?
 * Note : if the user is not authorized to see this category, category jump
 * will be replaced by admin cat_modify page
 *
 * @param int $category_id
 */
function cat_admin_access($category_id): bool
{
    /** @var array<string, mixed> $user */
    global $user;

    // $filter['visible_categories'] and $filter['visible_images']
    // are not used because it's not necessary (filter <> restriction)
    $forbidden_categories = $user['forbidden_categories'];
    $forbidden_categories = is_string($forbidden_categories) ? $forbidden_categories : '';
    if (in_array($category_id, @explode(',', $forbidden_categories))) {
        return false;
    }
    return true;
}
