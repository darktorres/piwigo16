<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Group\GroupRepository;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var Template $template
 */
global $conf, $template;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('groups');
$tabsheet->select('group_list');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(AccessLevel::Administrator);

if (! empty($_POST) or isset($_GET['delete']) or isset($_GET['toggle_is_default'])) {
    check_pwg_token();
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'group_list' => 'group_list.tpl',
]);

$template->assign(
    [
        'F_ADD_ACTION' => get_root_url() . 'admin.php?page=group_list',
        // 'U_HELP' => get_root_url().'admin/popuphelp.php?page=group_list',
        'PWG_TOKEN' => get_pwg_token(),
        'CACHE_KEYS' => get_admin_client_cache_keys(['groups', 'users']),
    ]
);

// +-----------------------------------------------------------------------+
// |                              group list                               |
// +-----------------------------------------------------------------------+

$group_repo = new GroupRepository(DbConnection::build());
$groups = $group_repo->findAllBasic();

$admin_url = get_root_url() . 'admin.php?page=';
$perm_url = $admin_url . 'group_perm&amp;group_id=';
$users_url = $admin_url . 'user_list&amp;group=';
$del_url = $admin_url . 'group_list&amp;delete=';
$toggle_is_default_url = $admin_url . 'group_list&amp;toggle_is_default=';

$group_counter = 0;

/** @var array<string, string> $user_fields */
$user_fields = $conf['user_fields'];

foreach ($groups as $row) {
    $members = $group_repo->findMemberUsernames($row['id'], $user_fields['username'], $user_fields['id']);

    $template->append(
        'groups',
        [
            'NAME' => $row['name'],
            'ID' => $row['id'],
            'IS_DEFAULT' => ($row['is_default'] ? ' [' . l10n('default') . ']' : ''),
            'NB_MEMBERS' => count($members),
            'L_MEMBERS' => implode(' <span class="userSeparator">&middot;</span> ', $members),
            'MEMBERS' => l10n_dec('%d member', '%d members', count($members)),
            'U_DELETE' => $del_url . $row['id'] . '&amp;pwg_token=' . get_pwg_token(),
            'U_PERM' => $perm_url . $row['id'],
            'U_USERS' => $users_url . $row['id'],
            'U_ISDEFAULT' => $toggle_is_default_url . $row['id'] . '&amp;pwg_token=' . get_pwg_token(),
        ]
    );

    $group_counter++;
}

$template->assign('ADMIN_PAGE_TITLE', l10n('Groups') . ' <span class="badge-number">' . $group_counter . '</span>');

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'group_list');
