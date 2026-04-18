<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('groups');
$tabsheet->select('group_list');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

if (! empty($_POST) ||
    isset($_GET['delete']) ||
    isset($_GET['toggle_is_default'])
) {
    functions::check_pwg_token();
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'group_list' => 'group_list.tpl',
]);

$template->assign(
    [
        'F_ADD_ACTION' => functions_url::get_root_url() . 'admin.php?page=group_list',
        // 'U_HELP' => \Piwigo\inc\functions_url::get_root_url().'admin/popuphelp.php?page=group_list',
        'PWG_TOKEN' => functions::get_pwg_token(),
        'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(['groups', 'users']),
    ]
);

// +-----------------------------------------------------------------------+
// |                              group list                               |
// +-----------------------------------------------------------------------+

$query = <<<SQL
    SELECT id, name, is_default
    FROM user_groups
    ORDER BY name ASC;
    SQL;
$result = $conf->sql_backend::pwg_query($query);

$admin_url = functions_url::get_root_url() . 'admin.php?page=';
$perm_url = $admin_url . 'group_perm&amp;group_id=';
$users_url = $admin_url . 'user_list&amp;group=';
$del_url = $admin_url . 'group_list&amp;delete=';
$toggle_is_default_url = $admin_url . 'group_list&amp;toggle_is_default=';

$group_counter = 0;

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $query = <<<SQL
        SELECT u.{$conf->user_fields['username']} AS username
        FROM users AS u
        INNER JOIN user_group AS ug ON u.{$conf->user_fields['id']} = ug.user_id
        WHERE ug.group_id = {$row['id']};
        SQL;
    $members = [];
    $res = $conf->sql_backend::pwg_query($query);

    while ($us = $conf->sql_backend::pwg_db_fetch_assoc($res)) {
        $members[] = $us['username'];
    }

    $template->append(
        'groups',
        [
            'NAME' => $row['name'],
            'ID' => $row['id'],
            'IS_DEFAULT' => ($conf->sql_backend::get_boolean($row['is_default']) ? ' [' . functions::l10n('default') . ']' : ''),
            'NB_MEMBERS' => count($members),
            'L_MEMBERS' => implode(' <span class="userSeparator">&middot;</span> ', $members),
            'MEMBERS' => functions::l10n_dec('%d member', '%d members', count($members)),
            'U_DELETE' => $del_url . $row['id'] . '&amp;pwg_token=' . functions::get_pwg_token(),
            'U_PERM' => $perm_url . $row['id'],
            'U_USERS' => $users_url . $row['id'],
            'U_ISDEFAULT' => $toggle_is_default_url . $row['id'] . '&amp;pwg_token=' . functions::get_pwg_token(),
        ]
    );

    $group_counter++;
}

$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Groups') . ' <span class="badge-number">' . $group_counter . '</span>');

$cache_keys = functions_admin::get_admin_client_cache_keys(['groups', 'users']);
$page_data = [
    'pwgToken' => functions::get_pwg_token(),
    'strMemberDefault' => functions::l10n('member'),
    'strMembersDefault' => functions::l10n('members'),
    'strGroupCreated' => functions::l10n('Group added'),
    'strRenamingDone' => functions::l10n('Group renamed'),
    'strNameTaken' => functions::l10n('Name is already taken'),
    'strNameNotEmpty' => functions::l10n('Name field must not be empty'),
    'strGroupDeleted' => functions::l10n('Group "%s" successfully deleted'),
    'strGroupsDeleted' => functions::l10n('Groups {%s} successfully deleted'),
    'strSetDefault' => functions::l10n('Set as group for new users'),
    'strUnsetDefault' => functions::l10n('Unset as group for new users'),
    'strDelete' => functions::l10n('Are you sure you want to delete group "%s"?'),
    'strYesDeleteConfirmation' => functions::l10n('Yes, delete'),
    'strNoDeleteConfirmation' => functions::l10n('No, I have changed my mind'),
    'strUserAssociated' => functions::l10n('User associated'),
    'strUserDissociate' => functions::l10n('Dissociate user from this group'),
    'strUserDissociated' => functions::l10n('User "%s" dissociated from this group'),
    'strUserList' => functions::l10n('Manage the members'),
    'strMergedInto' => functions::l10n('Group(s) {%s1} successfully merged into "%s2"'),
    'strCopy' => functions::l10n(' (copy)'),
    'strOtherCopy' => functions::l10n(' (copy %s)'),
    'serverKey' => $cache_keys['users'],
    'serverId' => $cache_keys['_hash'],
    'rootUrl' => functions_url::get_root_url(),
];
$template->assign('page_data_json', json_encode($page_data));

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['group_list', 'pwgConfirm']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'group_list');
