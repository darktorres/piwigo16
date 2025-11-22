<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\admin\inc;

use DateInterval;
use DateTime;
use Piwigo\inc\Config;
use Piwigo\inc\derivative_params;
use Piwigo\inc\derivative_std_params;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_mail;
use Piwigo\inc\functions_notification;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\inc\ImageStdParams;
use Random\RandomException;

final class functions_admin
{
    /**
     * Deletes a site and call delete_categories for each primary category of the site
     */
    public static function delete_site(
        int $id
    ): void {
        global $conf;

        // destruction of the categories of the site
        $query = <<<SQL
            SELECT id
            FROM categories
            WHERE site_id = {$id};
            SQL;
        $category_ids = $conf->sql_backend::query2array($query, null, 'id');
        self::delete_categories($category_ids);

        // destruction of the site
        $query = <<<SQL
            DELETE FROM sites
            WHERE id = {$id};
            SQL;
        $conf->sql_backend::pwg_query($query);
    }

    /**
     * Recursively deletes one or more categories.
     * It also deletes :
     *    - all the elements physically linked to the category (with delete_elements)
     *    - all the links between elements and this category
     *    - all the restrictions linked to the category
     *
     * @param array<int> $ids
     *    - no_delete : delete no photo, may create orphans
     *    - delete_orphans : delete photos that are no longer linked to any category
     *    - force_delete : delete photos even if they are linked to another category
     */
    public static function delete_categories(
        array $ids,
        string $photo_deletion_mode = 'no_delete'
    ): void {
        global $conf;

        if (count($ids) == 0) {
            return;
        }

        // add sub-category ids to the given ids : if a category is deleted, all
        // sub-categories must be so
        $ids = functions_category::get_subcat_ids($ids);

        // destruction of all photos physically linked to the category
        $ids_str = implode(', ', $ids);
        $wrapped_ids = wordwrap($ids_str, 80);
        $query = <<<SQL
            SELECT id
            FROM images
            WHERE storage_category_id IN ({$wrapped_ids});
            SQL;
        $element_ids = $conf->sql_backend::query2array($query, null, 'id');
        self::delete_elements($element_ids);

        // now, should we delete photos that are virtually linked to the category?
        if ($photo_deletion_mode === 'delete_orphans' ||
            $photo_deletion_mode === 'force_delete'
        ) {
            $ids_str = implode(', ', $ids);
            $query = <<<SQL
                SELECT DISTINCT image_id
                FROM image_category
                WHERE category_id IN ({$ids_str});
                SQL;
            $image_ids_linked = $conf->sql_backend::query2array($query, null, 'image_id');

            if ($image_ids_linked !== []) {
                if ($photo_deletion_mode === 'delete_orphans') {
                    $image_ids_list = implode(', ', $image_ids_linked);
                    $category_ids_list = implode(', ', $ids);
                    $query = <<<SQL
                        SELECT DISTINCT image_id
                        FROM image_category
                        WHERE image_id IN ({$image_ids_list})
                            AND category_id NOT IN ({$category_ids_list});
                        SQL;
                    $image_ids_not_orphans = $conf->sql_backend::query2array($query, null, 'image_id');
                    $image_ids_to_delete = array_diff($image_ids_linked, $image_ids_not_orphans);
                }

                if ($photo_deletion_mode === 'force_delete') {
                    $image_ids_to_delete = $image_ids_linked;
                }

                self::delete_elements($image_ids_to_delete, true);
            }
        }

        // destruction of the links between images and this category
        $category_ids_list = wordwrap(implode(', ', $ids), 80);
        $query = <<<SQL
            DELETE FROM image_category
            WHERE category_id IN ({$category_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the access linked to the category
        $cat_ids_list = wordwrap(implode(', ', $ids), 80);
        $query = <<<SQL
            DELETE FROM user_access
            WHERE cat_id IN ({$cat_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        $cat_ids_list = wordwrap(implode(', ', $ids), 80);
        $query = <<<SQL
            DELETE FROM group_access
            WHERE cat_id IN ({$cat_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the category
        $category_ids_list = wordwrap(implode(', ', $ids), 80);
        $query = <<<SQL
            DELETE FROM categories
            WHERE id IN ({$category_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        $cat_ids_list = implode(', ', $ids);
        $query = <<<SQL
            DELETE FROM old_permalinks
            WHERE cat_id IN ({$cat_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        $cat_ids_list = implode(', ', $ids);
        $query = <<<SQL
            DELETE FROM user_cache_categories
            WHERE cat_id IN ({$cat_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        functions_plugins::trigger_notify('delete_categories', $ids);
        functions::pwg_activity('album', $ids, 'delete', [
            'photo_deletion_mode' => $photo_deletion_mode,
        ]);
    }

    /**
     * Deletes all files (on disk) related to given image ids.
     *
     * @param array<int> $ids
     * @return array<int>|int image ids where files were successfully deleted
     */
    public static function delete_element_files(
        array $ids
    ): array|int {
        global $conf;

        if (count($ids) == 0) {
            return 0;
        }

        $new_ids = [];
        $formats_of = [];

        $image_ids_list = implode(', ', $ids);
        $query = <<<SQL
            SELECT image_id, ext
            FROM image_format
            WHERE image_id IN ({$image_ids_list});
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            if (! isset($formats_of[$row['image_id']])) {
                $formats_of[$row['image_id']] = [];
            }

            $formats_of[$row['image_id']][] = $row['ext'];
        }

        $image_ids_list = implode(', ', $ids);
        $query = <<<SQL
            SELECT id, path, representative_ext
            FROM images
            WHERE id IN ({$image_ids_list});
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            if (functions_url::url_is_remote($row['path'])) {
                continue;
            }

            $files = [];
            $files[] = functions::get_element_path($row);

            if (! empty($row['representative_ext'])) {
                $files[] = functions::original_to_representative($files[0], $row['representative_ext']);
            }

            if (isset($formats_of[$row['id']])) {
                foreach ($formats_of[$row['id']] as $format_ext) {
                    $files[] = functions::original_to_format($files[0], $format_ext);
                }
            }

            $ok = true;

            if ($conf->never_delete_originals === null) {
                foreach ($files as $path) {
                    if (is_file($path) &&
                        ! unlink($path)
                    ) {
                        $ok = false;
                        trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                        break;
                    }
                }
            }

            if ($ok) {
                self::delete_element_derivatives($row);
                $new_ids[] = $row['id'];
            } else {
                break;
            }
        }

        return $new_ids;
    }

    /**
     * Deletes elements from database.
     * It also deletes :
     *    - all the comments related to elements
     *    - all the links between categories/tags and elements
     *    - all the favorites/rates associated to elements
     *    - removes elements from caddie
     *
     * @param array<int> $ids
     * @return int number of deleted elements
     */
    public static function delete_elements(
        array $ids,
        bool $physical_deletion = false
    ): int {
        global $conf;

        if (count($ids) == 0) {
            return 0;
        }

        functions_plugins::trigger_notify('begin_delete_elements', $ids);

        if ($physical_deletion) {
            $ids = self::delete_element_files($ids);

            if (count($ids) == 0) {
                return 0;
            }
        }

        $ids_str = wordwrap(implode(', ', $ids), 80);

        // destruction of the comments on the image
        $query = <<<SQL
            DELETE FROM comments
            WHERE image_id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the links between images and categories
        $query = <<<SQL
            DELETE FROM image_category
            WHERE image_id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the formats
        $query = <<<SQL
            DELETE FROM image_format
            WHERE image_id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the links between images and tags
        $query = <<<SQL
            DELETE FROM image_tag
            WHERE image_id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the favorites associated with the picture
        $query = <<<SQL
            DELETE FROM favorites
            WHERE image_id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the rates associated to this element
        $query = <<<SQL
            DELETE FROM rate
            WHERE element_id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the caddie associated to this element
        $query = <<<SQL
            DELETE FROM caddie
            WHERE element_id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the image
        $query = <<<SQL
            DELETE FROM images
            WHERE id IN ({$ids_str});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // are the photo used as category representative?
        $query = <<<SQL
            SELECT id
            FROM categories
            WHERE representative_picture_id IN ({$ids_str});
            SQL;
        $category_ids = $conf->sql_backend::query2array($query, null, 'id');

        if ($category_ids !== []) {
            self::update_category($category_ids);
        }

        functions_plugins::trigger_notify('delete_elements', $ids);
        functions::pwg_activity('photo', $ids, 'delete');
        return count($ids);
    }

    /**
     * Deletes an user.
     * It also deletes all related data (accesses, favorites, permissions, etc.)
     * @todo : accept array input
     */
    public static function delete_user(
        int $user_id
    ): void {
        global $conf;
        $tables = [
            // destruction of the access linked to the user
            'user_access',
            // destruction of data notification by mail for this user
            'user_mail_notification',
            // destruction of data RSS notification for this user
            'user_feed',
            // deletion of calculated permissions linked to the user
            'user_cache',
            // deletion of computed cache data linked to the user
            'user_cache_categories',
            // destruction of the group links for this user
            'user_group',
            // destruction of the favorites associated with the user
            'favorites',
            // destruction of the caddie associated with the user
            'caddie',
            // deletion of piwigo specific information
            'user_infos',
            'user_auth_keys',
        ];

        foreach ($tables as $table) {
            $query = <<<SQL
                DELETE FROM {$table}
                WHERE user_id = {$user_id};
                SQL;
            $conf->sql_backend::pwg_query($query);
        }

        // purge of sessions
        functions_session::delete_user_sessions($user_id);

        // destruction of the user
        $query = <<<SQL
            DELETE FROM users
            WHERE {$conf->user_fields['id']} = {$user_id};
            SQL;
        $conf->sql_backend::pwg_query($query);

        functions_plugins::trigger_notify('delete_user', $user_id);
        functions::pwg_activity('user', $user_id, 'delete');
    }

    /**
     * Deletes all tags linked to no photo
     */
    public static function delete_orphan_tags(): void
    {
        $orphan_tags = self::get_orphan_tags();

        if ($orphan_tags !== []) {
            $orphan_tag_ids = [];

            foreach ($orphan_tags as $tag) {
                $orphan_tag_ids[] = $tag['id'];
            }

            self::delete_tags($orphan_tag_ids);
        }
    }

    /**
     * Get all tags (id + name) linked to no photo
     */
    public static function get_orphan_tags(): array
    {
        global $conf;

        $query = <<<SQL
            SELECT id, name
            FROM tags
            LEFT JOIN image_tag ON id = tag_id
            WHERE tag_id IS NULL

            SQL;

        if ($conf->dblayer === 'mysqli') {
            $query .= 'AND lastmodified < SUBDATE(NOW(), INTERVAL 1 DAY);';
        }

        if ($conf->dblayer === 'pgsql') {
            $query .= "AND lastmodified < NOW() - INTERVAL '1 DAY';";
        }

        return $conf->sql_backend::query2array($query);
    }

    /**
     * Verifies that the representative picture really exists in the db and
     * picks up a random representative if possible and based on config.
     *
     * @param string|int|array<int> $ids
     */
    public static function update_category(
        string|int|array $ids = 'all'
    ): ?bool {
        global $conf;

        if ($ids == 'all') {
            $where_cats = '1 = 1';
        } elseif (! is_array($ids)) {
            $where_cats = "%s = {$ids}";
        } else {
            if (count($ids) == 0) {
                return false;
            }

            $where_cats = '%s IN (' . wordwrap(implode(', ', $ids), 120) . ')';
        }

        // find all categories where the set representative is not possible :
        // the picture does not exist
        $where_cats_condition = sprintf($where_cats, 'c.id');
        $query = <<<SQL
            SELECT DISTINCT c.id
            FROM categories AS c
            LEFT JOIN images AS i ON c.representative_picture_id = i.id
            WHERE representative_picture_id IS NOT NULL
                AND {$where_cats_condition}
                AND i.id IS NULL;
            SQL;
        $wrong_representative = $conf->sql_backend::query2array($query, null, 'id');

        if ($wrong_representative !== []) {
            $wrong_representative_list = wordwrap(implode(', ', $wrong_representative), 120);
            $query = <<<SQL
                UPDATE categories
                SET representative_picture_id = NULL
                WHERE id IN ({$wrong_representative_list});
                SQL;
            $conf->sql_backend::pwg_query($query);
        }

        if (! $conf->allow_random_representative) {
            // If the random representative is not allowed, we need to find
            // categories with elements and with no representative. Those categories
            // must be added to the list of categories to set to a random
            // representative.
            $where_cats_condition = sprintf($where_cats, 'category_id');
            $query = <<<SQL
                SELECT DISTINCT id
                FROM categories INNER JOIN image_category ON id = category_id
                WHERE representative_picture_id IS NULL
                    AND {$where_cats_condition};
                SQL;
            $to_rand = $conf->sql_backend::query2array($query, null, 'id');

            if ($to_rand !== []) {
                self::set_random_representative($to_rand);
            }
        }

        return null;
    }

    /**
     * Checks and repairs image_category integrity.
     * Removes all entries from the table which correspond to a deleted image.
     */
    public static function images_integrity(): void
    {
        global $conf;

        $query = <<<SQL
            SELECT image_id
            FROM image_category
            LEFT JOIN images ON id = image_id
            WHERE id IS NULL;
            SQL;
        $orphan_image_ids = $conf->sql_backend::query2array($query, null, 'image_id');

        if ($orphan_image_ids !== []) {
            $orphan_image_ids_list = implode(', ', $orphan_image_ids);
            $query = <<<SQL
                DELETE FROM image_category
                WHERE image_id IN ({$orphan_image_ids_list});
                SQL;
            $conf->sql_backend::pwg_query($query);
        }
    }

    /**
     * Checks and repairs integrity on categories.
     * Removes all entries from related tables which correspond to a deleted category.
     */
    public static function categories_integrity(): void
    {
        global $conf;

        $related_columns = [
            'image_category.category_id',
            'user_access.cat_id',
            'group_access.cat_id',
            'old_permalinks.cat_id',
            'user_cache_categories.cat_id',
        ];

        foreach ($related_columns as $fullcol) {
            [$table, $column] = explode('.', $fullcol);

            $query = <<<SQL
                SELECT {$column}
                FROM {$table}
                LEFT JOIN categories ON id = {$column}
                WHERE id IS NULL;
                SQL;
            $orphans = array_unique($conf->sql_backend::query2array($query, null, $column));

            if ($orphans !== []) {
                $orphans_list = implode(', ', $orphans);
                $query = <<<SQL
                    DELETE FROM {$table}
                    WHERE {$column} IN ({$orphans_list});
                    SQL;
                $conf->sql_backend::pwg_query($query);
            }
        }
    }

    /**
     * Returns an array containing sub-directories which are potentially
     * a category.
     * Directories named ".svn", "thumbnail", "pwg_high" or "pwg_representative"
     * are omitted.
     *
     * @param string $path (eg: ./galleries)
     * @return array<string>
     */
    public static function get_fs_directories(
        string $path,
        bool $recursive = true
    ): array {
        global $conf;

        $dirs = [];
        $path = rtrim($path, '/');

        $exclude_folders = array_merge(
            $conf->sync_exclude_folders,
            [
                '.', '..', '.svn',
                'thumbnail', 'pwg_high',
                'pwg_representative',
                'pwg_format',
            ]
        );
        $exclude_folders = array_flip($exclude_folders);

        if (is_dir($path)) {
            $contents = opendir($path);

            if ($contents) {
                while (($node = readdir($contents)) !== false) {
                    if (is_dir($path . '/' . $node) &&
                        ! isset($exclude_folders[$node])
                    ) {
                        $dirs[] = $path . '/' . $node;

                        if ($recursive) {
                            $dirs = array_merge($dirs, self::get_fs_directories($path . '/' . $node));
                        }
                    }
                }

                closedir($contents);
            }
        }

        return $dirs;
    }

    /**
     * save the rank depending on given categories order
     *
     * The list of ordered categories id is supposed to be in the same parent
     * category
     */
    public static function save_categories_order(
        array $categories
    ): void {
        global $conf;

        $current_rank_for_id_uppercat = [];
        $current_rank = 0;

        $datas = [];

        foreach ($categories as $category) {
            if (is_array($category)) {
                $id = $category['id'];
                $id_uppercat = $category['id_uppercat'];

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
                'sort_rank' => $current_rank,
            ];
        }

        $fields = [
            'primary' => ['id'],
            'update' => ['sort_rank'],
        ];
        $conf->sql_backend::mass_updates('categories', $fields, $datas);

        self::update_global_rank();
    }

    /**
     * Orders categories (update categories.rank and global_rank database fields)
     * so that rank field are consecutive integers starting at 1 for each child.
     */
    public static function update_global_rank(): int
    {
        global $conf;

        $query = <<<SQL
            SELECT id, id_uppercat, uppercats, sort_rank, global_rank
            FROM categories
            ORDER BY id_uppercat, sort_rank, name;
            SQL;

        global $cat_map; // used in preg_replace callback
        $cat_map = [];

        $current_rank = 0;
        $current_uppercat = '';

        $result = $conf->sql_backend::pwg_query($query);

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            if ($row['id_uppercat'] != $current_uppercat) {
                $current_rank = 0;
                $current_uppercat = $row['id_uppercat'];
            }

            ++$current_rank;
            $cat =
              [
                  'sort_rank' => $current_rank,
                  'rank_changed' => $current_rank != $row['sort_rank'],
                  'global_rank' => $row['global_rank'],
                  'uppercats' => $row['uppercats'],
              ];
            $cat_map[$row['id']] = $cat;
        }

        $datas = [];

        $cat_map_callback = (fn (array $m): int => $cat_map[$m[1]]['sort_rank']);

        foreach ($cat_map as $id => $cat) {
            $new_global_rank = preg_replace_callback(
                '/(\d+)/',
                $cat_map_callback,
                str_replace(',', '.', $cat['uppercats'])
            );

            if ($cat['rank_changed'] ||
                $new_global_rank !== $cat['global_rank']
            ) {
                $datas[] = [
                    'id' => $id,
                    'sort_rank' => $cat['sort_rank'],
                    'global_rank' => $new_global_rank,
                ];
            }
        }

        unset($cat_map);

        $conf->sql_backend::mass_updates(
            'categories',
            [
                'primary' => ['id'],
                'update' => ['sort_rank', 'global_rank'],
            ],
            $datas
        );
        return count($datas);
    }

    /**
     * Change the **visible** property on a set of categories.
     *
     * @param array<int> $categories
     */
    public static function set_cat_visible(
        array $categories,
        bool|string $value,
        bool $unlock_child = false
    ): ?bool {
        global $conf;

        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($value === null) {
            trigger_error("set_cat_visible invalid param {$value}", E_USER_WARNING);
            return false;
        }

        // unlocking a category => all its parent categories become unlocked
        if ($value) {
            $cats = self::get_uppercat_ids($categories);

            if ($unlock_child) {
                $cats = array_merge($cats, functions_category::get_subcat_ids($categories));
            }

            $cats_list = implode(', ', $cats);
            $query = <<<SQL
                UPDATE categories
                SET visible = 'true'
                WHERE id IN ({$cats_list});
                SQL;
        }
        // locking a category   => all its child categories become locked
        else {
            $subcats = functions_category::get_subcat_ids($categories);
            $subcats_list = implode(', ', $subcats);
            $query = <<<SQL
                UPDATE categories
                SET visible = 'false'
                WHERE id IN ({$subcats_list});
                SQL;
        }

        $conf->sql_backend::pwg_query($query);

        return null;
    }

    /**
     * Change the **status** property on a set of categories : private or public.
     *
     * @param array<int> $categories
     */
    public static function set_cat_status(
        array $categories,
        string $value
    ): ?bool {
        global $conf;

        if (! in_array($value, ['public', 'private'])) {
            trigger_error("set_cat_status invalid param {$value}", E_USER_WARNING);
            return false;
        }

        // make public a category => all its parent categories become public
        if ($value === 'public') {
            $uppercats = self::get_uppercat_ids($categories);
            $uppercats_list = implode(', ', $uppercats);
            $query = <<<SQL
                UPDATE categories
                SET status = 'public'
                WHERE id IN ({$uppercats_list});
                SQL;
            $conf->sql_backend::pwg_query($query);
        }

        // make a category private => all its child categories become private
        if ($value === 'private') {
            $subcats = functions_category::get_subcat_ids($categories);

            $subcats_list = implode(', ', $subcats);
            $query = <<<SQL
                UPDATE categories
                SET status = 'private'
                WHERE id IN ({$subcats_list});
                SQL;
            $conf->sql_backend::pwg_query($query);

            // We have to keep permissions consistent: a sub-album can't be
            // permitted to a user or group if its parent album is not permitted to
            // the same user or group. Let's remove all permissions on sub-albums if
            // it is not consistent. Let's take the following example:
            //
            // A1        permitted to U1,G1
            // A1/A2     permitted to U1,U2,G1,G2
            // A1/A2/A3  permitted to U3,G1
            // A1/A2/A4  permitted to U2
            // A1/A5     permitted to U4
            // A6        permitted to U4
            // A6/A7     permitted to G1
            //
            // (we consider that it can be possible to start with inconsistent
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
            // 3) remove all inconsistent permissions from sub-albums of each top-album

            // step 1, search top albums
            $top_categories = [];
            $parent_ids = [];

            $categories_list = implode(', ', $categories);
            $query = <<<SQL
                SELECT id, name, id_uppercat, uppercats, global_rank
                FROM categories
                WHERE id IN ({$categories_list});
                SQL;
            $all_categories = $conf->sql_backend::query2array($query);
            usort($all_categories, functions_category::global_rank_compare(...));

            foreach ($all_categories as $cat) {
                $is_top = true;

                if (! empty($cat['id_uppercat'])) {
                    foreach (explode(',', $cat['uppercats']) as $id_uppercat) {
                        if (isset($top_categories[$id_uppercat])) {
                            $is_top = false;
                            break;
                        }
                    }
                }

                if ($is_top) {
                    $top_categories[$cat['id']] = $cat;

                    if (! empty($cat['id_uppercat'])) {
                        $parent_ids[] = $cat['id_uppercat'];
                    }
                }
            }

            // step 2, search the reference album for permissions
            //
            // to find the reference of each top album, we will need the parent albums
            $parent_cats = [];

            if ($parent_ids !== []) {
                $parent_ids_list = implode(', ', $parent_ids);
                $query = <<<SQL
                    SELECT id, status
                    FROM categories
                    WHERE id IN ({$parent_ids_list});
                    SQL;
                $parent_cats = $conf->sql_backend::query2array($query, 'id');
            }

            $tables = [
                'user_access' => 'user_id',
                'group_access' => 'group_id',
            ];

            foreach ($top_categories as $top_category) {
                // what is the "reference" for list of permissions? The parent album
                // if it is private, else the album itself
                $ref_cat_id = $top_category['id'];

                if (! empty($top_category['id_uppercat']) &&
                    isset($parent_cats[$top_category['id_uppercat']]) &&
                    $parent_cats[$top_category['id_uppercat']]['status'] == 'private'
                ) {
                    $ref_cat_id = $top_category['id_uppercat'];
                }

                $subcats = functions_category::get_subcat_ids([$top_category['id']]);

                foreach ($tables as $table => $field) {
                    // what are the permissions user/group of the reference album
                    $query = <<<SQL
                        SELECT {$field}
                        FROM {$table}
                        WHERE cat_id = {$ref_cat_id};
                        SQL;
                    $ref_access = $conf->sql_backend::query2array($query, null, $field);

                    if (count($ref_access) == 0) {
                        $ref_access[] = -1;
                    }

                    // step 3, remove the inconsistent permissions from sub-albums
                    $ref_access_list = implode(', ', $ref_access);
                    $subcats_list = implode(', ', $subcats);
                    $query = <<<SQL
                        DELETE FROM {$table}
                        WHERE {$field} NOT IN ({$ref_access_list})
                            AND cat_id IN ({$subcats_list});
                        SQL;
                    $conf->sql_backend::pwg_query($query);
                }
            }
        }

        return null;
    }

    /**
     * Returns all uppercats category ids of the given category ids.
     *
     * @param array<int> $cat_ids
     * @return array<int>
     */
    public static function get_uppercat_ids(
        array $cat_ids
    ): array {
        global $conf;

        if (! is_array($cat_ids) ||
            count($cat_ids) < 1
        ) {
            return [];
        }

        $uppercats = [];

        $cat_ids_list = implode(', ', $cat_ids);
        $query = <<<SQL
            SELECT uppercats
            FROM categories
            WHERE id IN ({$cat_ids_list});
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $uppercats = array_merge(
                $uppercats,
                explode(',', $row['uppercats'])
            );
        }

        return array_unique($uppercats);
    }

    public static function get_category_representative_properties(
        string $image_id,
        ?string $size = null
    ): array {
        global $conf;

        $query = <<<SQL
            SELECT id, representative_ext, path
            FROM images
            WHERE id = {$image_id};
            SQL;

        $row = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));

        $src = $size == null ? DerivativeImage::thumb_url($row) : DerivativeImage::url($size, $row);

        $url = functions_url::get_root_url() . 'admin.php?page=photo-' . $image_id;

        return [
            'src' => $src,
            'url' => $url,
        ];
    }

    /**
     * Set a new random representative to the categories.
     *
     * @param array<int> $categories
     */
    public static function set_random_representative(
        array $categories
    ): void {
        global $conf;

        $datas = [];

        foreach ($categories as $category_id) {
            $random_function = $conf->sql_backend::DB_RANDOM_FUNCTION;
            $query = <<<SQL
                SELECT image_id
                FROM image_category
                WHERE category_id = {$category_id}
                ORDER BY {$random_function}
                LIMIT 1;
                SQL;
            [$representative] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

            $datas[] = [
                'id' => $category_id,
                'representative_picture_id' => $representative,
            ];
        }

        $conf->sql_backend::mass_updates(
            'categories',
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
     * @param array<int> $cat_ids
     * @return array<string>
     */
    public static function get_fulldirs(
        array $cat_ids
    ): array {
        global $conf;

        if (count($cat_ids) == 0) {
            return [];
        }

        // caching directories of existing categories
        global $cat_dirs; // used in preg_replace callback
        $query = <<<SQL
            SELECT id, dir
            FROM categories
            WHERE dir IS NOT NULL;
            SQL;
        $cat_dirs = $conf->sql_backend::query2array($query, 'id', 'dir');

        // caching galleries_url
        $query = <<<SQL
            SELECT id, galleries_url
            FROM sites;
            SQL;
        $galleries_url = $conf->sql_backend::query2array($query, 'id', 'galleries_url');

        // categories : id, site_id, uppercats
        $cat_ids_list = wordwrap(implode(', ', $cat_ids), 80);
        $query = <<<SQL
            SELECT id, uppercats, site_id
            FROM categories
            WHERE dir IS NOT NULL
                AND id IN ({$cat_ids_list});
            SQL;
        $categories = $conf->sql_backend::query2array($query);

        // filling $cat_fulldirs
        $cat_dirs_callback = (fn (array $m): string => $cat_dirs[$m[1]]);

        $cat_fulldirs = [];

        foreach ($categories as $category) {
            $uppercats = str_replace(',', '/', $category['uppercats']);
            $cat_fulldirs[$category['id']] = $galleries_url[$category['site_id']];
            $cat_fulldirs[$category['id']] .= preg_replace_callback(
                '/(\d+)/',
                $cat_dirs_callback,
                $uppercats
            );
        }

        unset($cat_dirs);

        return $cat_fulldirs;
    }

    // /**
    //  * Returns an array with all file system files according to $conf->file_ext
    //  *
    //  * @deprecated 2.4
    //  */
    // public static function get_fs(
    //     string $path,
    //     bool $recursive = true
    // ): array {
    //     global $conf;

    //     // because isset is faster than in_array...
    //     if (! isset($conf->flip_picture_ext)) {
    //         $conf->flip_picture_ext = array_flip($conf->picture_ext);
    //     }

    //     if (! isset($conf->flip_file_ext)) {
    //         $conf->flip_file_ext = array_flip($conf->file_ext);
    //     }

    //     $fs['elements'] = [];
    //     $fs['thumbnails'] = [];
    //     $fs['representatives'] = [];
    //     $subdirs = [];

    //     if (is_dir($path)) {
    //         $contents = opendir($path);

    //         if ($contents) {
    //             while (($node = readdir($contents)) !== false) {
    //                 if ($node == '.' or
    //                     $node == '..'
    //                 ) {
    //                     continue;
    //                 }

    //                 if (is_file($path . '/' . $node)) {
    //                     $extension = functions::get_extension($node);

    //                     if (isset($conf->flip_picture_ext[$extension])) {
    //                         if (basename($path) == 'thumbnail') {
    //                             $fs['thumbnails'][] = $path . '/' . $node;
    //                         } elseif (basename($path) == 'pwg_representative') {
    //                             $fs['representatives'][] = $path . '/' . $node;
    //                         } else {
    //                             $fs['elements'][] = $path . '/' . $node;
    //                         }
    //                     } elseif (isset($conf->flip_file_ext[$extension])) {
    //                         $fs['elements'][] = $path . '/' . $node;
    //                     }
    //                 } elseif (is_dir($path . '/' . $node) and
    //                           $node != 'pwg_high' and
    //                           $recursive
    //                 ) {
    //                     $subdirs[] = $node;
    //                 }
    //             }
    //         }

    //         closedir($contents);

    //         foreach ($subdirs as $subdir) {
    //             $tmp_fs = self::get_fs($path . '/' . $subdir);

    //             $fs['elements'] = array_merge(
    //                 $fs['elements'],
    //                 $tmp_fs['elements']
    //             );

    //             $fs['thumbnails'] = array_merge(
    //                 $fs['thumbnails'],
    //                 $tmp_fs['thumbnails']
    //             );

    //             $fs['representatives'] = array_merge(
    //                 $fs['representatives'],
    //                 $tmp_fs['representatives']
    //             );
    //         }
    //     }

    //     return $fs;
    // }

    /**
     * Synchronize base users list and related users list.
     *
     * Compares and synchronizes base users table (users) with its child
     * tables (user_infos, USER_ACCESS, USER_CACHE, USER_GROUP) : each
     * base user must be present in child tables, users in child tables not
     * present in base table must be deleted.
     */
    public static function sync_users(): void
    {
        global $conf;

        $query = <<<SQL
            SELECT {$conf->user_fields['id']} AS id
            FROM users;
            SQL;
        $base_users = $conf->sql_backend::query2array($query, null, 'id');

        $query = <<<SQL
            SELECT user_id
            FROM user_infos;
            SQL;
        $infos_users = $conf->sql_backend::query2array($query, null, 'user_id');

        // users present in $base_users and not in $infos_users must be added
        $to_create = array_diff($base_users, $infos_users);

        if ($to_create !== []) {
            functions_user::create_user_infos($to_create);
        }

        // users present in user related tables must be present in the base user
        // table
        $tables = [
            'user_mail_notification',
            'user_feed',
            'user_infos',
            'user_access',
            'user_cache',
            'user_cache_categories',
            'user_group',
        ];

        foreach ($tables as $table) {
            $query = <<<SQL
                SELECT DISTINCT user_id
                FROM {$table};
                SQL;
            $to_delete = array_diff(
                $conf->sql_backend::query2array($query, null, 'user_id'),
                $base_users
            );

            if ($to_delete !== []) {
                $to_delete_list = implode(', ', $to_delete);
                $query = <<<SQL
                    DELETE FROM {$table}
                    WHERE user_id in ({$to_delete_list});
                    SQL;
                $conf->sql_backend::pwg_query($query);
            }
        }
    }

    /**
     * Updates categories.uppercats field based on categories.id + categories.id_uppercat
     */
    public static function update_uppercats(): void
    {
        global $conf;

        $query = <<<SQL
            SELECT id, id_uppercat, uppercats
            FROM categories;
            SQL;
        $cat_map = $conf->sql_backend::query2array($query, 'id');

        $datas = [];

        foreach ($cat_map as $id => $cat) {
            $upper_list = [];

            $uppercat = $id;

            while ($uppercat) {
                $upper_list[] = $uppercat;
                $uppercat = $cat_map[$uppercat]['id_uppercat'];
            }

            $new_uppercats = implode(', ', array_reverse($upper_list));

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
        $conf->sql_backend::mass_updates('categories', $fields, $datas);
    }

    /**
     * Update images.path field base on images.file and storage categories fulldirs.
     */
    public static function update_path(): void
    {
        global $conf;

        $query = <<<SQL
            SELECT DISTINCT storage_category_id
            FROM images
            WHERE storage_category_id IS NOT NULL;
            SQL;
        $cat_ids = $conf->sql_backend::query2array($query, null, 'storage_category_id');
        $fulldirs = self::get_fulldirs($cat_ids);

        foreach ($cat_ids as $cat_id) {
            $path_concat = $conf->sql_backend::pwg_db_concat(["'{$fulldirs[$cat_id]}/'", 'file']);
            $query = <<<SQL
                UPDATE images
                SET path = {$path_concat}
                WHERE storage_category_id = {$cat_id};
                SQL;
            $conf->sql_backend::pwg_query($query);
        }
    }

    /**
     * Change the parent category of the given categories. The categories are
     * supposed virtual.
     *
     * @param array<int> $category_ids
     * @param int $new_parent (-1 for root)
     */
    public static function move_categories(
        array $category_ids,
        int $new_parent = -1
    ): void {
        global $page;
        global $conf;

        if (count($category_ids) == 0) {
            return;
        }

        $new_parent = $new_parent < 1 ? 'NULL' : $new_parent;

        $categories = [];

        $category_ids_list = implode(', ', $category_ids);
        $query = <<<SQL
            SELECT id, id_uppercat, status, uppercats
            FROM categories
            WHERE id IN ({$category_ids_list});
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $categories[$row['id']] =
              [
                  'parent' => empty($row['id_uppercat']) ? 'NULL' : $row['id_uppercat'],
                  'status' => $row['status'],
                  'uppercats' => $row['uppercats'],
              ];
        }

        // is the movement possible? The movement is impossible if you try to move
        // a category in a sub-category or itself
        if ($new_parent != 'NULL') {
            $query = <<<SQL
                SELECT uppercats
                FROM categories
                WHERE id = {$new_parent};
                SQL;
            [$new_parent_uppercats] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

            foreach ($categories as $category) {
                // technically, you can't move a category with uppercats 12,125,13,14
                // into a new parent category with uppercats 12,125,13,14,24
                if (preg_match('/^' . $category['uppercats'] . '(,|$)/', $new_parent_uppercats)) {
                    $page['errors'][] = functions::l10n('You cannot move an album in its own sub album');
                    return;
                }
            }
        }

        $tables = [
            'user_access' => 'user_id',
            'group_access' => 'group_id',
        ];

        $category_ids_list = implode(', ', $category_ids);
        $query = <<<SQL
            UPDATE categories
            SET id_uppercat = {$new_parent}
            WHERE id IN ({$category_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        self::update_uppercats();
        self::update_global_rank();

        // status and related permissions management
        if ($new_parent == 'NULL') {
            $parent_status = 'public';
        } else {
            $query = <<<SQL
                SELECT status
                FROM categories
                WHERE id = {$new_parent};
                SQL;
            [$parent_status] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));
        }

        if ($parent_status == 'private') {
            self::set_cat_status(array_keys($categories), 'private');
        }

        $page['infos'][] = functions::l10n_dec(
            '%d album moved',
            '%d albums moved',
            count($categories)
        );

        functions::pwg_activity('album', $category_ids, 'move', [
            'parent' => $new_parent,
        ]);
    }

    /**
     * Create a virtual category.
     *
     * @param array{
     *    commentable: bool,
     *    visible: bool,
     *    status: string,
     *    comment: string,
     *    inherit: bool,
     * } $options
     * @return array ('info', 'id') or ('error')
     */
    public static function create_virtual_category(
        string $category_name,
        ?int $parent_id = null,
        array $options = []
    ): array {
        global $conf, $user;

        // is the given category name only containing blank spaces ?
        if (preg_match('/^\s*$/', $category_name)) {
            return [
                'error' => functions::l10n('The name of an album must not be empty'),
            ];
        }

        $rank = 0;

        if ($conf->newcat_default_position === 'last') {
            //what is the current higher rank for this parent?
            $parent_condition = empty($parent_id) ? 'IS NULL' : "= {$parent_id}";
            $query = <<<SQL
                SELECT MAX(sort_rank) AS max_rank
                FROM categories
                WHERE id_uppercat {$parent_condition};
                SQL;
            $row = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));

            if (is_numeric($row['max_rank'])) {
                $rank = $row['max_rank'] + 1;
            }
        }

        $insert = [
            'name' => $category_name,
            'sort_rank' => $rank,
            'global_rank' => 0,
        ];

        // is the album commentable?
        if (isset($options['commentable']) &&
            is_bool($options['commentable'])
        ) {
            $insert['commentable'] = $options['commentable'];
        } else {
            $insert['commentable'] = $conf->newcat_default_commentable;
        }

        $insert['commentable'] = $conf->sql_backend::boolean_to_string($insert['commentable']);

        // is the album temporarily locked? (only visible by administrators,
        // whatever permissions) (may be overwritten if parent album is not
        // visible)
        if (isset($options['visible']) &&
            is_bool($options['visible'])
        ) {
            $insert['visible'] = $options['visible'];
        } else {
            $insert['visible'] = $conf->newcat_default_visible;
        }

        $insert['visible'] = $conf->sql_backend::boolean_to_string($insert['visible']);

        // is the album private? (may be overwritten if parent album is private)
        if (isset($options['status']) &&
            $options['status'] === 'private'
        ) {
            $insert['status'] = 'private';
        } else {
            $insert['status'] = $conf->newcat_default_status;
        }

        // any description for this album?
        if (isset($options['comment'])) {
            $insert['comment'] = $conf->allow_html_descriptions ? $options['comment'] : strip_tags($options['comment']);
        }

        if (! empty($parent_id) &&
            is_numeric($parent_id)
        ) {
            $query = <<<SQL
                SELECT id, uppercats, global_rank, visible, status
                FROM categories
                WHERE id = {$parent_id};
                SQL;
            $parent = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));

            $insert['id_uppercat'] = $parent['id'];
            $insert['global_rank'] = $parent['global_rank'] . '.' . $insert['sort_rank'];

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
        $conf->sql_backend::single_insert('categories', $insert);
        $inserted_id = $conf->sql_backend::pwg_db_insert_id();

        $conf->sql_backend::single_update(
            'categories',
            [
                'uppercats' => $uppercats_prefix . $inserted_id,
            ],
            [
                'id' => $inserted_id,
            ]
        );

        self::update_global_rank();

        if ($insert['status'] === 'private' &&
            ! empty($insert['id_uppercat']) &&
            ((isset($options['inherit']) && $options['inherit']) || $conf->inheritance_by_default)
        ) {
            $query = <<<SQL
                SELECT group_id
                FROM group_access
                WHERE cat_id = {$insert['id_uppercat']};
                SQL;
            $granted_grps = $conf->sql_backend::query2array($query, null, 'group_id');
            $inserts = [];

            foreach ($granted_grps as $granted_grp) {
                $inserts[] = [
                    'group_id' => $granted_grp,
                    'cat_id' => $inserted_id,
                ];
            }

            $conf->sql_backend::mass_inserts('group_access', ['group_id', 'cat_id'], $inserts);

            $query = <<<SQL
                SELECT user_id
                FROM user_access
                WHERE cat_id = {$insert['id_uppercat']};
                SQL;
            $granted_users = $conf->sql_backend::query2array($query, null, 'user_id');
            self::add_permission_on_category($inserted_id, $granted_users);
        } elseif ($insert['status'] === 'private') {
            self::add_permission_on_category($inserted_id, array_unique(array_merge(self::get_admins(), [$user['id']])));
        }

        functions_plugins::trigger_notify('create_virtual_category', array_merge([
            'id' => $inserted_id,
        ], $insert));
        functions::pwg_activity('album', $inserted_id, 'add');

        return [
            'info' => functions::l10n('Album added'),
            'id' => $inserted_id,
        ];
    }

    /**
     * Set tags to an image.
     * Warning: given tags are all tags associated to the image, not additional tags.
     *
     * @param array<int> $tags
     */
    public static function set_tags(
        array $tags,
        int|string $image_id
    ): void {
        self::set_tags_of([
            $image_id => $tags,
        ]);
    }

    /**
     * Add new tags to a set of images.
     *
     * @param array<int> $tags
     * @param array<int> $images
     */
    public static function add_tags(
        array $tags,
        array $images
    ): void {
        global $conf;

        if (count($tags) == 0 ||
            count($images) == 0
        ) {
            return;
        }

        $taglist_before = self::get_image_tag_ids($images);

        // we can't insert twice the same {image_id,tag_id} so we must first
        // delete lines we'll insert later
        $image_ids_list = implode(', ', $images);
        $tag_ids_list = implode(', ', $tags);
        $query = <<<SQL
            DELETE FROM image_tag
            WHERE image_id IN ({$image_ids_list})
                AND tag_id IN ({$tag_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        $inserts = [];

        foreach ($images as $image_id) {
            foreach (array_unique($tags) as $tag_id) {
                $inserts[] = [
                    'image_id' => $image_id,
                    'tag_id' => $tag_id,
                ];
            }
        }

        $conf->sql_backend::mass_inserts(
            'image_tag',
            array_keys($inserts[0]),
            $inserts
        );

        $taglist_after = self::get_image_tag_ids($images);
        $images_to_update = self::compare_image_tag_lists($taglist_before, $taglist_after);
        self::update_images_lastmodified($images_to_update);

        self::invalidate_user_cache_nb_tags();
    }

    /**
     * Delete tags and tags associations.
     *
     * @param array<int> $tag_ids
     */
    public static function delete_tags(
        array $tag_ids
    ): ?bool {
        global $conf;

        if (is_numeric($tag_ids)) {
            $tag_ids = [$tag_ids];
        }

        if (! is_array($tag_ids)) {
            return false;
        }

        // we need the list of impacted images, to update their lastmodified
        $tag_ids_list = implode(', ', $tag_ids);
        $query = <<<SQL
            SELECT image_id
            FROM image_tag
            WHERE tag_id IN ({$tag_ids_list});
            SQL;
        $image_ids = $conf->sql_backend::query2array($query, null, 'image_id');

        $tag_ids_list = implode(', ', $tag_ids);
        $query = <<<SQL
            DELETE FROM image_tag
            WHERE tag_id IN ({$tag_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        $tag_ids_list = implode(', ', $tag_ids);
        $query = <<<SQL
            DELETE FROM tags
            WHERE id IN ({$tag_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);

        functions_plugins::trigger_notify('delete_tags', $tag_ids);
        functions::pwg_activity('tag', $tag_ids, 'delete');

        self::update_images_lastmodified($image_ids);
        self::invalidate_user_cache_nb_tags();

        return null;
    }

    /**
     * Returns a tag id from its name. If nothing found, create a new tag.
     */
    public static function tag_id_from_tag_name(
        string $tag_name
    ): int {
        global $page;
        global $conf;

        $tag_name = trim($tag_name);

        if (isset($page['tag_id_from_tag_name_cache'][$tag_name])) {
            return $page['tag_id_from_tag_name_cache'][$tag_name];
        }

        // search existing by exact name
        $query = <<<SQL
            SELECT id
            FROM tags
            WHERE name = '{$tag_name}';
            SQL;
        $existing_tags = $conf->sql_backend::query2array($query, null, 'id');

        if (count($existing_tags) == 0) {
            $url_name = functions_plugins::trigger_change('render_tag_url', $tag_name);
            // search existing by url name
            $query = <<<SQL
                SELECT id
                FROM tags
                WHERE url_name = '{$url_name}';
                SQL;
            $existing_tags = $conf->sql_backend::query2array($query, null, 'id');

            if (count($existing_tags) == 0) {
                // search by extended description (plugin sub name)
                $sub_name_where = functions_plugins::trigger_change('get_tag_name_like_where', [], $tag_name);

                if (count($sub_name_where)) {
                    $sub_name_conditions = implode(' OR ', $sub_name_where);
                    $query = <<<SQL
                        SELECT id
                        FROM tags
                        WHERE {$sub_name_conditions};
                        SQL;
                    $existing_tags = $conf->sql_backend::query2array($query, null, 'id');
                }

                if (count($existing_tags) == 0) { // finally create the tag
                    $conf->sql_backend::mass_inserts(
                        'tags',
                        ['name', 'url_name'],
                        [
                            [
                                'name' => $tag_name,
                                'url_name' => $url_name,
                            ],
                        ]
                    );

                    $page['tag_id_from_tag_name_cache'][$tag_name] = $conf->sql_backend::pwg_db_insert_id();

                    self::invalidate_user_cache_nb_tags();

                    return $page['tag_id_from_tag_name_cache'][$tag_name];
                }
            }
        }

        $page['tag_id_from_tag_name_cache'][$tag_name] = $existing_tags[0];
        return $page['tag_id_from_tag_name_cache'][$tag_name];
    }

    /**
     * Batch lookup tag IDs by tag names. More efficient than calling tag_id_from_tag_name() for each tag.
     *
     * @param array<string> $tag_names - List of tag names to look up or create
     * @return array<string, int> - Mapping of tag_name => tag_id
     */
    public static function tag_ids_from_tag_names(
        array $tag_names
    ): array {
        global $conf, $page;

        if (count($tag_names) === 0) {
            return [];
        }

        // Normalize: trim and filter empty names
        $tag_names = array_filter(array_map('trim', $tag_names));
        if (count($tag_names) === 0) {
            return [];
        }

        // Initialize cache if needed
        if (! isset($page['tag_id_from_tag_name_cache'])) {
            $page['tag_id_from_tag_name_cache'] = [];
        }

        $result_map = [];
        $tags_to_lookup = [];

        // Check cache first
        foreach ($tag_names as $tag_name) {
            if (isset($page['tag_id_from_tag_name_cache'][$tag_name])) {
                $result_map[$tag_name] = $page['tag_id_from_tag_name_cache'][$tag_name];
            } else {
                $tags_to_lookup[] = $tag_name;
            }
        }

        if (count($tags_to_lookup) === 0) {
            return $result_map;
        }

        // Batch query for existing tags by exact name
        $quoted_names = array_map(function($name) use ($conf) {
            return "'" . $conf->sql_backend::pwg_db_real_escape_string($name) . "'";
        }, $tags_to_lookup);

        $names_in = implode(', ', $quoted_names);
        $query = <<<SQL
            SELECT id, name
            FROM tags
            WHERE name IN ({$names_in});
            SQL;
        $existing_by_name = $conf->sql_backend::query2array($query, 'name', 'id');

        // Update result map with found tags
        foreach ($existing_by_name as $name => $id) {
            $result_map[$name] = $id;
            $page['tag_id_from_tag_name_cache'][$name] = $id;
        }

        // Find tags still not found
        $tags_to_create = [];
        foreach ($tags_to_lookup as $tag_name) {
            if (! isset($result_map[$tag_name])) {
                $tags_to_create[] = $tag_name;
            }
        }

        // If there are tags not found by exact name, try url_name lookup
        if (count($tags_to_create) > 0) {
            $tags_to_create_by_url = [];
            $url_map = [];

            foreach ($tags_to_create as $tag_name) {
                $url_name = functions_plugins::trigger_change('render_tag_url', $tag_name);
                $url_map[$url_name] = $tag_name;
                $tags_to_create_by_url[] = $url_name;
            }

            $quoted_urls = array_map(function($url) use ($conf) {
                return "'" . $conf->sql_backend::pwg_db_real_escape_string($url) . "'";
            }, $tags_to_create_by_url);

            $urls_in = implode(', ', $quoted_urls);
            $query = <<<SQL
                SELECT id, url_name
                FROM tags
                WHERE url_name IN ({$urls_in});
                SQL;
            $existing_by_url = $conf->sql_backend::query2array($query, 'url_name', 'id');

            // Update result map with tags found by url
            foreach ($existing_by_url as $url_name => $id) {
                $tag_name = $url_map[$url_name];
                $result_map[$tag_name] = $id;
                $page['tag_id_from_tag_name_cache'][$tag_name] = $id;
            }

            // Find tags still not found
            $tags_to_create = [];
            foreach ($tags_to_lookup as $tag_name) {
                if (! isset($result_map[$tag_name])) {
                    $tags_to_create[] = $tag_name;
                }
            }
        }

        // Create new tags in a batch insert
        if (count($tags_to_create) > 0) {
            $inserts = [];

            foreach ($tags_to_create as $tag_name) {
                $url_name = functions_plugins::trigger_change('render_tag_url', $tag_name);
                $inserts[] = [
                    'name' => $tag_name,
                    'url_name' => $url_name,
                ];
            }

            $conf->sql_backend::mass_inserts(
                'tags',
                ['name', 'url_name'],
                $inserts
            );

            self::invalidate_user_cache_nb_tags();

            // Query newly created tags to get their IDs
            $quoted_names = array_map(function($name) use ($conf) {
                return "'" . $conf->sql_backend::pwg_db_real_escape_string($name) . "'";
            }, $tags_to_create);

            $names_in = implode(', ', $quoted_names);
            $query = <<<SQL
                SELECT id, name
                FROM tags
                WHERE name IN ({$names_in});
                SQL;
            $new_tags = $conf->sql_backend::query2array($query, 'name', 'id');

            foreach ($new_tags as $name => $id) {
                $result_map[$name] = $id;
                $page['tag_id_from_tag_name_cache'][$name] = $id;
            }
        }

        return $result_map;
    }

    /**
     * Set tags of images. Overwrites all existing associations.
     *
     * @param array<int, string> $tags_of - keys are image ids, values are array of tag ids
     */
    public static function set_tags_of(
        array $tags_of
    ): void {
        global $conf;

        if ($tags_of !== []) {
            $taglist_before = self::get_image_tag_ids(array_keys($tags_of));
            global $logger;
            $logger->debug('taglist_before', $taglist_before);

            $tag_ids = implode(', ', array_keys($tags_of));
            $query = <<<SQL
                DELETE FROM image_tag
                WHERE image_id IN ({$tag_ids});
                SQL;
            $conf->sql_backend::pwg_query($query);

            $inserts = [];

            foreach ($tags_of as $image_id => $tag_ids) {
                foreach (array_unique($tag_ids) as $tag_id) {
                    $inserts[] = [
                        'image_id' => $image_id,
                        'tag_id' => $tag_id,
                    ];
                }
            }

            if ($inserts !== []) {
                $conf->sql_backend::mass_inserts(
                    'image_tag',
                    array_keys($inserts[0]),
                    $inserts
                );
            }

            $taglist_after = self::get_image_tag_ids(array_keys($tags_of));
            global $logger;
            $logger->debug('taglist_after', $taglist_after);
            $images_to_update = self::compare_image_tag_lists($taglist_before, $taglist_after);
            global $logger;
            $logger->debug('$images_to_update', $images_to_update);

            self::update_images_lastmodified($images_to_update);
            self::invalidate_user_cache_nb_tags();
        }
    }

    /**
     * Get list of tag ids for each image. Returns an empty list if the image has
     * no tags.
     *
     * @return array image_id => list of tag ids
     */
    public static function get_image_tag_ids(
        array $image_ids
    ): array {
        global $conf;

        if (! is_array($image_ids) &&
            is_int($image_ids)
        ) {
            $images_ids = [$image_ids];
        }

        if (count($image_ids) == 0) {
            return [];
        }

        $image_ids_list = implode(', ', $image_ids);
        $query = <<<SQL
            SELECT image_id, tag_id
            FROM image_tag
            WHERE image_id IN ({$image_ids_list});
            SQL;

        $tags_of = array_fill_keys($image_ids, []);
        $image_tags = $conf->sql_backend::query2array($query);

        foreach ($image_tags as $image_tag) {
            $tags_of[$image_tag['image_id']][] = $image_tag['tag_id'];
        }

        return $tags_of;
    }

    /**
     * Compare the list of tags, for each image. Returns image_ids where tag list has changed.
     *
     * @param array $taglist_before - for each image_id (key), list of tag ids
     * @param array $taglist_after - for each image_id (key), list of tag ids
     * @return array - image_ids where the list has changed
     */
    public static function compare_image_tag_lists(
        array $taglist_before,
        array $taglist_after
    ): array {
        $images_to_update = [];

        foreach ($taglist_after as $image_id => $list_after) {
            sort($list_after);

            $list_before = $taglist_before[$image_id] ?? [];
            sort($list_before);

            if ($list_after != $list_before) {
                $images_to_update[] = $image_id;
            }
        }

        return $images_to_update;
    }

    /**
     * Instead of associating images to categories, add them in the lounge, waiting for take-off.
     *
     * @param array $images - list of image ids
     * @param array $categories - list of category ids
     */
    public static function fill_lounge(
        array $images,
        array $categories
    ): void {
        global $conf;

        $inserts = [];

        foreach ($categories as $category_id) {
            foreach ($images as $image_id) {
                $inserts[] = [
                    'image_id' => $image_id,
                    'category_id' => $category_id,
                ];
            }
        }

        if ($inserts !== []) {
            $conf->sql_backend::mass_inserts(
                'lounge',
                array_keys($inserts[0]),
                $inserts,
                [
                    'ignore' => true,
                ]
            );
        }
    }

    /**
     * Move images from the lounge to the categories they were intended for.
     *
     * @return ?array number of images moved
     * @throws RandomException
     */
    public static function empty_lounge(
        bool $invalidate_user_cache = true
    ): ?array {
        global $conf, $logger;

        if ($conf->empty_lounge_running !== null) {
            [$running_exec_id, $running_exec_start_time] = explode('-', $conf->empty_lounge_running);

            if (time() - $running_exec_start_time > 60) {
                $logger->debug(__FUNCTION__ . ', exec=' . $running_exec_id . ', timeout stopped by another call to the function');
                functions::conf_delete_param('empty_lounge_running');
            }
        }

        $exec_id = functions_session::generate_key(4);
        $logger->debug(__FUNCTION__ . (isset($_REQUEST['method']) ? ' (API:' . $_REQUEST['method'] . ')' : '') . ', exec=' . $exec_id . ', begins');

        // if lounge is already being emptied, skip
        $current_time = time();
        $ignore = '';

        if ($conf->dblayer === 'mysqli') {
            $ignore = 'IGNORE';
        }

        $query = <<<SQL
            INSERT {$ignore} INTO config (param, value)
            VALUES ('empty_lounge_running', '{$exec_id}-{$current_time}')
            SQL;

        if ($conf->dblayer === 'pgsql') {
            $query .= <<<SQL

                ON CONFLICT (param) DO NOTHING
                SQL;
        }

        $query .= ';';
        $conf->sql_backend::pwg_query($query);

        $query = <<<SQL
            SELECT value FROM config WHERE param = 'empty_lounge_running';
            SQL;
        [$empty_lounge_running] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));
        [$running_exec_id] = explode('-', $empty_lounge_running);

        if ($running_exec_id !== $exec_id) {
            $logger->debug(__FUNCTION__ . ', exec=' . $exec_id . ', skip');
            return null;
        }

        $logger->debug(__FUNCTION__ . ', exec=' . $exec_id . ' wins the race and gets the token!');

        $max_image_id = 0;

        $query = <<<SQL
            SELECT image_id, category_id
            FROM lounge
            ORDER BY category_id ASC, image_id ASC;
            SQL;

        $rows = $conf->sql_backend::query2array($query);

        $images = [];

        foreach ($rows as $idx => $row) {
            if ($row['image_id'] > $max_image_id) {
                $max_image_id = $row['image_id'];
            }

            $images[] = $row['image_id'];

            if (! isset($rows[$idx + 1]) ||
                $rows[$idx + 1]['category_id'] != $row['category_id']
            ) {
                // if we're at the end of the loop OR if category changes
                self::associate_images_to_categories($images, [$row['category_id']]);
                $images = [];
            }
        }

        $query = <<<SQL
            DELETE FROM lounge
            WHERE image_id <= {$max_image_id};
            SQL;
        $conf->sql_backend::pwg_query($query);

        if ($invalidate_user_cache) {
            self::invalidate_user_cache();
        }

        functions::conf_delete_param('empty_lounge_running');

        $logger->debug(__FUNCTION__ . ', exec=' . $exec_id . ', ends');

        functions_plugins::trigger_notify('empty_lounge', $rows);

        return $rows;
    }

    /**
     * Associate a list of images to a list of categories.
     * The function will not duplicate links and will preserve ranks.
     *
     * @param array<int> $images
     * @param array<int> $categories
     */
    public static function associate_images_to_categories(
        array $images,
        array $categories
    ): ?bool {
        global $conf;

        if (count($images) == 0 ||
            count($categories) == 0
        ) {
            return false;
        }

        // get existing associations
        $image_ids = implode(', ', $images);
        $category_ids = implode(', ', $categories);
        $query = <<<SQL
            SELECT image_id, category_id
            FROM image_category
            WHERE image_id IN ({$image_ids})
                AND category_id IN ({$category_ids});
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        $existing = [];

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $existing[$row['category_id']][] = $row['image_id'];
        }

        // get max rank of each categories
        $category_ids = implode(', ', $categories);
        $query = <<<SQL
            SELECT category_id, MAX(sort_rank) AS max_rank
            FROM image_category
            WHERE sort_rank IS NOT NULL
                AND category_id IN ({$category_ids})
            GROUP BY category_id;
            SQL;

        $current_rank_of = $conf->sql_backend::query2array(
            $query,
            'category_id',
            'max_rank'
        );

        // associate only not already associated images
        $inserts = [];

        foreach ($categories as $category_id) {
            if (! isset($current_rank_of[$category_id])) {
                $current_rank_of[$category_id] = 0;
            }

            if (! isset($existing[$category_id])) {
                $existing[$category_id] = [];
            }

            foreach ($images as $image_id) {
                if (! in_array($image_id, $existing[$category_id])) {
                    $rank = ++$current_rank_of[$category_id];

                    $inserts[] = [
                        'image_id' => $image_id,
                        'category_id' => $category_id,
                        'sort_rank' => $rank,
                    ];
                }
            }
        }

        if ($inserts !== []) {
            $conf->sql_backend::mass_inserts(
                'image_category',
                array_keys($inserts[0]),
                $inserts
            );

            self::update_category($categories);
        }

        return null;
    }

    /**
     * Dissociate a list of images from a category.
     *
     * @param array<int> $images
     */
    public static function dissociate_images_from_category(
        array $images,
        int $category
    ): ?int {
        global $conf;

        // physical links must not be broken, so we must first retrieve image_id
        // which create virtual links with the category to "dissociate from".
        $image_ids = implode(', ', $images);
        $query = <<<SQL
            SELECT id
            FROM image_category
            INNER JOIN images ON image_id = id
            WHERE category_id = {$category}
                AND id IN ({$image_ids})
                AND (category_id != storage_category_id OR storage_category_id IS NULL);
            SQL;
        $dissociables = $conf->sql_backend::query2array($query, null, 'id');

        if ($dissociables !== []) {
            $dissociable_ids = implode(', ', $dissociables);
            $query = <<<SQL
                DELETE FROM image_category
                WHERE category_id = {$category}
                    AND image_id IN ({$dissociable_ids});
                SQL;
            $conf->sql_backend::pwg_query($query);
        }

        return count($dissociables);
    }

    /**
     * Dissociate images from all old categories except their storage category and
     * associate to new categories.
     * This function will preserve ranks.
     *
     * @param array<int> $images
     * @param array<int> $categories
     */
    public static function move_images_to_categories(
        array $images,
        array $categories
    ): ?bool {
        global $conf;

        if (count($images) == 0) {
            return false;
        }

        // let's first break links with all old albums but their "storage album"
        $image_ids = implode(', ', $images);
        $query = '';

        if ($conf->dblayer === 'mysqli') {
            $query = <<<SQL
                DELETE FROM image_category
                JOIN images ON image_id = id
                WHERE id IN ({$image_ids})

                SQL;
        }

        if ($conf->dblayer === 'pgsql') {
            $query = <<<SQL
                DELETE FROM image_category
                USING images
                WHERE image_id = id
                AND id IN ({$image_ids})

                SQL;
        }

        if (is_array($categories) &&
            $categories !== []
        ) {
            $category_ids = implode(', ', $categories);
            $query .= <<<SQL
                AND category_id NOT IN ({$category_ids})

                SQL;
        }

        $query .= <<<SQL
            AND (storage_category_id IS NULL OR storage_category_id != category_id);
            SQL;
        $conf->sql_backend::pwg_query($query);

        if (is_array($categories) &&
            $categories !== []
        ) {
            self::associate_images_to_categories($images, $categories);
        }

        return null;
    }

    /**
     * Associate images associated to a list of source categories to a list of
     * destination categories.
     *
     * @param array<int> $sources
     * @param array<int> $destinations
     */
    public static function associate_categories_to_categories(
        array $sources,
        array $destinations
    ): ?bool {
        global $conf;

        if (count($sources) == 0) {
            return false;
        }

        $category_ids = implode(', ', $sources);
        $query = <<<SQL
            SELECT image_id
            FROM image_category
            WHERE category_id IN ({$category_ids});
            SQL;
        $images = $conf->sql_backend::query2array($query, null, 'image_id');

        self::associate_images_to_categories($images, $destinations);

        return null;
    }

    /**
     * Refer main Piwigo URLs (currently PHPWG_DOMAIN domain)
     *
     * @return array<string>
     */
    public static function pwg_URL(): array
    {
        return [
            'HOME' => PHPWG_URL,
            'WIKI' => PHPWG_URL . '/doc',
            'DEMO' => PHPWG_URL . '/demo',
            'FORUM' => PHPWG_URL . '/forum',
            'BUGS' => PHPWG_URL . '/bugs',
            'EXTENSIONS' => PHPWG_URL . '/ext',
        ];
    }

    /**
     * Invalidates cached data (permissions and category counts) for all users.
     */
    public static function invalidate_user_cache(
        bool $full = true
    ): void {
        global $conf;

        if ($full) {
            $query = <<<SQL
                TRUNCATE TABLE user_cache_categories;
                SQL;
            $conf->sql_backend::pwg_query($query);
            $query = <<<SQL
                TRUNCATE TABLE user_cache;
                SQL;
        } else {
            $query = <<<SQL
                UPDATE user_cache
                SET need_update = 'true';
                SQL;
        }

        $conf->sql_backend::pwg_query($query);

        functions::conf_delete_param('count_orphans');
        functions_plugins::trigger_notify('invalidate_user_cache', $full);
    }

    /**
     * Invalidates cached tags counter for all users.
     */
    public static function invalidate_user_cache_nb_tags(): void
    {
        global $user;
        global $conf;
        unset($user['nb_available_tags']);

        $query = <<<SQL
            UPDATE user_cache
            SET nb_available_tags = NULL;
            SQL;
        $conf->sql_backend::pwg_query($query);
    }

    /**
     * Returns access levels as array used on template with html_options functions.
     */
    public static function get_user_access_level_html_options(
        int $MinLevelAccess = ACCESS_FREE,
        int $MaxLevelAccess = ACCESS_CLOSED
    ): array {
        $tpl_options = [];

        for ($level = $MinLevelAccess; $level <= $MaxLevelAccess; $level++) {
            $tpl_options[$level] = functions::l10n(sprintf('ACCESS_%d', $level));
        }

        return $tpl_options;
    }

    /**
     * returns a list of templates currently available in template-extension.
     * Each .tpl file is extracted from template-extension.
     *
     * @param string $start (internal use)
     * @return array<string>
     */
    public static function get_extents(
        string $start = ''
    ): array {
        if ($start === '') {
            $start = './template-extension';
        }

        $dir = opendir($start);
        $extents = [];

        while (($file = readdir($dir)) !== false) {
            if ($file === '.' ||
                $file === '..' ||
                $file === '.svn'
            ) {
                continue;
            }

            $path = $start . '/' . $file;

            if (is_dir($path)) {
                $extents = array_merge($extents, self::get_extents($path));
            } elseif (! is_link($path) &&
                      file_exists($path) &&
                      functions::get_extension($path) === 'tpl'
            ) {
                $extents[] = substr($path, 21);
            }
        }

        return $extents;
    }

    /**
     * Create a new tag.
     *
     * @return array ('id', info') or ('error')
     */
    public static function create_tag(
        string $tag_name
    ): array {
        global $conf;

        // clean the tag, no html/js allowed in tag name
        $tag_name = strip_tags($tag_name);

        // does the tag already exists?
        $query = <<<SQL
            SELECT id
            FROM tags
            WHERE name = '{$tag_name}';
            SQL;
        $existing_tags = $conf->sql_backend::query2array($query, null, 'id');

        if (count($existing_tags) == 0) {
            $conf->sql_backend::single_insert(
                'tags',
                [
                    'name' => $tag_name,
                    'url_name' => functions_plugins::trigger_change('render_tag_url', $tag_name),
                ]
            );

            $inserted_id = $conf->sql_backend::pwg_db_insert_id();

            return [
                'info' => functions::l10n('Tag "%s" was added', stripslashes($tag_name)),
                'id' => $inserted_id,
            ];
        }

        return [
            'error' => functions::l10n('Tag "%s" already exists', stripslashes($tag_name)),
        ];
    }

    /**
     * Is the category accessible to the (Admin) user ?
     * Note : if the user is not authorized to see this category, category jump
     * will be replaced by admin cat_modify page
     */
    public static function cat_admin_access(
        int $category_id
    ): bool {
        global $user;
        // $filter['visible_categories'] and $filter['visible_images']
        // are not used because it's not necessary (filter != restriction)
        return ! in_array($category_id, explode(',', $user['forbidden_categories'] ?? ''));
    }

    /**
     * Retrieve data from external URL.
     *
     * @param string|resource|null $dest - can be a file resource or string
     * @param array<string, string> $get_data - data added to request url
     * @param array<string, string> $post_data - data transmitted with POST
     * @param int $step (internal use)
     */
    public static function fetchRemote(
        string $src,
        mixed &$dest,
        array $get_data = [],
        array $post_data = [],
        string $user_agent = 'Piwigo',
        int $step = 0
    ): bool {
        global $conf;

        // Try to retrieve data from local file?
        if (! functions_url::url_is_remote($src)) {
            $content = file_exists($src) ? file_get_contents($src) : false;

            if ($content !== false) {
                is_resource($dest) ? fwrite($dest, $content) : $dest = $content;
                return true;
            }

            return false;

        }

        // After 3 redirections, return false
        if ($step > 3) {
            return false;
        }

        // Initialization
        $method = $post_data === [] ? 'GET' : 'POST';
        $request = $post_data === [] ? '' : http_build_query($post_data, '', '&');

        if ($get_data !== []) {
            $src .= str_contains($src, '?') ? '&' : '?';
            $src .= http_build_query($get_data, '', '&');
        }

        // Initialize $dest
        if (! is_resource($dest)) {
            $dest = '';
        }

        // Try curl to read remote file
        $ch = curl_init();

        if (isset($conf->use_proxy) &&
            $conf->use_proxy
        ) {
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, 0);
            curl_setopt($ch, CURLOPT_PROXY, $conf->proxy_server);

            if (isset($conf->proxy_auth) &&
                ! empty($conf->proxy_auth)
            ) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $conf->proxy_auth);
            }
        }

        curl_setopt($ch, CURLOPT_URL, $src);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
        }

        $content = curl_exec($ch);
        $header_length = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content !== false &&
            $status >= 200 &&
            $status < 400
        ) {
            if (preg_match('/Location:\s+?(.+)/', substr($content, 0, $header_length), $m)) {
                return self::fetchRemote($m[1], $dest, [], [], $user_agent, $step + 1);
            }

            $content = substr($content, $header_length);
            is_resource($dest) ? fwrite($dest, $content) : $dest = $content;
            return true;
        }

        // Try file_get_contents to read remote file
        if (ini_get('allow_url_fopen')) {
            $opts = [
                'http' => [
                    'method' => $method,
                    'user_agent' => $user_agent,
                    'header' => str_contains($src, 'format=php') ? "Content-type: application/x-www-form-urlencoded\r\n" : '',
                ],
            ];

            if ($method === 'POST') {
                $opts['http']['content'] = $request;
            }

            $context = stream_context_create($opts);
            $content = file_get_contents($src, false, $context);

            if ($content !== false) {
                is_resource($dest) ? fwrite($dest, $content) : $dest = $content;
                return true;
            }
        }

        // Try fsockopen to read remote file
        $src = parse_url($src);
        $host = $src['host'];
        $path = $src['path'] ?? '/';
        $path .= isset($src['query']) ? '?' . $src['query'] : '';
        $s = fsockopen($host, 80, $errno, $errstr, 5);

        if ($s === false) {
            return false;
        }

        $http_request = $method . ' ' . $path . " HTTP/1.0\r\n";
        $http_request .= 'Host: ' . $host . "\r\n";

        if ($method === 'POST') {
            $http_request .= "Content-Type: application/x-www-form-urlencoded;\r\n";
            $http_request .= 'Content-Length: ' . strlen($request) . "\r\n";
        }

        $http_request .= 'User-Agent: ' . $user_agent . "\r\n";
        $http_request .= "Accept: */*\r\n";
        $http_request .= "\r\n";
        $http_request .= $request;

        fwrite($s, $http_request);

        $i = 0;
        $in_content = false;

        while (! feof($s)) {
            $line = fgets($s);

            if (rtrim($line, "\r\n") === '' &&
                ! $in_content
            ) {
                $in_content = true;
                $i++;
                continue;
            }

            if ($i == 0) {
                if (! preg_match('/HTTP\/(\\d\\.\\d)\\s*(\\d+)\\s*(.*)/', rtrim($line, "\r\n"), $m)) {
                    fclose($s);
                    return false;
                }

                $status = (int) $m[2];

                if ($status < 200 ||
                    $status >= 400
                ) {
                    fclose($s);
                    return false;
                }
            }

            if (! $in_content) {
                if (preg_match('/Location:\s+?(.+)$/', rtrim($line, "\r\n"), $m)) {
                    fclose($s);
                    return self::fetchRemote(trim($m[1]), $dest, [], [], $user_agent, $step + 1);
                }

                $i++;
                continue;
            }

            is_resource($dest) ? fwrite($dest, $line) : $dest .= $line;
            $i++;
        }

        fclose($s);
        return true;
    }

    /**
     * Returns the groupname corresponding to the given group identifier if exists.
     */
    public static function get_groupname(
        int $group_id
    ): false|string {
        global $conf;

        $query = <<<SQL
            SELECT name
            FROM user_groups
            WHERE id = {$group_id};
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        if ($conf->sql_backend::pwg_db_num_rows($result) > 0) {
            [$groupname] = $conf->sql_backend::pwg_db_fetch_row($result);
        } else {
            return false;
        }

        return $groupname;
    }

    public static function delete_groups(
        array $group_ids
    ): false|array {
        global $conf;

        if (count($group_ids) == 0) {
            trigger_error('There is no group to delete', E_USER_WARNING);
            return false;
        }

        if (preg_match('/^group:(\d+)$/', functions::conf_get_param('email_admin_on_new_user', 'undefined'), $matches)) {
            foreach ($group_ids as $group_id) {
                if ($group_id == $matches[1]) {
                    functions::conf_update_param('email_admin_on_new_user', 'all', true);
                }
            }
        }

        $group_id_string = implode(', ', $group_ids);

        // destruction of the access linked to the group
        $query = <<<SQL
            DELETE FROM group_access
            WHERE group_id IN ({$group_id_string});
            SQL;
        $conf->sql_backend::pwg_query($query);

        // destruction of the users links for this group
        $query = <<<SQL
            DELETE FROM user_group
            WHERE group_id IN ({$group_id_string});
            SQL;
        $conf->sql_backend::pwg_query($query);

        $query = <<<SQL
            SELECT id, name
            FROM user_groups
            WHERE id IN ({$group_id_string});
            SQL;

        $group_list = $conf->sql_backend::query2array($query, 'id', 'name');
        $groupids = array_keys($group_list);

        // destruction of the group
        $query = <<<SQL
            DELETE FROM user_groups
            WHERE id IN ({$group_id_string});
            SQL;
        $conf->sql_backend::pwg_query($query);

        functions_plugins::trigger_notify('delete_group', $groupids);
        functions::pwg_activity('group', $groupids, 'delete');

        return $group_list;
    }

    /**
     * Returns the username corresponding to the given user identifier if exists.
     */
    public static function get_username(
        int $user_id
    ): false|string {
        global $conf;

        $query = <<<SQL
            SELECT {$conf->user_fields['username']}
            FROM users
            WHERE {$conf->user_fields['id']} = {$user_id};
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        if ($conf->sql_backend::pwg_db_num_rows($result) > 0) {
            [$username] = $conf->sql_backend::pwg_db_fetch_row($result);
        } else {
            return false;
        }

        return stripslashes($username);
    }

    /**
     * Get url on piwigo.org for newsletter subscription
     *
     * @param string $language (unused)
     */
    public static function get_newsletter_subscribe_base_url(
        string $language = 'en_UK'
    ): string {
        return PHPWG_URL . '/announcement/subscribe/';
    }

    /**
     * Return admin menu id for accordion.
     */
    public static function get_active_menu(
        string $menu_page
    ): int {
        global $page;
        return $page['active_menu'] ?? match ($menu_page) {
            'photo', 'photos_add', 'rating', 'rating_user', 'tags', 'batch_manager' => 0,
            'album', 'cat_list', 'albums', 'cat_options', 'cat_search', 'permalinks' => 1,
            'user_list', 'user_perm', 'group_list', 'group_perm', 'notification_by_mail', 'user_activity' => 2,
            'site_manager', 'site_update', 'stats', 'history', 'maintenance', 'comments', 'updates' => 3,
            'configuration', 'derivatives', 'extend_for_templates', 'menubar', 'themes', 'theme', 'languages' => 4,
            default => -1,
        };
    }

    /**
     * Get tags list from SQL query (ids are surrounded by ~~, for get_tag_ids()).
     *
     * @param bool $only_user_language - if true, only local name is returned for
     *    multilingual tags (if ExtendedDescription plugin is active)
     * @return array[] ('id', 'name')
     */
    public static function get_taglist(
        string $query,
        bool $only_user_language = true
    ): array {
        global $conf;

        $result = $conf->sql_backend::pwg_query($query);

        $taglist = [];
        $altlist = [];

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $raw_name = $row['name'];
            $name = functions_plugins::trigger_change('render_tag_name', $raw_name, $row);

            $taglist[] = [
                'name' => $name,
                'id' => '~~' . $row['id'] . '~~',
            ];

            if (! $only_user_language) {
                $alt_names = functions_plugins::trigger_change('get_tag_alt_names', [], $raw_name);

                foreach (array_diff(array_unique($alt_names), [$name]) as $alt) {
                    $altlist[] = [
                        'name' => $alt,
                        'id' => '~~' . $row['id'] . '~~',
                    ];
                }
            }
        }

        usort($taglist, functions_html::tag_alpha_compare(...));

        if ($altlist !== []) {
            usort($altlist, functions_html::tag_alpha_compare(...));
            $taglist = array_merge($taglist, $altlist);
        }

        return $taglist;
    }

    /**
     * Get tags ids from a list of raw tags (existing tags or new tags).
     *
     * In $raw_tags we receive something like array('~~6~~', '~~59~~', 'New
     * tag', 'Another new tag') The ~~34~~ means that it is an existing
     * tag. We added the surrounding ~~ to permit creation of tags like "10"
     * or "1234" (numeric characters only)
     *
     * @param string|array<string> $raw_tags - array or comma separated string
     * @return array<int>
     */
    public static function get_tag_ids(
        array|string $raw_tags,
        bool $allow_create = true
    ): array {
        $tag_ids = [];

        if (! is_array($raw_tags)) {
            $raw_tags = explode(',', $raw_tags);
        }

        foreach ($raw_tags as $raw_tag) {
            if (preg_match('/^~~(\d+)~~$/', $raw_tag, $matches)) {
                $tag_ids[] = $matches[1];
            } elseif ($allow_create) {
                // we have to create a new tag
                $tag_ids[] = self::tag_id_from_tag_name(strip_tags($raw_tag));
            }
        }

        return $tag_ids;
    }

    /**
     * Returns the argument_ids array with new sequenced keys based on related
     * names. Sequence is not case sensitive.
     * Warning: By definition, this function breaks original keys.
     *
     * @param array<int> $element_ids
     * @param array<string> $name - names of elements, indexed by ids
     * @return array<int>
     */
    public static function order_by_name(
        array $element_ids,
        array $name
    ): array {
        $ordered_element_ids = [];

        foreach ($element_ids as $k_id => $element_id) {
            $key = strtolower($name[$element_id]) . '-' . $name[$element_id] . '-' . $k_id;
            $ordered_element_ids[$key] = $element_id;
        }

        ksort($ordered_element_ids);
        return $ordered_element_ids;
    }

    /**
     * Grant access to a list of categories for a list of users.
     *
     * @param array<int>|int $category_ids
     * @param array<int> $user_ids
     */
    public static function add_permission_on_category(
        array|int $category_ids,
        array $user_ids
    ): void {
        global $conf;

        if (! is_array($category_ids)) {
            $category_ids = [$category_ids];
        }

        if (! is_array($user_ids)) {
            $user_ids = [$user_ids];
        }

        // check for emptiness
        if (count($category_ids) == 0 ||
            count($user_ids) == 0
        ) {
            return;
        }

        // make sure categories are private and select uppercats or subcats
        $cat_ids = self::get_uppercat_ids($category_ids);

        if (isset($_POST['apply_on_sub'])) {
            $cat_ids = array_merge($cat_ids, functions_category::get_subcat_ids($category_ids));
        }

        $category_ids = implode(', ', $cat_ids);
        $query = <<<SQL
            SELECT id
            FROM categories
            WHERE id IN ({$category_ids})
                AND status = 'private';
            SQL;
        $private_cats = $conf->sql_backend::query2array($query, null, 'id');

        if (count($private_cats) == 0) {
            return;
        }

        $inserts = [];

        foreach ($private_cats as $cat_id) {
            foreach ($user_ids as $user_id) {
                $inserts[] = [
                    'user_id' => $user_id,
                    'cat_id' => $cat_id,
                ];
            }
        }

        $conf->sql_backend::mass_inserts(
            'user_access',
            ['user_id', 'cat_id'],
            $inserts,
            [
                'ignore' => true,
            ]
        );
    }

    /**
     * Returns the list of admin users.
     *
     * @return array<int>
     */
    public static function get_admins(
        bool $include_webmaster = true
    ): array {
        global $conf;

        $status_list = ['admin'];

        if ($include_webmaster) {
            $status_list[] = 'webmaster';
        }

        $status_values = implode("','", $status_list);
        $query = <<<SQL
            SELECT user_id
            FROM user_infos
            WHERE status in ('{$status_values}');
            SQL;

        return $conf->sql_backend::query2array($query, null, 'user_id');
    }

    /**
     * Delete all derivative files for one or several types
     *
     * @param array<int>|string $types
     */
    public static function clear_derivative_cache(
        array|string $types = 'all'
    ): void {
        if ($types === 'all') {
            $types = ImageStdParams::get_all_types();
            $types[] = derivative_std_params::IMG_CUSTOM;
        } elseif (! is_array($types)) {
            $types = [$types];
        }

        $counter = count($types);

        for ($i = 0; $i < $counter; $i++) {
            $type = $types[$i];

            if ($type == derivative_std_params::IMG_CUSTOM) {
                $type = derivative_params::derivative_to_url($type) . '_[a-zA-Z0-9]+';
            } elseif (in_array($type, ImageStdParams::get_all_types())) {
                $type = derivative_params::derivative_to_url($type);
            } else { //assume a custom type
                $type = derivative_params::derivative_to_url(derivative_std_params::IMG_CUSTOM) . '_' . $type;
            }

            $types[$i] = $type;
        }

        $pattern = '#.*-';

        if (count($types) > 1) {
            $pattern .= '(' . implode('|', $types) . ')';
        } else {
            $pattern .= $types[0];
        }

        $pattern .= '\.[a-zA-Z0-9]{3,4}$#';
        $contents = opendir('./' . PWG_DERIVATIVE_DIR);

        if ($contents) {
            while (($node = readdir($contents)) !== false) {
                if ($node !== '.' &&
                    $node !== '..' &&
                    is_dir('./' . PWG_DERIVATIVE_DIR . $node)
                ) {
                    self::clear_derivative_cache_rec('./' . PWG_DERIVATIVE_DIR . $node, $pattern);
                }
            }

            closedir($contents);
        }
    }

    /**
     * Used by clear_derivative_cache()
     */
    public static function clear_derivative_cache_rec(
        string $path,
        string $pattern
    ): bool|int|null {
        $rmdir = true;
        $rm_index = false;
        $contents = opendir($path);

        if ($contents) {
            while (($node = readdir($contents)) !== false) {
                if ($node === '.' ||
                    $node === '..'
                ) {
                    continue;
                }

                if (is_dir($path . '/' . $node)) {
                    $rmdir &= self::clear_derivative_cache_rec($path . '/' . $node, $pattern);
                } elseif (preg_match($pattern, $node)) {
                    unlink($path . '/' . $node);
                } elseif ($node === 'index.htm') {
                    $rm_index = true;
                } else {
                    $rmdir = false;
                }
            }

            closedir($contents);

            if ($rmdir) {
                if ($rm_index) {
                    unlink($path . '/index.htm');
                }

                clearstatcache();
                rmdir($path);
            }

            return $rmdir;
        }

        return null;
    }

    /**
     * Deletes derivatives of a particular element
     *
     * @param array $infos ('path'[, 'representative_ext'])
     */
    public static function delete_element_derivatives(
        array $infos,
        int|string $type = 'all'
    ): void {
        $path = $infos['path'];

        if (! empty($infos['representative_ext'])) {
            $path = functions::original_to_representative($path, $infos['representative_ext']);
        }

        if (substr_compare($path, '../', 0, 3) == 0) {
            $path = substr($path, 3);
        }

        $dot = strrpos($path, '.');

        $pattern = $type == 'all' ? '-*' : '-' . derivative_params::derivative_to_url($type) . '*';

        $path = substr_replace($path, $pattern, $dot, 0);
        $glob = glob('./' . PWG_DERIVATIVE_DIR . $path);

        if ($glob !== false) {
            foreach ($glob as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Returns an array containing sub-directories, excluding ".svn"
     *
     * @return array<string>
     */
    public static function get_dirs(
        string $directory
    ): array {
        $sub_dirs = [];
        $opendir = opendir($directory);

        if ($opendir) {
            while ($file = readdir($opendir)) {
                if ($file !== '.' &&
                    $file !== '..' &&
                    is_dir($directory . '/' . $file) &&
                    $file !== '.svn'
                ) {
                    $sub_dirs[] = $file;
                }
            }

            closedir($opendir);
        }

        return $sub_dirs;
    }

    /**
     * Recursively delete a directory.
     *
     * @param ?string $trash_path, try to move the directory to this path if it cannot be delete
     */
    public static function deltree(
        string $path,
        ?string $trash_path = null
    ): ?bool {
        if (is_dir($path)) {
            $fh = opendir($path);

            while ($file = readdir($fh)) {
                if ($file !== '.' &&
                    $file !== '..'
                ) {
                    $pathfile = $path . '/' . $file;

                    if (is_dir($pathfile)) {
                        self::deltree($pathfile, $trash_path);
                    } else {
                        unlink($pathfile);
                    }
                }
            }

            closedir($fh);

            if (rmdir($path)) {
                return true;
            } elseif (! empty($trash_path)) {
                if (! is_dir($trash_path)) {
                    functions::mkgetdir($trash_path, functions::MKGETDIR_RECURSIVE | functions::MKGETDIR_DIE_ON_ERROR | functions::MKGETDIR_PROTECT_HTACCESS);
                }

                while ($r = $trash_path . '/' . md5(uniqid((string) mt_rand(), true))) {
                    if (! is_dir($r)) {
                        rename($path, $r);
                        break;
                    }
                }
            } else {
                return false;
            }
        }

        return null;
    }

    /**
     * Returns keys to identify the state of main tables. A key consists of the
     * last modification timestamp and the total of items (separated by a _).
     * Additionally returns the hash of root path.
     * Used to invalidate LocalStorage cache on admin pages.
     *
     * @param string|array<string> $requested list of keys to retrieve (categories,groups,images,tags,users)
     * @return array<string>
     */
    public static function get_admin_client_cache_keys(
        array|string $requested = []
    ): array {
        global $conf;

        $tables = [
            'categories' => 'categories',
            'user_groups' => 'user_groups',
            'images' => 'images',
            'tags' => 'tags',
            'users' => 'user_infos',
        ];

        if (! is_array($requested)) {
            $requested = [$requested];
        }

        $requested = $requested === [] ? array_keys($tables) : array_intersect($requested, array_keys($tables));

        $keys = [
            '_hash' => md5(functions_url::get_absolute_root_url()),
        ];

        foreach ($requested as $item) {
            $query = <<<SQL
                SELECT CONCAT(
                SQL;

            if ($conf->dblayer === 'mysqli') {
                $query .= <<<SQL
                    UNIX_TIMESTAMP(MAX(lastmodified))
                    SQL;
            }

            if ($conf->dblayer === 'pgsql') {
                $query .= <<<SQL
                    EXTRACT(EPOCH FROM MAX(lastmodified))::BIGINT
                    SQL;
            }

            $query .= <<<SQL
                , '_', COUNT(*))
                FROM {$tables[$item]};
                SQL;
            [$keys[$item]] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));
        }

        return $keys;
    }

    /**
     * Return the list of image ids where md5sum is null
     *
     * @return array<int> image_ids
     */
    public static function get_photos_no_md5sum(): array
    {
        global $conf;

        $query = <<<SQL
            SELECT id
            FROM images
            WHERE md5sum IS NULL;
            SQL;
        return $conf->sql_backend::query2array($query, null, 'id');
    }

    /**
     * Compute and add the md5sum of image ids (where md5sum is null)
     * @param array<int> $ids list of image ids and there paths
     * @return int number of md5sum added
     */
    public static function add_md5sum(
        array $ids
    ): int {
        global $conf;

        $ids_list = implode(', ', $ids);
        $query = <<<SQL
            SELECT path
            FROM images
            WHERE id IN ({$ids_list});
            SQL;
        $paths = $conf->sql_backend::query2array($query, null, 'path');
        $imgs_ids_paths = array_combine($ids, $paths);
        $updates = [];

        foreach ($ids as $id) {
            $file = './' . $imgs_ids_paths[$id];
            $md5sum = md5_file($file);
            $updates[] = [
                'id' => $id,
                'md5sum' => $md5sum,
            ];
        }

        $conf->sql_backend::mass_updates(
            'images',
            [
                'primary' => ['id'],
                'update' => ['md5sum'],
            ],
            $updates
        );
        return count($ids);
    }

    public static function count_orphans(): bool|int
    {
        global $conf;

        if (functions::conf_get_param('count_orphans') === null) {
            // we don't care about the list of image_ids, we only care about the number
            // of orphans, so let's use a faster method than calling count(get_orphans())
            $query = <<<SQL
                SELECT COUNT(*) AS "COUNT(*)"
                FROM images;
                SQL;
            [$image_counter_all] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

            $query = <<<SQL
                SELECT COUNT(DISTINCT image_id)
                FROM image_category;
                SQL;
            [$image_counter_in_categories] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

            $counter = $image_counter_all - $image_counter_in_categories;
            functions::conf_update_param('count_orphans', $counter, true);
        }

        return functions::conf_get_param('count_orphans');
    }

    /**
     * Return the list of image ids associated to no album
     *
     * @return array<int> $image_ids
     */
    public static function get_orphans(): array
    {
        global $conf;

        // exclude images in the lounge
        $query = <<<SQL
            SELECT image_id
            FROM lounge;
            SQL;
        $lounged_ids = $conf->sql_backend::query2array($query, null, 'image_id');

        $query = <<<SQL
            SELECT id
            FROM images
            LEFT JOIN image_category ON id = image_id
            WHERE category_id IS NULL

            SQL;

        if ($lounged_ids !== []) {
            $imploded_lounged_ids = implode(', ', $lounged_ids);
            $query .= <<<SQL
                AND id NOT IN ({$imploded_lounged_ids})

                SQL;
        }

        $query .= <<<SQL
            ORDER BY id ASC;
            SQL;

        return $conf->sql_backend::query2array($query, null, 'id');
    }

    /**
     * save the rank depending on given images order
     *
     * The list of ordered images id is supposed to be in the same parent
     * category
     *
     * @param array<int> $images
     */
    public static function save_images_order(
        int $category_id,
        array $images
    ): void {
        global $conf;

        $current_rank = 0;
        $datas = [];

        foreach ($images as $id) {
            $datas[] = [
                'category_id' => $category_id,
                'image_id' => $id,
                'sort_rank' => ++$current_rank,
            ];
        }

        $fields = [
            'primary' => ['image_id', 'category_id'],
            'update' => ['sort_rank'],
        ];
        $conf->sql_backend::mass_updates('image_category', $fields, $datas);
    }

    /**
     * Force update on images.lastmodified column. Useful when modifying the tag list.
     */
    public static function update_images_lastmodified(
        array $image_ids
    ): void {
        global $conf;

        if (! is_array($image_ids) &&
            is_int($image_ids)
        ) {
            $images_ids = [$image_ids];
        }

        if (count($image_ids) == 0) {
            return;
        }

        $image_ids_list = implode(', ', $image_ids);
        $query = <<<SQL
            UPDATE images
            SET lastmodified = NOW()
            WHERE id IN ({$image_ids_list});
            SQL;
        $conf->sql_backend::pwg_query($query);
    }

    /**
     * Get a more human friendly representation of big numbers. Like 17.8k instead of 17832
     */
    public static function number_format_human_readable(
        ?float $numbers
    ): string {
        $readable = ['', 'k', 'M'];
        $index = 0;
        $numbers = empty($numbers) ? 0 : $numbers;

        while ($numbers >= 1000) {
            $numbers /= 1000;
            $index++;

            if ($index > count($readable) - 1) {
                $index--;
                break;
            }
        }

        $decimals = 1;

        if ($readable[$index] === '') {
            $decimals = 0;
        }

        return number_format($numbers, $decimals) . $readable[$index];
    }

    /**
     * Get infos related to an image
     */
    public static function get_image_infos(
        int|string $image_id,
        bool $die_on_missing = false
    ): array|int|null {
        global $conf;

        if (! is_numeric($image_id)) {
            functions_html::fatal_error('[' . __FUNCTION__ . '] invalid image identifier ' . htmlentities($image_id));
        }

        $query = <<<SQL
            SELECT *
            FROM images
            WHERE id = {$image_id};
            SQL;
        $images = $conf->sql_backend::query2array($query);

        if (count($images) == 0) {
            if ($die_on_missing) {
                functions_html::fatal_error('photo ' . $image_id . ' does not exist');
            }

            return null;
        }

        return $images[0];
    }

    /**
     * Return each cache image sizes.
     */
    public static function get_cache_size_derivatives(
        string $path
    ): array {
        $msizes = []; //final res
        $subdirs = []; //sous-rep

        if (is_dir($path)) {
            $contents = opendir($path);

            if ($contents) {
                while (($node = readdir($contents)) !== false) {
                    if ($node === '.' ||
                        $node === '..'
                    ) {
                        continue;
                    }

                    if (is_file($path . '/' . $node)) {
                        $split = explode('-', $node);

                        if ($split !== []) {
                            $size_code = substr(end($split), 0, 2);
                            $msizes[$size_code] ??= 0;
                            $msizes[$size_code] += filesize($path . '/' . $node);
                        }
                    } elseif (is_dir($path . '/' . $node)) {
                        $tmp_msizes = self::get_cache_size_derivatives($path . '/' . $node);

                        foreach ($tmp_msizes as $size_key => $value) {
                            $msizes[$size_key] ??= 0;
                            $msizes[$size_key] += $value;
                        }
                    }
                }
            }

            closedir($contents);
        }

        return $msizes;
    }

    /**
     * Displays a header warning if we find missing photos on a random sample.
     */
    public static function fs_quick_check(): void
    {
        global $page, $conf;

        if ($conf->fs_quick_check_period == 0) {
            return;
        }

        if (isset($page[__FUNCTION__ . '_already_called'])) {
            return;
        }

        $page[__FUNCTION__ . '_already_called'] = true;
        functions::conf_update_param('fs_quick_check_last_check', date('c'));

        $query = <<<SQL
            SELECT id
            FROM images
            WHERE date_available < '2022-12-08 00:00:00'
                AND path LIKE './upload/%'
            LIMIT 5000;
            SQL;
        $issue1827_ids = $conf->sql_backend::query2array($query, null, 'id');
        shuffle($issue1827_ids);
        $issue1827_ids = array_slice($issue1827_ids, 0, 50);

        $query = <<<SQL
            SELECT id
            FROM images
            LIMIT 5000;
            SQL;
        $random_image_ids = $conf->sql_backend::query2array($query, null, 'id');
        shuffle($random_image_ids);
        $random_image_ids = array_slice($random_image_ids, 0, 50);

        $fs_quick_check_ids = array_unique(array_merge($issue1827_ids, $random_image_ids));

        if (count($fs_quick_check_ids) < 1) {
            return;
        }

        $quick_check_ids = implode(', ', $fs_quick_check_ids);
        $query = <<<SQL
            SELECT id, path
            FROM images
            WHERE id IN ({$quick_check_ids});
            SQL;
        $fsqc_paths = $conf->sql_backend::query2array($query, 'id', 'path');

        foreach ($fsqc_paths as $id => $path) {
            if (! file_exists($path)) {
                global $template;

                $template->assign(
                    'header_msgs',
                    [
                        functions::l10n('Some photos are missing from your file system. Details provided by plugin Check Uploads'),
                    ]
                );

                return;
            }
        }
    }

    /**
     * Return latest news from piwigo.org.
     */
    public static function get_piwigo_news(): array
    {
        global $lang_info;

        $news = null;

        $cache_path = './' . functions::conf_get_param('data_location') . 'cache/piwigo_latest_news-' . $lang_info['code'] . '.cache.php';

        if (! is_file($cache_path) ||
            filemtime($cache_path) < strtotime('24 hours ago')
        ) {
            $url = PHPWG_URL . '/ws.php?method=porg.news.getLatest&format=json';

            if (self::fetchRemote($url, $content)) {
                $all_news = [];

                $porg_news_getLatest = json_decode($content, true);

                if (isset($porg_news_getLatest['result'])) {
                    $topic = $porg_news_getLatest['result'];

                    $news = [
                        'id' => $topic['topic_id'],
                        'subject' => $topic['subject'],
                        'posted_on' => $topic['posted_on'],
                        'posted' => functions::format_date($topic['posted_on']),
                        'url' => $topic['url'],
                    ];
                }

                if (functions::mkgetdir(dirname($cache_path))) {
                    file_put_contents($cache_path, serialize($news));
                }
            } else {
                return [];
            }
        }

        if ($news === null) {
            $news = unserialize(file_get_contents($cache_path));
        }

        return $news;
    }

    public static function assocToOrderedTree(
        array $assocT
    ): array {
        global $nb_photos_in, $nb_sub_photos, $is_forbidden;

        $orderedTree = [];

        foreach ($assocT as $cat) {
            $orderedCat = [];
            $orderedCat['sort_rank'] = $cat['cat']['sort_rank'];
            $orderedCat['name'] = $cat['cat']['name'];
            $orderedCat['status'] = $cat['cat']['status'];
            $orderedCat['id'] = $cat['cat']['id'];
            $orderedCat['visible'] = $cat['cat']['visible'];
            $orderedCat['nb_images'] = $nb_photos_in[$cat['cat']['id']] ?? 0;
            $orderedCat['last_updates'] = $cat['cat']['lastmodified'];
            $orderedCat['has_not_access'] = isset($is_forbidden[$cat['cat']['id']]);
            $orderedCat['nb_sub_photos'] = $nb_sub_photos[$cat['cat']['id']] ?? 0;

            if (isset($cat['children'])) {
                //Does not update when moving a node
                $orderedCat['nb_subcats'] = count($cat['children']);
                $orderedCat['children'] = self::assocToOrderedTree($cat['children']);
            }

            $orderedTree[] = $orderedCat;
        }

        usort($orderedTree, fn (array $a, array $b): int => $a['sort_rank'] <=> $b['sort_rank']);
        return $orderedTree;
    }

    public static function get_categories_ref_date(
        array $ids,
        string $field = 'date_available',
        string $minmax = 'max'
    ): array {
        global $conf;

        // we need to work on the whole tree under each category, even if we don't
        // want to sort sub categories
        $category_ids = functions_category::get_subcat_ids($ids);

        // search for the reference date of each album
        $category_ids_str = implode(', ', $category_ids);
        $query = <<<SQL
            SELECT category_id, {$minmax}({$field}) AS ref_date
            FROM image_category
            JOIN images ON image_id = id
            WHERE category_id IN ({$category_ids_str})
            GROUP BY category_id;
            SQL;
        $ref_dates = $conf->sql_backend::query2array($query, 'category_id', 'ref_date');

        // the iterate on all albums (having a ref_date or not) to find the
        // reference_date, with a search on sub-albums
        $category_ids_str = implode(', ', $category_ids);
        $query = <<<SQL
            SELECT id, uppercats
            FROM categories
            WHERE id IN ({$category_ids_str});
            SQL;
        $uppercats_of = $conf->sql_backend::query2array($query, 'id', 'uppercats');

        foreach (array_keys($uppercats_of) as $cat_id) {
            // find the subcats
            $subcat_ids = [];

            foreach ($uppercats_of as $id => $uppercats) {
                if (preg_match('/(^|,)' . $cat_id . '(,|$)/', $uppercats)) {
                    $subcat_ids[] = $id;
                }
            }

            $to_compare = [];

            foreach ($subcat_ids as $id) {
                if (isset($ref_dates[$id])) {
                    $to_compare[] = $ref_dates[$id];
                }
            }

            if ($to_compare !== []) {
                $ref_dates[$cat_id] = $minmax === 'max' ? max($to_compare) : min($to_compare);
            } else {
                $ref_dates[$cat_id] = null;
            }
        }

        // only return the list of $ids, not the sub-categories
        $return = [];

        foreach ($ids as $id) {
            $return[$id] = $ref_dates[$id];
        }

        return $return;
    }

    public static function UC_name_compare(
        array $a,
        array $b
    ): int {
        return strcmp(strtolower($a['NAME']), strtolower($b['NAME']));
    }

    // get_complete_dir returns the concatenation of get_site_url and
    // get_local_dir
    // Example : "pets > rex > 1_year_old" is on the the same site as the
    // Piwigo files and this category has 22 for identifier
    // get_complete_dir(22) returns "./galleries/pets/rex/1_year_old/"
    public static function get_complete_dir(
        string $category_id
    ): string {
        return self::get_site_url($category_id) . self::get_local_dir($category_id);
    }

    // get_local_dir returns an array with complete path without the site url
    // Example : "pets > rex > 1_year_old" is on the the same site as the
    // Piwigo files and this category has 22 for identifier
    // get_local_dir(22) returns "pets/rex/1_year_old/"
    public static function get_local_dir(
        string $category_id
    ): string {
        global $conf;

        global $page;

        $uppercats = '';
        $local_dir = '';

        if (isset($page['plain_structure'][$category_id]['uppercats'])) {
            $uppercats = $page['plain_structure'][$category_id]['uppercats'];
        } else {
            $query = <<<SQL
                SELECT uppercats
                FROM categories
                WHERE id = {$category_id};
                SQL;
            $row = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));
            $uppercats = $row['uppercats'];
        }

        $upper_array = explode(',', $uppercats);

        $database_dirs = [];
        $query = <<<SQL
            SELECT id, dir
            FROM categories
            WHERE id IN ({$uppercats});
            SQL;
        $result = $conf->sql_backend::pwg_query($query);

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $database_dirs[$row['id']] = $row['dir'];
        }

