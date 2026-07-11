<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $admin_album_base_url and $category are set by
// admin/album.php before including this panel; $page originates in
// admin.php and is further populated by admin/album.php ($page['tab'])
// before reaching this panel; the rest by include/common.inc.php.
/**
 * @var string $admin_album_base_url
 * @var array<string, string|null> $category
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 */
global $admin_album_base_url, $category, $conf, $template, $page;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                       variable initialization                         |
// +-----------------------------------------------------------------------+

$page['cat'] = (int) $category['id'];

// +-----------------------------------------------------------------------+
// |                           form submission                             |
// +-----------------------------------------------------------------------+

if (! empty($_POST)) {
    check_pwg_token();

    // the status <select> always submits this field; fall back to an empty
    // string (never matches 'public'/'private') on malformed input
    $post_status = isset($_POST['status']) && is_string($_POST['status']) ? $_POST['status'] : '';

    if ($category['status'] != $post_status or ($category['status'] != 'public' and isset($_POST['apply_on_sub']))) {
        $cat_ids = [$page['cat']];
        if (isset($_POST['apply_on_sub'])) {
            $cat_ids = array_merge($cat_ids, get_subcat_ids([$page['cat']]));
        }
        set_cat_status($cat_ids, $post_status);
        $category['status'] = $post_status;
    }

    if ($post_status == 'private') {
        //
        // manage groups
        //
        $query = '
SELECT group_id
  FROM ' . GROUP_ACCESS_TABLE . '
  WHERE cat_id = ' . $page['cat'] . '
;';
        // array_from_query()'s own return type is declared array<int|string,
        // mixed>; narrow to the real int ids (DB values are string|null per
        // this driver, group_id is a NOT NULL numeric column)
        $groups_granted = [];
        foreach (array_from_query($query, 'group_id') as $raw_group_id) {
            if (is_numeric($raw_group_id)) {
                $groups_granted[] = (int) $raw_group_id;
            }
        }

        $post_groups = [];
        if (isset($_POST['groups']) && is_array($_POST['groups'])) {
            foreach ($_POST['groups'] as $raw_group_id) {
                if (is_numeric($raw_group_id)) {
                    $post_groups[] = (int) $raw_group_id;
                }
            }
        }

        //
        // remove permissions to groups
        //
        $deny_groups = array_diff($groups_granted, $post_groups);
        if (count($deny_groups) > 0) {
            // if you forbid access to an album, all sub-albums become
            // automatically forbidden
            $query = '
DELETE
  FROM ' . GROUP_ACCESS_TABLE . '
  WHERE group_id IN (' . implode(',', $deny_groups) . ')
    AND cat_id IN (' . implode(',', get_subcat_ids([$page['cat']])) . ')
;';
            pwg_query($query);
        }

        //
        // add permissions to groups
        //
        $grant_groups = $post_groups;
        if (count($grant_groups) > 0) {
            $cat_ids = get_uppercat_ids([$page['cat']]);
            if (isset($_POST['apply_on_sub'])) {
                $cat_ids = array_merge($cat_ids, get_subcat_ids([$page['cat']]));
            }

            $query = '
SELECT id
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', $cat_ids) . ')
    AND status = \'private\'
;';
            $private_cats = array_from_query($query, 'id');

            $inserts = [];
            foreach ($private_cats as $cat_id) {
                foreach ($grant_groups as $group_id) {
                    $inserts[] = [
                        'group_id' => $group_id,
                        'cat_id' => $cat_id,
                    ];
                }
            }

            mass_inserts(
                GROUP_ACCESS_TABLE,
                ['group_id', 'cat_id'],
                $inserts,
                [
                    'ignore' => true,
                ]
            );
        }

        //
        // users
        //
        $query = '
SELECT user_id
  FROM ' . USER_ACCESS_TABLE . '
  WHERE cat_id = ' . $page['cat'] . '
;';
        // array_from_query()'s own return type is declared array<int|string,
        // mixed>; narrow to the real int ids (DB values are string|null per
        // this driver, user_id is a NOT NULL numeric column)
        $users_granted = [];
        foreach (array_from_query($query, 'user_id') as $raw_user_id) {
            if (is_numeric($raw_user_id)) {
                $users_granted[] = (int) $raw_user_id;
            }
        }

        $post_users = [];
        if (isset($_POST['users']) && is_array($_POST['users'])) {
            foreach ($_POST['users'] as $raw_user_id) {
                if (is_numeric($raw_user_id)) {
                    $post_users[] = (int) $raw_user_id;
                }
            }
        }

        //
        // remove permissions to users
        //
        $deny_users = array_diff($users_granted, $post_users);
        if (count($deny_users) > 0) {
            // if you forbid access to an album, all sub-album become automatically
            // forbidden
            $query = '
DELETE
  FROM ' . USER_ACCESS_TABLE . '
  WHERE user_id IN (' . implode(',', $deny_users) . ')
    AND cat_id IN (' . implode(',', get_subcat_ids([$page['cat']])) . ')
;';
            pwg_query($query);
        }

        //
        // add permissions to users
        //
        $grant_users = $post_users;
        if (count($grant_users) > 0) {
            add_permission_on_category($page['cat'], $grant_users);
        }
    }

    $template->assign(
        [
            'save_success' => l10n('Album updated successfully'),
        ]
    );

}

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+

