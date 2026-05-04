<?php

declare(strict_types=1);
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
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('photo', $_GET, false, PATTERN_ID);
check_input_parameter('album', $_GET, false, PATTERN_ID);
check_input_parameter('group', $_GET, false, PATTERN_ID);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'user_activity';
include(PHPWG_ROOT_PATH.'admin/include/user_tabs.inc.php');


if (isset($_GET['type']) && 'download_logs' == $_GET['type']) {
    $output_lines = [];

    $usernameField = \Piwigo\Config\Config::userFields()['username'];
    $idField = \Piwigo\Config\Config::userFields()['id'];
    $activityRows = \Piwigo\Core\ServiceLocator::get(\Doctrine\DBAL\Connection::class)
        ->executeQuery(
            "SELECT activity_id, performed_by, object, object_id, action, ip_address, occured_on, details,
             $usernameField AS username
             FROM " . ACTIVITY_TABLE . ' JOIN ' . USERS_TABLE .
            " AS u ON performed_by = u.$idField WHERE object = 'user' ORDER BY activity_id DESC"
        )
        ->fetchAllAssociative();

    array_push($output_lines, ['User', 'ID_User', 'Object', 'Object_ID', 'Action', 'Date', 'Hour', 'IP_Address', 'Details']);
    foreach ($activityRows as $row) {
        $row['details'] = str_replace('`groups`', 'groups', is_scalar($row['details']) ? (string)$row['details'] : '');
        $row['details'] = str_replace('`rank`', 'rank', $row['details']);

        [$date, $hour] = explode(' ', is_scalar($row['occured_on']) ? (string) $row['occured_on'] : '');

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
    header('Content-Disposition: attachment; filename='.date('YmdGis').'piwigo_activity_log.csv');
    header('Content-Transfer-Encoding: UTF-8');

    $f = fopen('php://output', 'w');
    if ($f !== false) {
        foreach ($output_lines as $line) {
            fputcsv($f, array_map(fn(mixed $v): string => is_scalar($v) ? (string) $v : '', $line), ';', '"', '\\');
        }
        fclose($f);
    }

    exit();
}

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+
$template->set_filename('user_activity', 'user_activity.tpl');
$template->assign('ADMIN_PAGE_TITLE', l10n('Users'));

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+
$cache_keys = get_admin_client_cache_keys(['users']);
$user_activity_page_data = [
  'CACHE_KEYS' => $cache_keys,
  'ROOT_URL' => get_root_url(),
  'str_create' => l10n('Create'),
];

$template->assign([
  'PWG_TOKEN' => get_pwg_token(),
  'INHERIT' => \Piwigo\Config\Config::inheritanceByDefault(),
  'CACHE_KEYS' => $cache_keys,
  'user_activity_page_data_json' => json_encode($user_activity_page_data),
  ]);

$query = '
SELECT 
    performed_by, 
    COUNT(*) as counter 
  FROM '.ACTIVITY_TABLE.'
  WHERE object != \'system\'
  GROUP BY performed_by
;';

$nb_lines_for_user = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'counter', 'performed_by');

if (count($nb_lines_for_user) > 0) {
    $query = '
  SELECT 
      '.\Piwigo\Config\Config::userFields()['id'].' AS id, 
      '.\Piwigo\Config\Config::userFields()['username'].' AS username 
    FROM '.USERS_TABLE.' 
    WHERE '.\Piwigo\Config\Config::userFields()['id'].' IN ('.implode(',', array_keys($nb_lines_for_user)).');';
}

$username_of = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'username', 'id');

$filterable_users = [];

foreach ($nb_lines_for_user as $id => $nb_line) {
    array_push(
        $filterable_users,
        [
        'id' => $id,
        'username' => $username_of[$id] ?? 'user#' . $id,
        'nb_lines' => $nb_line,
    ]
    );
}
$template->assign('ulist', $filterable_users);

$nb_users = \Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)->countAll(USERS_TABLE);
$template->assign('nb_users', $nb_users);

$actRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Activity\ActivityRepository::class);
$min_date = $actRepo->findOldestDate();
$max_date = $actRepo->findNewestDate();

$template->assign(
    'ACTIVITY_DATES',
    [
    'min' => empty($min_date) ? '' : substr((string) $min_date, 0, 10),
    'max' => empty($max_date) ? '' : substr((string) $max_date, 0, 10),
  ]
);

$additional_filt_type = false;
$additional_filt_name = null;
$additional_filt_value = null;

$additional_filters = [
  'photo' => IMAGES_TABLE,
  'album' => CATEGORIES_TABLE,
  'group' => GROUPS_TABLE,
];

foreach ($additional_filters as $filter_key => $filter_table) {
    if (isset($_GET[$filter_key])) {
        $filterId = is_scalar($_GET[$filter_key]) ? (string) $_GET[$filter_key] : '0';
        $query = '
SELECT
    name
  FROM '.$filter_table.'
  WHERE id = '.$filterId.'
;';
        $rows = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

        if (count($rows) == 0) {
            fatal_error($filter_key.' #'.$filterId.' does not exist');
        }

        $additional_filt_type = $filter_key;
        $additional_filt_name = $rows[0]['name'];
        $additional_filt_value = $filterId;

        break;
    }
}

