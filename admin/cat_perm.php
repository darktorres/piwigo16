<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;
use Piwigo\Exception\ValidationException;
use Piwigo\Core\ServiceLocator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Config\Config;
use Piwigo\Group\GroupRepository;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $category, $admin_album_base_url;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                       variable initialization                         |
// +-----------------------------------------------------------------------+

check_input_parameter('cat_id', $_GET, false, PATTERN_ID);
$cat_id = is_scalar($_GET['cat_id'] ?? null) ? (string)$_GET['cat_id'] : '';
if (empty($cat_id)) {
    throw new ValidationException('No category selected');
}

$category = get_cat_info((int)$cat_id);
if ($category === null) {
    throw new ValidationException('Invalid category');
}
$pageCat = $category['id'];
$pageCat = is_numeric($pageCat ?? null) ? (int) $pageCat : 0;

// +-----------------------------------------------------------------------+
// |                           form submission                             |
// +-----------------------------------------------------------------------+

if (!empty($_POST)) {
    check_pwg_token();

    $post_status_raw = $_POST['status'] ?? null;
    $post_status = is_scalar($post_status_raw) ? (string) $post_status_raw : '';
    if ($category['status'] != $post_status or ($category['status'] != 'public' and isset($_POST['apply_on_sub']))) {
        $cat_ids = [$pageCat];
        if (isset($_POST['apply_on_sub'])) {
            $cat_ids = array_merge($cat_ids, get_subcat_ids([$pageCat]));
        }
        set_cat_status($cat_ids, $post_status);
        $category['status'] = $post_status;
    }

    if ('private' == $post_status) {
        //
        // manage groups
        //
        $query = '
SELECT group_id
  FROM '.GROUP_ACCESS_TABLE.'
  WHERE cat_id = '.$pageCat.'
;';
        $groups_granted = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'group_id');

        if (!isset($_POST['groups'])) {
            $_POST['groups'] = [];
        }

        /** @var int[] $post_groups */
        $post_groups = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['groups']) ? $_POST['groups'] : []);
        /** @var int[] $groups_granted_int */
        $groups_granted_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $groups_granted);

        //
        // remove permissions to groups
        //
        $deny_groups = array_diff($groups_granted_int, $post_groups);
        if (count($deny_groups) > 0) {
            // if you forbid access to an album, all sub-albums become automatically forbidden
            ServiceLocator::get(PermissionRepository::class)
                ->deleteGroupAccess(
                    array_map(intval(...), $deny_groups),
                    array_map(intval(...), get_subcat_ids([$pageCat]))
                );
        }

        //
        // add permissions to groups
        //
        $grant_groups = $post_groups;
        if (count($grant_groups) > 0) {
            $cat_ids = get_uppercat_ids([$pageCat]);
            if (isset($_POST['apply_on_sub'])) {
                $cat_ids = array_merge($cat_ids, get_subcat_ids([$pageCat]));
            }

            $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id IN ('.implode(',', array_map(fn (int|string $v): string => (string) $v, $cat_ids)).')
    AND status = \'private\'
;';
            $private_cats = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'id');

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
                ['group_id','cat_id'],
                $inserts,
                ['ignore' => true]
            );
        }

        //
        // users
        //
        $query = '
SELECT user_id
  FROM '.USER_ACCESS_TABLE.'
  WHERE cat_id = '.$pageCat.'
