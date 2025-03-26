<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
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
        SELECT activity_id, performed_by, object, object_id, action, ip_address, occurred_on, details, {$conf['user_fields']['username']} AS username
        FROM activity
        JOIN users AS u ON performed_by = u.{$conf['user_fields']['id']}
        ORDER BY activity_id DESC;
        SQL;

    $result = functions_mysqli::pwg_query($query);
    array_push($output_lines, ['User', 'ID_User', 'Object', 'Object_ID', 'Action', 'Date', 'Hour', 'IP_Address', 'Details']);

    while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
        $row['details'] = str_replace('`groups`', 'groups', $row['details']);
        $row['details'] = str_replace('`rank`', 'rank', $row['details']);

        list($date, $hour) = explode(' ', $row['occurred_on']);

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
        fputcsv($f, $line, ';');
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
    'INHERIT' => $conf['inheritance_by_default'],
    'CACHE_KEYS' => functions_admin::get_admin_client_cache_keys(['users']),
]);

$query = <<<SQL
    SELECT performed_by, COUNT(*) AS counter
    FROM activity
    WHERE object != 'system'
    GROUP BY performed_by;
    SQL;

$nb_lines_for_user = functions_mysqli::query2array($query, 'performed_by', 'counter');

if (count($nb_lines_for_user) > 0) {
    $ids = implode(', ', array_keys($nb_lines_for_user));
    $query = <<<SQL
        SELECT {$conf['user_fields']['id']} AS id, {$conf['user_fields']['username']} AS username
        FROM users
        WHERE {$conf['user_fields']['id']} IN ({$ids});
        SQL;
}

$username_of = functions_mysqli::query2array($query, 'id', 'username');

$filterable_users = [];

foreach ($nb_lines_for_user as $id => $nb_line) {
    array_push(
        $filterable_users,
        [
            'id' => $id,
            'username' => isset($username_of[$id]) ? $username_of[$id] : 'user#' . $id,
            'nb_lines' => $nb_line,
        ]
    );
}

$template->assign('ulist', $filterable_users);

$query = <<<SQL
    SELECT COUNT(*)
    FROM users;
    SQL;

list($nb_users) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
$template->assign('nb_users', $nb_users);

$template->assign_var_from_handle('ADMIN_CONTENT', 'user_activity');
