<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'user_activity';
require __DIR__ . '/../admin/inc/user_tabs.php';

if (isset($_GET['type']) &&
    $_GET['type'] == 'download_logs'
) {
    $output_lines = [];

    $query = <<<SQL
        SELECT activity_id, performed_by, object, object_id, action, ip_address, occurred_on, details, {$conf->user_fields['username']} AS username
        FROM activity
        JOIN users AS u ON performed_by = u.{$conf->user_fields['id']}
        ORDER BY activity_id DESC;
        SQL;

    $result = $conf->sql_backend::pwg_query($query);
    $output_lines[] = ['User', 'ID_User', 'Object', 'Object_ID', 'Action', 'Date', 'Hour', 'IP_Address', 'Details'];

    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
        [$date, $hour] = explode(' ', $row['occurred_on']);

        $output_lines[] = [
            'username' => $row['username'],
            'user_id' => $row['performed_by'],
            'object' => $row['object'],
            'object_id' => $row['object_id'],
            'action' => $row['action'],
            'date' => $date,
            'hour' => $hour,
            'ip_address' => $row['ip_address'],
            'details' => $row['details'],
        ];
    }

    header('Content-type: application/csv');
    header('Content-Disposition: attachment; filename=' . date('YmdGis') . 'piwigo_activity_log.csv');
    header('Content-Transfer-Encoding: UTF-8');

    $f = fopen('php://output', 'w');

    foreach ($output_lines as $line) {
        fputcsv($f, $line, ';', escape: '\\');
    }

    fclose($f);

    exit();
}

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+
$template->set_filename('user_activity', 'user_activity.tpl');
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Users'));

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+
$template->assign([
    'PWG_TOKEN' => functions::get_pwg_token(),
    'INHERIT' => $conf->inheritance_by_default,
    'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(['users']),
]);

$query = <<<SQL
    SELECT performed_by, COUNT(*) AS counter
    FROM activity
    WHERE object != 'system'
    GROUP BY performed_by;
    SQL;

$nb_lines_for_user = $conf->sql_backend::query2array($query, 'performed_by', 'counter');

if ($nb_lines_for_user !== []) {
    $ids = implode(', ', array_keys($nb_lines_for_user));
    $query = <<<SQL
        SELECT {$conf->user_fields['id']} AS id, {$conf->user_fields['username']} AS username
        FROM users
        WHERE {$conf->user_fields['id']} IN ({$ids});
        SQL;
}

$username_of = $conf->sql_backend::query2array($query, 'id', 'username');

$filterable_users = [];

foreach ($nb_lines_for_user as $id => $nb_line) {
    $filterable_users[] = [
        'id' => $id,
        'username' => $username_of[$id] ?? 'user#' . $id,
        'nb_lines' => $nb_line,
    ];
}

$template->assign('ulist', $filterable_users);

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM users;
    SQL;

[$nb_users] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));
$template->assign('nb_users', $nb_users);

$cache_keys = functions_admin::get_admin_client_cache_keys(['users']);
$page_data = [
    'usersServerKey' => $cache_keys['users'],
    'usersServerId' => $cache_keys['_hash'],
    'rootUrl' => functions_url::get_root_url(),
    'usersKey' => functions::l10n('Users'),
    'nbUsers' => $nb_users,
    'actionTypes' => [
        'add' => functions::l10n('add'),
        'delete' => functions::l10n('deletion'),
        'move' => functions::l10n('move'),
        'edit' => functions::l10n('edit'),
        'login' => functions::l10n('login'),
        'logout' => functions::l10n('logout'),
    ],
    'actionInfos' => [
        'album' => [
            'added' => functions::l10n('%d album added'),
            'deleted' => functions::l10n('%d album deleted'),
            'edited' => functions::l10n('%d album edited'),
            'moved' => functions::l10n('%d album moved'),
            'addedPlural' => functions::l10n('%d albums added'),
            'deletedPlural' => functions::l10n('%d albums deleted'),
            'editedPlural' => functions::l10n('%d albums edited'),
            'movedPlural' => functions::l10n('%d albums moved'),
        ],
        'photo' => [
            'added' => functions::l10n('%d photo added'),
            'deleted' => functions::l10n('%d photo deleted'),
            'edited' => functions::l10n('%d photo edited'),
            'moved' => functions::l10n('%d photo moved'),
            'addedPlural' => functions::l10n('%d photos added'),
            'deletedPlural' => functions::l10n('%d photos deleted'),
            'editedPlural' => functions::l10n('%d photos edited'),
            'movedPlural' => functions::l10n('%d photos moved'),
        ],
    ],
];
$template->assign('page_data_json', json_encode($page_data));

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['user_activity']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'user_activity');