;';
        $users_granted = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'user_id');

        if (!isset($_POST['users'])) {
            $_POST['users'] = [];
        }

        /** @var int[] $post_users */
        $post_users = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($_POST['users']) ? $_POST['users'] : []);
        /** @var int[] $users_granted_int */
        $users_granted_int = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $users_granted);

        //
        // remove permissions to users
        //
        $deny_users = array_diff($users_granted_int, $post_users);
        if (count($deny_users) > 0) {
            // if you forbid access to an album, all sub-album become automatically forbidden
            ServiceLocator::get(PermissionRepository::class)
                ->deleteUserAccess(
                    array_map(intval(...), $deny_users),
                    array_map(intval(...), get_subcat_ids([$pageCat]))
                );
        }

        //
        // add permissions to users
        //
        $grant_users = $post_users;
        if (count($grant_users) > 0) {
            add_permission_on_category($pageCat, $grant_users);
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
    'CATEGORIES_NAV' =>
      get_cat_display_name_from_id(
          $pageCat,
          'admin.php?page=album-'
      ),
    'U_HELP' => get_root_url().'admin/popuphelp.php?page=cat_perm',
    'F_ACTION' => $admin_album_base_url.'-permissions',
    'private' => ('private' == $category['status']),
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
  FROM `'.GROUPS_TABLE.'`
  ORDER BY name ASC
;';
$groups = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'name', 'id');
$template->assign('groups', $groups);

// groups granted to access the category
$query = '
SELECT group_id
  FROM '.GROUP_ACCESS_TABLE.'
  WHERE cat_id = '.$pageCat.'
;';
$group_granted_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'group_id');
$template->assign('groups_selected', $group_granted_ids);

// users...
$users = [];

$query = '
SELECT '.Config::userFields()['id'].' AS id,
       '.Config::userFields()['username'].' AS username
  FROM '.USERS_TABLE.'
;';
$users = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'username', 'id');
$template->assign('users', $users);


$query = '
SELECT user_id
  FROM '.USER_ACCESS_TABLE.'
  WHERE cat_id = '.$pageCat.'
;';
$user_granted_direct_ids = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'user_id');
$template->assign('users_selected', $user_granted_direct_ids);


$user_granted_indirect_ids = [];
if (count($group_granted_ids) > 0) {
    $granted_groups = [];

    foreach (ServiceLocator::get(GroupRepository::class)
        ->findUserGroupMembersByGroupIds(array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $group_granted_ids)) as $row) {
        $row_group_id = is_scalar($row['group_id']) ? (string) $row['group_id'] : '';
        if (!isset($granted_groups[$row_group_id])) {
            $granted_groups[$row_group_id] = [];
        }
        $granted_groups[$row_group_id][] = is_scalar($row['user_id']) ? (string) $row['user_id'] : '';
    }

    $user_granted_by_group_ids = [];

    foreach ($granted_groups as $group_users) {
        $user_granted_by_group_ids = array_merge($user_granted_by_group_ids, $group_users);
    }

    $user_granted_by_group_ids = array_unique($user_granted_by_group_ids);

    $user_granted_indirect_ids = array_diff(
        $user_granted_by_group_ids,
        array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $user_granted_direct_ids)
    );

    $template->assign('nb_users_granted_indirect', count($user_granted_indirect_ids));

    foreach ($granted_groups as $group_id => $group_users) {
        $group_usernames = [];
        foreach ($group_users as $user_id) {
            if (in_array($user_id, $user_granted_indirect_ids)) {
                $user_key = $user_id;
                $group_usernames[] = isset($users[$user_key]) ? (is_scalar($users[$user_key]) ? (string) $users[$user_key] : '') : '';
            }
        }

        $template->append(
            'user_granted_indirect_groups',
            [
            'group_name' => isset($groups[$group_id]) ? (is_scalar($groups[$group_id]) ? (string) $groups[$group_id] : '') : '',
            'group_users' => implode(', ', $group_usernames),
            ]
        );
    }
}

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+
$cache_keys = get_admin_client_cache_keys(['groups', 'users']);
$cat_perm_page_data = [
  'CACHE_KEYS' => $cache_keys,
  'ROOT_URL' => get_root_url(),
  'str_create' => l10n('Create'),
  'has_indirect_perms' => count($user_granted_indirect_ids) > 0,
];

$template->assign([
  'PWG_TOKEN' => get_pwg_token(),
  'INHERIT' => Config::inheritanceByDefault(),
  'CACHE_KEYS' => $cache_keys,
  'cat_perm_page_data_json' => json_encode($cat_perm_page_data),
  ]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'cat_perm');