        foreach ($upper_array as $id) {
            $local_dir .= $database_dirs[$id] . '/';
        }

        return $local_dir;
    }

    // retrieving the site url : "http://domain.com/gallery/" or
    // simply "./galleries/"
    public static function get_site_url(
        string $category_id
    ): string {
        global $page;
        global $conf;

        $query = <<<SQL
            SELECT galleries_url
            FROM sites AS s, categories AS c
            WHERE s.id = c.site_id
                AND c.id = {$category_id};
            SQL;
        $row = $conf->sql_backend::pwg_db_fetch_assoc($conf->sql_backend::pwg_query($query));
        return $row['galleries_url'];
    }

    public static function get_min_local_dir(
        string $local_dir
    ): string {
        $full_dir = explode('/', $local_dir);

        if (count($full_dir) <= 3) {
            return $local_dir;
        }

        $start = $full_dir[0] . '/' . $full_dir[1];
        $end = end($full_dir);
        return $start . '/&hellip;/' . $end;

    }

    public static function order_by_is_local(): bool
    {
        /**
         * @var Config $conf
         */
        $conf = [];
        require __DIR__ . '/../../inc/config_default.php';

        if (file_exists(__DIR__ . '/../../local/config/config.php')) {
            require __DIR__ . '/../../local/config/config.php';
        }

        return isset($conf->order_by) ||
               isset($conf->order_by_inside_category);
    }

    public static function make_consecutive(
        array &$orders,
        int $step = 50
    ): void {
        uasort($orders, fn (float|int $a, float|int $b): float|int => abs($a) - abs($b));

        $crt = 1;

        foreach ($orders as $id => $pos) {
            $orders[$id] = $step * ($pos < 0 ? -$crt : $crt);
            $crt++;
        }
    }

    /**
     * Do timeout treatment in order to finish to send mails
     *
     * @param $post_keyname: key of check_key post array
     * @param $check_key_treated: array of check_key treated
     */
    public static function do_timeout_treatment(
        string $post_keyname,
        array $check_key_treated = []
    ): void {
        global $env_nbm, $base_url, $page, $must_repost;

        if ($env_nbm['is_sendmail_timeout'] && isset($_POST[$post_keyname])) {
            $post_count = count($_POST[$post_keyname]);
            $treated_count = count($check_key_treated);
            if ($treated_count != 0) {
                $time_refresh = ceil((functions::get_moment() - $env_nbm['start_time']) * $post_count / $treated_count);
            } else {
                $time_refresh = 0;
            }

            $_POST[$post_keyname] = array_diff($_POST[$post_keyname], $check_key_treated);
            $must_repost = true;
            $page['errors'][] = functions::l10n_dec(
                'Execution time is out, treatment must be continue [Estimated time: %d second].',
                'Execution time is out, treatment must be continue [Estimated time: %d seconds].',
                $time_refresh
            );
        }

    }

    /**
     * Get the authorized_status for each tab
     * return corresponding status
     */
    public static function get_tab_status(
        string $mode
    ): int {
        $result = ACCESS_WEBMASTER;

        return match ($mode) {
            'param', 'subscribe' => ACCESS_WEBMASTER,
            'send' => ACCESS_ADMINISTRATOR,
            default => ACCESS_WEBMASTER,
        };
    }

    /**
     * Inserting News users
     */
    public static function insert_new_data_user_mail_notification(): void
    {
        global $conf, $page, $env_nbm;

        // Set null mail_address empty
        $query = <<<SQL
            UPDATE users
            SET {$conf->user_fields['email']} = NULL
            WHERE TRIM({$conf->user_fields['email']}) = '';
            SQL;
        $conf->sql_backend::pwg_query($query);

        // null mail_address are not selected in the list
        $query = <<<SQL
            SELECT u.{$conf->user_fields['id']} AS user_id, u.{$conf->user_fields['username']} AS username, u.{$conf->user_fields['email']} AS mail_address
            FROM users AS u
            LEFT JOIN user_mail_notification AS m ON u.{$conf->user_fields['id']} = m.user_id
            WHERE u.{$conf->user_fields['email']} IS NOT NULL
                AND m.user_id IS NULL
            ORDER BY user_id;
            SQL;

        $result = $conf->sql_backend::pwg_query($query);

        if ($conf->sql_backend::pwg_db_num_rows($result) > 0) {
            $inserts = [];
            $check_key_list = [];

            while ($nbm_user = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
                // Calculate key
                $nbm_user['check_key'] = functions_notification_by_mail::find_available_check_key();

                // Save key
                $check_key_list[] = $nbm_user['check_key'];

                // Insert new nbm_users
                $inserts[] = [
                    'user_id' => $nbm_user['user_id'],
                    'check_key' => $nbm_user['check_key'],
                    'enabled' => 'false', // By default if false, set to true with specific functions
                ];

                $page['infos'][] = functions::l10n(
                    'User %s [%s] added.',
                    stripslashes($nbm_user['username']),
                    $nbm_user['mail_address']
                );
            }

            // Insert new nbm_users
            $conf->sql_backend::mass_inserts('user_mail_notification', ['user_id', 'check_key', 'enabled'], $inserts);
            // Update field enabled with specific function
            $check_key_treated = functions_notification_by_mail::do_subscribe_unsubscribe_notification_by_mail(
                true,
                $conf->nbm_default_value_user_enabled,
                $check_key_list
            );

            // On timeout simulate like tabsheet send
            if ($env_nbm['is_sendmail_timeout']) {
                $quoted_check_key_list = functions_notification_by_mail::quote_check_key_list(array_diff($check_key_list, $check_key_treated));

                if (count($quoted_check_key_list) != 0) {
                    $imploded_check_key_list = implode(', ', $quoted_check_key_list);
                    $query = <<<SQL
                        DELETE FROM user_mail_notification
                        WHERE check_key IN ({$imploded_check_key_list});
                        SQL;
                    $result = $conf->sql_backend::pwg_query($query);

                    functions::redirect($base_url . functions_url::get_query_string_diff([], false), functions::l10n('Operation in progress') . "\n" . functions::l10n('Please wait...'));
                }
            }
        }
    }

    /**
     * Apply global functions to mail content
     * return customize mail content rendered
     */
    public static function render_global_customize_mail_content(
        string $customize_mail_content
    ): string {
        global $conf;

        if ($conf->nbm_send_html_mail &&
            ! (str_starts_with($customize_mail_content, '<'))
        ) {
            // On HTML mail, detects if the content are HTML format.
            // If it's plain text format, convert content to readable HTML
            return nl2br(htmlspecialchars($customize_mail_content));
        }

        return $customize_mail_content;

    }

    /**
     * Send mail for notification to all users
     * Return list of "selected" users for 'list_to_send'
     * Return list of "treated" check_key for 'send'
     */
    public static function do_action_send_mail_notification(
        string $action = 'list_to_send',
        array $check_key_list = [],
        string $customize_mail_content = ''
    ): array {
        global $conf, $page, $user, $lang_info, $lang, $env_nbm;
        $return_list = [];

        if (in_array($action, ['list_to_send', 'send'])) {
            [$dbnow] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query('SELECT NOW();'));

            $is_action_send = ($action === 'send');

            // disabled and null mail_address are not selected in the list
            $data_users = functions_notification_by_mail::get_user_notifications('send', $check_key_list);

            // List all if it's define on options or on timeout
            $is_list_all_without_test = ($env_nbm['is_sendmail_timeout'] || $conf->nbm_list_all_enabled_users_to_send);

            // Check if exist news to list user or send mails
            if (! $is_list_all_without_test ||
                $is_action_send
            ) {
                if ($data_users !== []) {
                    $datas = [];
                    if (! isset($customize_mail_content)) {
                        $customize_mail_content = $conf->nbm_complementary_mail_content;
                    }

                    $customize_mail_content =
                      functions_plugins::trigger_change('nbm_render_global_customize_mail_content', $customize_mail_content);
                    // Prepare message after change language
                    if ($is_action_send) {
                        $msg_break_timeout = functions::l10n('Time to send mail is limited. Others mails are skipped.');
                    } else {
                        $msg_break_timeout = functions::l10n('Prepared time for list of users to send mail is limited. Others users are not listed.');
                    }

                    // Begin nbm users environment
                    functions_notification_by_mail::begin_users_env_nbm($is_action_send);
                    foreach ($data_users as $nbm_user) {
                        if (! $is_action_send &&
                            functions_notification_by_mail::check_sendmail_timeout()
                        ) {
                            // Stop fill list on 'list_to_send', if the quota is override
                            $page['infos'][] = $msg_break_timeout;
                            break;
                        }

                        if ($is_action_send &&
                            functions_notification_by_mail::check_sendmail_timeout()
                        ) {
                            // Stop fill list on 'send', if the quota is override
                            $page['errors'][] = $msg_break_timeout;
                            break;
                        }

                        // set env nbm user
                        functions_notification_by_mail::set_user_on_env_nbm($nbm_user, $is_action_send);

                        if ($is_action_send) {
                            $auth = null;
                            $add_url_params = [];
                            $auth_key = functions_user::create_user_auth_key($nbm_user['user_id'], $nbm_user['status']);
                            if ($auth_key !== false) {
                                $auth = $auth_key['auth_key'];
                                $add_url_params['auth'] = $auth;
                            }

                            functions_url::set_make_full_url();
                            // Fill return list of "treated" check_key for 'send'
                            $return_list[] = $nbm_user['check_key'];
                            if ($conf->nbm_send_detailed_content) {
                                $news = functions_notification::news($nbm_user['last_send'], $dbnow, false, $conf->nbm_send_html_mail, $auth);
                                $exist_data = $news !== [];
                            } else {
                                $exist_data = functions_notification::news_exists($nbm_user['last_send'], $dbnow);
                            }

                            if ($exist_data) {
                                $subject = '[' . $conf->gallery_title . '] ' . functions::l10n('New photos added');

                                // Assign current var for nbm mail
                                functions_notification_by_mail::assign_vars_nbm_mail_content($nbm_user);

                                if ($nbm_user['last_send'] !== null) {
                                    $env_nbm['mail_template']->assign(
                                        'content_new_elements_between',
                                        [
                                            'DATE_BETWEEN_1' => $nbm_user['last_send'],
                                            'DATE_BETWEEN_2' => $dbnow,
                                        ]
                                    );
                                } else {
                                    $env_nbm['mail_template']->assign(
                                        'content_new_elements_single',
                                        [
                                            'DATE_SINGLE' => $dbnow,
                                        ]
                                    );
                                }

                                if ($conf->nbm_send_detailed_content) {
                                    $env_nbm['mail_template']->assign('global_new_lines', $news);
                                }

                                $nbm_user_customize_mail_content =
                                  functions_plugins::trigger_change(
                                      'nbm_render_user_customize_mail_content',
                                      $customize_mail_content,
                                      $nbm_user
                                  );

                                if (! empty($nbm_user_customize_mail_content)) {
                                    $env_nbm['mail_template']->assign(
                                        'custom_mail_content',
                                        $nbm_user_customize_mail_content
                                    );
                                }

                                if ($conf->nbm_send_html_mail &&
                                    $conf->nbm_send_recent_post_dates
                                ) {
                                    $recent_post_dates = functions_notification::get_recent_post_dates_array(
                                        $conf->recent_post_dates['NBM']
                                    );

                                    foreach ($recent_post_dates as $date_detail) {
                                        $env_nbm['mail_template']->append(
                                            'recent_posts',
                                            [
                                                'TITLE' => functions_notification::get_title_recent_post_date($date_detail),
                                                'HTML_DATA' => functions_notification::get_html_description_recent_post_date($date_detail, $auth),
                                            ]
                                        );
                                    }
                                }

                                $env_nbm['mail_template']->assign(
                                    [
                                        'GOTO_GALLERY_TITLE' => $conf->gallery_title,
                                        'GOTO_GALLERY_URL' => functions_url::add_url_params(functions_url::get_gallery_home_url(), $add_url_params),
                                        'SEND_AS_NAME' => $env_nbm['send_as_name'],
                                    ]
                                );

                                $ret = functions_mail::pwg_mail(
                                    [
                                        'name' => stripslashes($nbm_user['username']),
                                        'email' => $nbm_user['mail_address'],
                                    ],
                                    [
                                        'from' => $env_nbm['send_as_mail_formated'],
                                        'subject' => $subject,
                                        'email_format' => $env_nbm['email_format'],
                                        'content' => $env_nbm['mail_template']->parse('notification_by_mail', true),
                                        'content_format' => $env_nbm['email_format'],
                                        'auth_key' => $auth,
                                    ]
                                );

                                if ($ret) {
                                    functions_notification_by_mail::inc_mail_sent_success($nbm_user);

                                    $datas[] = [
                                        'user_id' => $nbm_user['user_id'],
                                        'last_send' => $dbnow,
                                    ];
                                } else {
                                    functions_notification_by_mail::inc_mail_sent_failed($nbm_user);
                                }

                                functions_url::unset_make_full_url();
                            }
                        } elseif (functions_notification::news_exists($nbm_user['last_send'], $dbnow)) {
                            // Fill return list of "selected" users for 'list_to_send'
                            $return_list[] = $nbm_user;
                        }

                        // unset env nbm user
                        functions_notification_by_mail::unset_user_on_env_nbm();
                    }

                    // Restore nbm environment
                    functions_notification_by_mail::end_users_env_nbm();
                    if ($is_action_send) {
                        $conf->sql_backend::mass_updates(
                            'user_mail_notification',
                            [
                                'primary' => ['user_id'],
                                'update' => ['last_send'],
                            ],
                            $datas
                        );

                        functions_notification_by_mail::display_counter_info();
                    }
                } elseif ($is_action_send) {
                    $page['errors'][] = functions::l10n('No user to send notifications by mail.');
                }
            } else {
                // Quick List, don't check news
                // Fill return list of "selected" users for 'list_to_send'
                $return_list = $data_users;
            }
        }

        // Return list of "selected" users for 'list_to_send'
        // Return list of "treated" check_key for 'send'
        return $return_list;
    }

    public static function parse_sort_variables(
        array $sortable_by,
        string|null $default_field,
        string $get_param,
        array $get_rejects,
        ?string $template_var,
        string $anchor = ''
    ): array {
        global $template;

        $url_components = parse_url($_SERVER['REQUEST_URI']);

        $base_url = $url_components['path'];

        parse_str($url_components['query'], $vars);
        $is_first = true;

        foreach ($vars as $key => $value) {
            if (! in_array($key, $get_rejects) &&
                $key != $get_param
            ) {
                $base_url .= $is_first ? '?' : '&amp;';
                $is_first = false;

                if (! in_array($key, ['page', 'psf', 'dpsf', 'pwg_token'])) {
                    functions_html::fatal_error('unexpected URL get key');
                }

                $base_url .= urlencode($key) . '=' . urlencode($value);
            }
        }

        $ret = [];

        foreach ($sortable_by as $field) {
            $url = $base_url;
            $disp = '↓'; // TODO: an small image is better

            if ($field !== ($_GET[$get_param] ?? null)) {
                if (! isset($default_field) ||
                    $default_field != $field
                ) { // the first should be the default
                    $url = functions_url::add_url_params($url, [
                        $get_param => $field,
                    ]);
                } elseif (isset($default_field) &&
                          ! isset($_GET[$get_param])
                ) {
                    $ret[] = $field;
                    $disp = '<em>' . $disp . '</em>';
                }
            } else {
                $ret[] = $field;
                $disp = '<em>' . $disp . '</em>';
            }

            if (isset($template_var)) {
                $template->assign(
                    $template_var . strtoupper($field),
                    '<a href="' . $url . $anchor . '" title="' . functions::l10n('Sort order') . '">' . $disp . '</a>'
                );
            }
        }

        return $ret;
    }

    public static function avg_compare(
        array $a,
        array $b
    ): int {
        $d = $a['avg'] - $b['avg'];
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function count_compare(
        array $a,
        array $b
    ): int {
        $d = $a['count'] - $b['count'];
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function cv_compare(
        array $a,
        array $b
    ): int {
        $d = $b['cv'] - $a['cv']; //desc
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function consensus_dev_compare(
        array $a,
        array $b
    ): int {
        $d = $b['cd'] - $a['cd']; //desc
        return ($d == 0) ? 0 : ($d < 0 ? -1 : 1);
    }

    public static function last_rate_compare(
        array $a,
        array $b
    ): int {
        return -strcmp($a['last_date'], $b['last_date']);
    }

    //Get the last unit of time for years, months, days and hours
    public static function get_last(
        int $last_number = 60,
        string $type = 'year'
    ): array {
        global $conf;

        $query = <<<SQL
            SELECT year, month, day, hour, nb_pages
            FROM history_summary

            SQL;

        if ($type === 'hour') {
            $query .= <<<SQL
                WHERE year IS NOT NULL
                    AND month IS NOT NULL
                    AND day IS NOT NULL
                    AND hour IS NOT NULL
                ORDER BY year DESC, month DESC, day DESC, hour DESC
                LIMIT {$last_number}

                SQL;
        } elseif ($type === 'day') {
            $query .= <<<SQL
                WHERE year IS NOT NULL
                    AND month IS NOT NULL
                    AND day IS NOT NULL
                    AND hour IS NULL
                ORDER BY year DESC, month DESC, day DESC
                LIMIT {$last_number}

                SQL;
        } elseif ($type === 'month') {
            $query .= <<<SQL
                WHERE year IS NOT NULL
                    AND month IS NOT NULL
                    AND day IS NULL
                ORDER BY year DESC, month DESC
                LIMIT {$last_number}

                SQL;
        } else {
            $query .= <<<SQL
                WHERE year IS NOT NULL
                    AND month IS NULL
                ORDER BY year DESC
                LIMIT {$last_number}

                SQL;
        }

        $query = trim($query) . ';';
        $result = $conf->sql_backend::pwg_query($query);

        $output = [];

        while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
            $output[] = $row;
        }

        return $output;
    }

    public static function get_month_of_last_years(
        string|int $last = 'all'
    ): array {
        global $conf;

        $query = <<<SQL
            SELECT year, month, day, hour, nb_pages
            FROM history_summary
            WHERE month IS NOT NULL
                AND day IS NULL
            ORDER BY year DESC, month DESC

            SQL;

        if ($last !== 'all') {
            $date = new DateTime();
            $limit = ($last - 1) * 12 + $date->format('n') - 1;
            $query .= " LIMIT {$limit}";
            $result = $conf->sql_backend::query2array($query . ';');
            $lastDate = $date->sub(new DateInterval('P' . ($last - 1) . 'Y' . ($date->format('n') - 1) . 'M'));
            return self::set_missing_values('month', $result, $lastDate, new DateTime());
        }

        if (count($conf->sql_backend::query2array($query . ';')) > 1) {
            return self::set_missing_values('month', $conf->sql_backend::query2array($query . ';'));
        }

        $last_year_date = new DateTime();
        return self::set_missing_values(
            'month',
            $conf->sql_backend::query2array($query . ';'),
            $last_year_date->sub(new DateInterval('P1Y')),
            new DateTime()
        );
    }

    public static function get_month_stats(): array
    {
        global $conf;

        $result = [];
        $date = new DateTime();
        $date_last_month = clone $date;
        $date_last_year = clone $date;
        $months = [];

        $date_last_month->sub(new DateInterval('P1M'));
        $date_last_year->sub(new DateInterval('P1Y'));
        $query = <<<SQL
            SELECT year, month, day, hour, nb_pages
            FROM history_summary
            WHERE
            (
                (year = {$date->format('Y')} AND month = {$date->format('n')})
                OR (year = {$date_last_month->format('Y')} AND month = {$date_last_month->format('n')})
                OR (year = {$date_last_year->format('Y')} AND month = {$date_last_year->format('n')})
            )
                AND day IS NOT NULL
                AND hour IS NULL
            ORDER BY year DESC, month DESC;
            SQL;

        foreach ($conf->sql_backend::query2array($query) as $value) {
            $date = self::get_date_object($value);
            $months[$date->format('Y/m/1')][] = $value;
        }

        $actual_date = new DateTime();

        if (! isset($months[$actual_date->format('Y/m/1')])) {
            $months[$actual_date->format('Y/m/1')][] = [
                'year' => $actual_date->format('Y'),
                'month' => $actual_date->format('n'),
                'day' => null,
                'hour' => null,
                'nb_pages' => 0,
            ];
        }

        foreach ($months as $key => $val) {
            $lastDate = new DateTime($key);
            $lastDate = $lastDate->add(new DateInterval('P1M'));
            $lastDate = $lastDate->sub(new DateInterval('P1D'));

            if ($lastDate > new DateTime()) {
                $lastDate = new DateTime();
            }

            $result['month'][] = self::set_missing_values('day', $val, new DateTime($key), $lastDate);
        }

        $query = <<<SQL
            SELECT AVG(nb_pages)
            FROM history_summary
            WHERE
            (
                year = {$date->format('Y')} OR
                (year = ({$date->format('Y')} - 1) AND month > {$date->format('n')})
            )
                AND day IS NOT NULL
                AND hour IS NULL
                GROUP BY year, month
            ORDER BY year DESC, month DESC;
            SQL;

        [$result['avg']] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

        return $result;
    }

    public static function set_missing_values(
        string $unit,
        array $data,
        ?DateTime $firstDate = null,
        ?DateTime $lastDate = null
    ): array {
        $limit = count($data);
        $result = [];

        $date = $firstDate == null ? self::get_date_object($data[count($data) - 1]) : $firstDate;

        $date_end = $lastDate == null ? self::get_date_object($data[0]) : $lastDate;

        //Declare variable according the unit
        if ($unit === 'year') {
            $date_format = 'Y';
            $date_add = 'P1Y';
        } elseif ($unit === 'month') {
            $date_format = 'Y-m';
            $date_add = 'P1M';
        } elseif ($unit === 'day') {
            $date_format = 'Y-m-d';
            $date_add = 'P1D';
        } elseif ($unit === 'hour') {
            $date_format = 'Y-m-d\TH:00';
            $date_add = 'PT1H';
        }

        //Fill an empty array with all the dates
        while ($date <= $date_end) {
            $result[$date->format($date_format)] = 0;
            $date->add(new DateInterval($date_add));
        }

        //Overload with database rows
        foreach ($data as $value) {
            $str = self::get_date_object($value)->format($date_format);

            if (isset($result[$str])) {
                $result[$str] += $value['nb_pages'];
            }
        }

        return $result;
    }

    //Get a DateTime object for a database row
    public static function get_date_object(
        array $row
    ): DateTime {
        $date_string = $row['year'];

        if ($row['month'] != null) {
            $date_string = $date_string . '-' . $row['month'];

            if ($row['day'] != null) {
                $date_string = $date_string . '-' . $row['day'];

                if ($row['hour'] != null) {
                    $date_string = $date_string . ' ' . $row['hour'] . ':00';
                }
            }
        } else {
            $date_string .= '-1';
        }

        return new DateTime($date_string);
    }

    public static function get_watermark_filename(
        array $list,
        string $candidate,
        int $step = 0
    ): string {
        global $change_name;
        $change_name = $candidate;

        if ($step != 0) {
            $change_name .= '-' . $step;
        }

        if (in_array($change_name, $list)) {
            return self::get_watermark_filename($list, $candidate, $step + 1);
        }

        return $change_name . '.png';
    }
}