$template->assign('ADDITIONAL_FILT', [
  'type' => $additional_filt_type,
  'name' => $additional_filt_name,
  'value' => $additional_filt_value,
]);

$query = '
SELECT
    object, 
    action, 
    count(*) AS counter
  FROM '.ACTIVITY_TABLE.'
  WHERE object != \'system\'';

if ($additional_filt_type) {
    $query .= '
    AND object = "'.$additional_filt_type.'"';
}

$query .= '
  GROUP BY 
    action,
    object
  ORDER BY object ASC
  ;';


$actions = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
foreach ($actions as &$action) {
    $action['value'] = (is_scalar($action['object']) ? (string) $action['object'] : '').'/'.(is_scalar($action['action']) ? (string) $action['action'] : '');
}

$template->assign('ACTIONS', $actions);

$template->assign('page_data_json', json_encode([
  'nb_users'                      => (int) $nb_users,
  'additional_filt_type'          => $additional_filt_type ?: null,
  'additional_filt_value'         => $additional_filt_value !== null ? (is_numeric($additional_filt_value) ? (int) $additional_filt_value : 0) : null,
  'date_min'                      => empty($min_date) ? '' : substr((string) $min_date, 0, 10),
  'date_max'                      => empty($max_date) ? '' : substr((string) $max_date, 0, 10),
  'color_icons'                   => ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'],
  'page_ellipsis'                 => '<span>...</span>',
  'page_item'                     => '<a data-page="%d">%d</a>',
  'users_key'                     => l10n('Users'),
  'line_key'                      => l10n('%s line'),
  'lines_key'                     => l10n('%s lines'),
  'actionType_add'                => l10n('add'),
  'actionType_delete'             => l10n('deletion'),
  'actionType_move'               => l10n('move'),
  'actionType_edit'               => l10n('edit'),
  'actionType_login'              => l10n('login'),
  'actionType_logout'             => l10n('logout'),
  'actionInfos_album_added'       => l10n('%d album added'),
  'actionInfos_album_deleted'     => l10n('%d album deleted'),
  'actionInfos_album_edited'      => l10n('%d album edited'),
  'actionInfos_album_moved'       => l10n('%d album moved'),
  'actionInfos_albums_added'      => l10n('%d albums added'),
  'actionInfos_albums_deleted'    => l10n('%d albums deleted'),
  'actionInfos_albums_edited'     => l10n('%d albums edited'),
  'actionInfos_albums_moved'      => l10n('%d albums moved'),
  'actionInfos_user_added'        => l10n('%d user added'),
  'actionInfos_user_deleted'      => l10n('%d user deleted'),
  'actionInfos_user_edited'       => l10n('%d user edited'),
  'actionInfos_user_logged_in'    => l10n('%d user logged in'),
  'actionInfos_user_logged_out'   => l10n('%d user logged out'),
  'actionInfos_users_added'       => l10n('%d users added'),
  'actionInfos_users_deleted'     => l10n('%d users deleted'),
  'actionInfos_users_edited'      => l10n('%d users edited'),
  'actionInfos_users_logged_in'   => l10n('%d users logged in'),
  'actionInfos_users_logged_out'  => l10n('%d users logged out'),
  'actionInfos_photo_added'       => l10n('%d photo added'),
  'actionInfos_photo_deleted'     => l10n('%d photo deleted'),
  'actionInfos_photo_edited'      => l10n('%d photo edited'),
  'actionInfos_photo_moved'       => l10n('%d photo moved'),
  'actionInfos_photos_added'      => l10n('%d photos added'),
  'actionInfos_photos_deleted'    => l10n('%d photos deleted'),
  'actionInfos_photos_edited'     => l10n('%d photos edited'),
  'actionInfos_photos_moved'      => l10n('%d photos moved'),
  'actionInfos_group_added'       => l10n('%d group added'),
  'actionInfos_group_deleted'     => l10n('%d group deleted'),
  'actionInfos_group_edited'      => l10n('%d group edited'),
  'actionInfos_group_moved'       => l10n('%d group moved'),
  'actionInfos_groups_added'      => l10n('%d groups added'),
  'actionInfos_groups_deleted'    => l10n('%d groups deleted'),
  'actionInfos_groups_edited'     => l10n('%d groups edited'),
  'actionInfos_groups_moved'      => l10n('%d groups moved'),
  'actionInfos_tag_added'         => l10n('%d tag added'),
  'actionInfos_tag_deleted'       => l10n('%d tag deleted'),
  'actionInfos_tag_edited'        => l10n('%d tag edited'),
  'actionInfos_tag_moved'         => l10n('%d tag moved'),
  'actionInfos_tags_added'        => l10n('%d tags added'),
  'actionInfos_tags_deleted'      => l10n('%d tags deleted'),
  'actionInfos_tags_edited'       => l10n('%d tags edited'),
  'actionInfos_tags_moved'        => l10n('%d tags moved'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

$template->assign_var_from_handle('ADMIN_CONTENT', 'user_activity');
