<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new Tabsheet();
$tabsheet->set_id('groups');
$tabsheet->select('group_list');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

if (!empty($_POST) or isset($_GET['delete']) or isset($_GET['toggle_is_default'])) {
    check_pwg_token();
}


// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(['group_list' => 'group_list.tpl']);

$cache_keys = get_admin_client_cache_keys(['groups', 'users']);

$group_list_page_data = [
  'CACHE_KEYS' => $cache_keys,
  'ROOT_URL' => get_root_url(),
  'str_create' => l10n('Create'),
];

$template->assign(
    [
    'F_ADD_ACTION' => get_root_url().'admin.php?page=group_list',
    // 'U_HELP' => get_root_url().'admin/popuphelp.php?page=group_list',
    'PWG_TOKEN' => get_pwg_token(),
    'CACHE_KEYS' => $cache_keys,
    'ROOT_URL' => get_root_url(),
    'group_list_page_data_json' => json_encode($group_list_page_data),
    'page_data_json' => json_encode([
        'pwg_token' => get_pwg_token(),
        'rootUrl' => get_root_url(),
        'serverId' => $cache_keys['_hash'],
        'serverKey' => $cache_keys['users'],
        'str_copy' => l10n(' (copy)'),
        'str_delete' => l10n('Are you sure you want to delete group "%s"?'),
        'str_group_created' => l10n('Group added'),
        'str_group_deleted' => l10n('Group "%s" succesfully deleted'),
        'str_groups_deleted' => l10n('Groups {%s} succesfully deleted'),
        'str_member_default' => l10n('member'),
        'str_members_default' => l10n('members'),
        'str_merged_into' => l10n('Group(s) {%s1} succesfully merged into "%s2"'),
        'str_name_not_empty' => l10n('Name field must not be empty'),
        'str_name_taken' => l10n('Name is already taken'),
        'str_no_delete_confirmation' => l10n('No, I have changed my mind'),
        'str_other_copy' => l10n(' (copy %s)'),
        'str_renaming_done' => l10n('Group renamed'),
        'str_set_default' => l10n('Set as group for new users'),
        'str_unset_default' => l10n('Unset as group for new users'),
        'str_user_associated' => l10n('User associated'),
        'str_user_dissociate' => l10n('Dissociate user from this group'),
        'str_user_dissociated' => l10n('User "%s" dissociated from this group'),
        'str_user_list' => l10n('Manage the members'),
        'str_yes_delete_confirmation' => l10n('Yes, delete'),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
    ]
);

// +-----------------------------------------------------------------------+
// |                              group list                               |
// +-----------------------------------------------------------------------+

$query = '
SELECT id, name, is_default
  FROM `'.GROUPS_TABLE.'`
  ORDER BY name ASC
;';
$result = pwg_query($query);

$admin_url = get_root_url().'admin.php?page=';
$perm_url    = $admin_url.'group_perm&amp;group_id=';
$users_url = $admin_url.'user_list&amp;group=';
$del_url     = $admin_url.'group_list&amp;delete=';
$toggle_is_default_url     = $admin_url.'group_list&amp;toggle_is_default=';

$group_counter = 0;

while ($row = pwg_db_fetch_assoc($result)) {
    $query = '
SELECT u.'. \Piwigo\Config\Config::userFields()['username'].' AS username
  FROM '.USERS_TABLE.' AS u
  INNER JOIN '.USER_GROUP_TABLE.' AS ug
    ON u.'.\Piwigo\Config\Config::userFields()['id'].' = ug.user_id
  WHERE ug.group_id = '.$row['id'].'
;';
    $members = [];
    $res = pwg_query($query);
    while ($us = pwg_db_fetch_assoc($res)) {
        $members[] = $us['username'];
    }
    $template->append(
        'groups',
        [
        'NAME' => $row['name'],
        'ID' => $row['id'],
        'IS_DEFAULT' => (get_boolean($row['is_default']) ? ' ['.l10n('default').']' : ''),
        'NB_MEMBERS' => count($members),
        'L_MEMBERS' => implode(' <span class="userSeparator">&middot;</span> ', $members),
        'MEMBERS' => l10n_dec('%d member', '%d members', count($members)),
        'U_DELETE' => $del_url.$row['id'].'&amp;pwg_token='.get_pwg_token(),
        'U_PERM' => $perm_url.$row['id'],
        'U_USERS' => $users_url.$row['id'],
        'U_ISDEFAULT' => $toggle_is_default_url.$row['id'].'&amp;pwg_token='.get_pwg_token(),
        ]
    );

    $group_counter++;
}

$template->assign('ADMIN_PAGE_TITLE', l10n('Groups').' <span class="badge-number">'.$group_counter.'</span>');

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'group_list');
