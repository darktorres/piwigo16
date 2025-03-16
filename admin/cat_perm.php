<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                       variable initialization                         |
// +-----------------------------------------------------------------------+

$page['cat'] = $category['id'];

// +-----------------------------------------------------------------------+
// |                           form submission                             |
// +-----------------------------------------------------------------------+

if (! empty($_POST)) {
    functions::check_pwg_token();

    if ($category['status'] != $_POST['status'] or ($category['status'] != 'public' and isset($_POST['apply_on_sub']))) {
        $cat_ids = [$page['cat']];
        if (isset($_POST['apply_on_sub'])) {
            $cat_ids = array_merge($cat_ids, functions_category::get_subcat_ids([$page['cat']]));
        }
        functions_admin::set_cat_status($cat_ids, $_POST['status']);
        $category['status'] = $_POST['status'];
    }

    if ($_POST['status'] == 'private') {
        //
        // manage groups
        //
        $query = '
SELECT group_id
  FROM ' . GROUP_ACCESS_TABLE . '
  WHERE cat_id = ' . $page['cat'] . '
;';
        $groups_granted = functions::array_from_query($query, 'group_id');

        if (! isset($_POST['groups'])) {
            $_POST['groups'] = [];
        }

        //
        // remove permissions to groups
        //
        $deny_groups = array_diff($groups_granted, $_POST['groups']);
        if (count($deny_groups) > 0) {
            // if you forbid access to an album, all sub-albums become
            // automatically forbidden
            $query = '
DELETE
  FROM ' . GROUP_ACCESS_TABLE . '
  WHERE group_id IN (' . implode(',', $deny_groups) . ')
    AND cat_id IN (' . implode(',', functions_category::get_subcat_ids([$page['cat']])) . ')
;';
            functions_mysqli::pwg_query($query);
        }

        //
        // add permissions to groups
        //
        $grant_groups = $_POST['groups'];
        if (count($grant_groups) > 0) {
            $cat_ids = functions_admin::get_uppercat_ids([$page['cat']]);
            if (isset($_POST['apply_on_sub'])) {
                $cat_ids = array_merge($cat_ids, functions_category::get_subcat_ids([$page['cat']]));
            }

            $query = '
SELECT id
  FROM ' . CATEGORIES_TABLE . '
  WHERE id IN (' . implode(',', $cat_ids) . ')
    AND status = \'private\'
;';
            $private_cats = functions::array_from_query($query, 'id');

            $inserts = [];
            foreach ($private_cats as $cat_id) {
                foreach ($grant_groups as $group_id) {
                    $inserts[] = [
                        'group_id' => $group_id,
                        'cat_id' => $cat_id,
                    ];
                }
            }

            functions_mysqli::mass_inserts(
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
        $users_granted = functions::array_from_query($query, 'user_id');

        if (! isset($_POST['users'])) {
            $_POST['users'] = [];
        }

        //
        // remove permissions to users
        //
        $deny_users = array_diff($users_granted, $_POST['users']);
        if (count($deny_users) > 0) {
            // if you forbid access to an album, all sub-album become automatically
            // forbidden
            $query = '
DELETE
  FROM ' . USER_ACCESS_TABLE . '
  WHERE user_id IN (' . implode(',', $deny_users) . ')
    AND cat_id IN (' . implode(',', functions_category::get_subcat_ids([$page['cat']])) . ')
;';
            functions_mysqli::pwg_query($query);
        }

        //
        // add permissions to users
        //
        $grant_users = $_POST['users'];
        if (count($grant_users) > 0) {
            functions_admin::add_permission_on_category($page['cat'], $grant_users);
        }
    }

    $page['infos'][] = functions::l10n('Album updated successfully');
}

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+

$template->set_filename('cat_perm', 'cat_perm.tpl');

$template->assign(
    [
        'CATEGORIES_NAV' =>
          functions_html::get_cat_display_name_from_id(
              $page['cat'],
              'admin.php?page=album-'
          ),
        'U_HELP' => functions_url::get_root_url() . 'admin/popuphelp.php?page=cat_perm',
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
$groups = functions::simple_hash_from_query($query, 'id', 'name');
$template->assign('groups', $groups);

// groups granted to access the category
$query = '
SELECT group_id
  FROM ' . GROUP_ACCESS_TABLE . '
  WHERE cat_id = ' . $page['cat'] . '
;';
$group_granted_ids = functions::array_from_query($query, 'group_id');
$template->assign('groups_selected', $group_granted_ids);

// users...
$users = [];

$query = '
SELECT ' . $conf['user_fields']['id'] . ' AS id,
       ' . $conf['user_fields']['username'] . ' AS username
  FROM ' . USERS_TABLE . '
;';
$users = functions::simple_hash_from_query($query, 'id', 'username');
$template->assign('users', $users);

$query = '
SELECT user_id
  FROM ' . USER_ACCESS_TABLE . '
  WHERE cat_id = ' . $page['cat'] . '
;';
$user_granted_direct_ids = functions::array_from_query($query, 'user_id');
$template->assign('users_selected', $user_granted_direct_ids);

$user_granted_indirect_ids = [];
if (count($group_granted_ids) > 0) {
    $granted_groups = [];

    $query = '
SELECT user_id, group_id
  FROM ' . USER_GROUP_TABLE . '
  WHERE group_id IN (' . implode(',', $group_granted_ids) . ')
';
    $result = functions_mysqli::pwg_query($query);
    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
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
            if (in_array($user_id, $user_granted_indirect_ids)) {
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
    'PWG_TOKEN' => functions::get_pwg_token(),
    'INHERIT' => $conf['inheritance_by_default'],
    'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(['groups', 'users']),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'cat_perm');