$template->set_filename('cat_perm', 'cat_perm.tpl');

$template->assign(
    [
        'CATEGORIES_NAV' => get_cat_display_name_from_id(
            $page['cat'],
            'admin.php?page=album-'
        ),
        'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=cat_perm',
        'F_ACTION' => $admin_album_base_url . '-permissions',
        'private' => ($category['status'] == 'private'),
    ]
);

// +-----------------------------------------------------------------------+
// |                          form construction                            |
// +-----------------------------------------------------------------------+

// groups denied are the groups not granted. So we need to find all groups
// minus groups granted to find groups denied.

$groups = [];

$query = '
SELECT id, name
  FROM `' . GROUPS_TABLE . '`
  ORDER BY name ASC
;';
$groups = simple_hash_from_query($query, 'id', 'name');
$template->assign('groups', $groups);

// groups granted to access the category
$query = '
SELECT group_id
  FROM ' . GROUP_ACCESS_TABLE . '
  WHERE cat_id = ' . $page['cat'] . '
;';
// array_from_query()'s own return type is declared array<int|string, mixed>;
// narrow to the real int ids (DB values are string|null per this driver,
// group_id is a NOT NULL numeric column)
$group_granted_ids = [];
foreach (array_from_query($query, 'group_id') as $raw_group_id) {
    if (is_numeric($raw_group_id)) {
        $group_granted_ids[] = (int) $raw_group_id;
    }
}
$template->assign('groups_selected', $group_granted_ids);

// users...
$users = [];

// $conf['user_fields'] maps generic field names to table-specific column
// names (see include/config_default.inc.php); every value is a plain
// string.
$user_fields_raw = $conf['user_fields'];
$user_fields = [];
if (is_array($user_fields_raw)) {
    foreach ($user_fields_raw as $field_key => $field_value) {
        if (is_string($field_key) and is_string($field_value)) {
            $user_fields[$field_key] = $field_value;
        }
    }
}
$user_field_id = $user_fields['id'] ?? 'id';
$user_field_username = $user_fields['username'] ?? 'username';

$query = '
SELECT ' . $user_field_id . ' AS id,
       ' . $user_field_username . ' AS username
  FROM ' . USERS_TABLE . '
;';
$users = simple_hash_from_query($query, 'id', 'username');
$template->assign('users', $users);

$query = '
SELECT user_id
  FROM ' . USER_ACCESS_TABLE . '
  WHERE cat_id = ' . $page['cat'] . '
;';
// array_from_query()'s own return type is declared array<int|string, mixed>;
// narrow to the real int ids (DB values are string|null per this driver,
// user_id is a NOT NULL numeric column)
$user_granted_direct_ids = [];
foreach (array_from_query($query, 'user_id') as $raw_user_id) {
    if (is_numeric($raw_user_id)) {
        $user_granted_direct_ids[] = (int) $raw_user_id;
    }
}
$template->assign('users_selected', $user_granted_direct_ids);

$user_granted_indirect_ids = [];
if (count($group_granted_ids) > 0) {
    $granted_groups = [];

    $query = '
SELECT user_id, group_id
  FROM ' . USER_GROUP_TABLE . '
  WHERE group_id IN (' . implode(',', $group_granted_ids) . ')
';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // group_id/user_id are NOT NULL numeric columns; this driver
        // returns every column as string|null, so guard before using
        // group_id as an array key and collecting user_id
        if (! is_string($row['group_id']) || ! is_string($row['user_id'])) {
            continue;
        }
        if (! isset($granted_groups[$row['group_id']])) {
            $granted_groups[$row['group_id']] = [];
        }
        $granted_groups[$row['group_id']][] = $row['user_id'];
    }

    $user_granted_by_group_ids = [];

    foreach ($granted_groups as $group_users) {
        $user_granted_by_group_ids = array_merge($user_granted_by_group_ids, $group_users);
    }

    $user_granted_by_group_ids = array_unique($user_granted_by_group_ids);

    $user_granted_indirect_ids = array_diff(
        $user_granted_by_group_ids,
        $user_granted_direct_ids
    );

    $template->assign('nb_users_granted_indirect', count($user_granted_indirect_ids));

    foreach ($granted_groups as $group_id => $group_users) {
        $group_usernames = [];
        foreach ($group_users as $user_id) {
            // simple_hash_from_query()'s own return type is declared
            // array<int|string, mixed>; narrow to the real username string
            if (in_array($user_id, $user_granted_indirect_ids) && isset($users[$user_id]) && is_string($users[$user_id])) {
                $group_usernames[] = $users[$user_id];
            }
        }

        $template->append(
            'user_granted_indirect_groups',
            [
                'group_name' => $groups[$group_id],
                'group_users' => implode(', ', $group_usernames),
            ]
        );
    }
}

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+
$template->assign([
    'PWG_TOKEN' => get_pwg_token(),
    'INHERIT' => $conf['inheritance_by_default'],
    'CACHE_KEYS' => get_admin_client_cache_keys(['groups', 'users']),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'cat_perm');
