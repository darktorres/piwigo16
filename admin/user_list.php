<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Add users and manage users list
 */

use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

functions::check_input_parameter('group', $_GET, false, PATTERN_ID);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'user_list';
require __DIR__ . '/../admin/inc/user_tabs.php';

// +-----------------------------------------------------------------------+
// |                              groups list                              |
// +-----------------------------------------------------------------------+

$groups = [];

$query = <<<SQL
    SELECT id, name
    FROM user_groups
    ORDER BY name ASC;
    SQL;
$result = $conf->sql_backend::pwg_query($query);

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $groups[$row['id']] = $row['name'];
}

// +-----------------------------------------------------------------------+
// |                              Dates for filtering                      |
// +-----------------------------------------------------------------------+

$query = <<<SQL
    SELECT DISTINCT

    SQL;

if ($conf->dblayer === 'mysqli') {
    $query .= <<<SQL
        MONTH(registration_date) AS registration_month, YEAR(registration_date) AS registration_year, registration_date

        SQL;
}

if ($conf->dblayer === 'pgsql') {
    $query .= <<<SQL
        EXTRACT(MONTH FROM registration_date) AS registration_month, EXTRACT(YEAR FROM registration_date) AS registration_year, registration_date

        SQL;
}

$query .= <<<SQL
    FROM user_infos
    ORDER BY registration_date;
    SQL;
$result = $conf->sql_backend::pwg_query($query);

$register_dates = [];

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $register_dates[] = $row['registration_year'] . '-' . sprintf('%02u', $row['registration_month']);
}

$template->assign('register_dates', implode(',', $register_dates));

// +-----------------------------------------------------------------------+
// | template                                                              |
// +-----------------------------------------------------------------------+
$template->assign(
    [
        'ADMIN_PAGE_TITLE' => functions::l10n('Users'),
        'ACTIVATE_COMMENTS' => $conf->activate_comments,
        'Double_Password' => $conf->double_password_type_in_admin,
    ]
);

$template->set_filenames([
    'user_list' => 'user_list.tpl',
]);

$default_user = functions_user::get_default_user_info();

$protected_users = [
    $user['id'],
    $conf->guest_id,
    $conf->default_user_id,
    $conf->webmaster_id,
];

$password_protected_users = [$conf->guest_id];

// an admin can't delete other admin/webmaster
if ($user['status'] == 'admin') {
    $query = <<<SQL
        SELECT user_id
        FROM user_infos
        WHERE status IN ('webmaster', 'admin');
        SQL;
    $admin_ids = $conf->sql_backend::query2array($query, null, 'user_id');

    $protected_users = array_merge($protected_users, $admin_ids);

    // we add all admin+webmaster users BUT the user herself
    $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$user['id']]));
}

$template->assign(
    [
        'U_HISTORY' => functions_url::get_root_url() . 'admin.php?page=history&filter_user_id=',
        'PWG_TOKEN' => functions::get_pwg_token(),
        'NB_IMAGE_PAGE' => $default_user['nb_image_page'],
        'RECENT_PERIOD' => $default_user['recent_period'],
        'theme_options' => functions::get_pwg_themes(),
        'theme_selected' => functions_user::get_default_theme(),
        'language_options' => functions::get_languages(),
        'language_selected' => functions_user::get_default_language(),
        'association_options' => $groups,
        'protected_users' => implode(',', array_unique($protected_users)),
        'password_protected_users' => implode(',', array_unique($password_protected_users)),
        'guest_user' => $conf->guest_id,
        'filter_group' => ($_GET['group'] ?? null),
        'connected_user' => $user['id'],
        'connected_user_status' => $user['status'],
        'owner' => $conf->webmaster_id,
    ]
);

if (isset($_GET['show_add_user'])) {
    $template->assign('show_add_user', true);
}

// Status options
foreach ($conf->sql_backend::get_enums('user_infos', 'status') as $status) {
    $label_of_status[$status] = functions::l10n('user_status_' . $status);
}

$pref_status_options = $label_of_status;

// a simple "admin" can't set/remove statuses webmaster/admin
if ($user['status'] == 'admin') {
    unset($pref_status_options['webmaster']);
    unset($pref_status_options['admin']);
}

$template->assign('label_of_status', $label_of_status);
$template->assign('pref_status_options', $pref_status_options);
$template->assign('pref_status_selected', 'normal');

// user level options
foreach ($conf->available_permission_levels as $level) {
    $level_options[$level] = functions::l10n(sprintf('Level %d', $level));
}

$template->assign('level_options', $level_options);
$template->assign('level_selected', $default_user['level']);

$query = <<<SQL
    SELECT id, name, is_default
    FROM user_groups
    ORDER BY name ASC;
    SQL;
$result = $conf->sql_backend::pwg_query($query);

$groups_arr_id = [];
$groups_arr_name = [];

while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $groups_arr_name[] = '"' . $conf->sql_backend::pwg_db_real_escape_string($row['name']) . '"';
    $groups_arr_id[] = $row['id'];
}

$template->assign('groups_arr_id', implode(',', $groups_arr_id));
$template->assign('groups_arr_name', implode(',', $groups_arr_name));
$template->assign('guest_id', $conf->guest_id);

$view_selector = functions_user::userprefs_get_param('user-manager-view', 'line');
$template->assign('view_selector', $view_selector);

if ($view_selector == 'line') {
    //Show 5 users by default
    $pagination = functions_user::userprefs_get_param('user-manager-pagination', 5);
    $template->assign('pagination', $pagination);
} else {
    //Show 10 users by default
    $pagination = functions_user::userprefs_get_param('user-manager-pagination', 10);
    $template->assign('pagination', $pagination);
}

$status_to_str = [];
foreach ($conf->sql_backend::get_enums('user_infos', 'status') as $status) {
    $status_to_str[$status] = functions::l10n('user_status_' . $status);
}

$months = [];
for ($i = 1; $i <= 12; $i++) {
    $months[] = functions::l10n(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][$i - 1]);
}

$page_data = [
    'titleMsg' => functions::l10n('Are you sure you want to delete the user "%s"?'),
    'areYouSureMsg' => functions::l10n('Are you sure?'),
    'confirmMsg' => functions::l10n('Yes, I am sure'),
    'cancelMsg' => functions::l10n('No, I have changed my mind'),
    'strAndOthersTags' => functions::l10n('and %s others'),
    'missingConfirm' => functions::l10n('You need to confirm deletion'),
    'missingUsername' => functions::l10n('Please, enter a login'),
    'fieldNotEmpty' => functions::l10n('Name field must not be empty'),
    'registeredStr' => functions::l10n('Registered'),
    'lastVisitStr' => functions::l10n('Last visit'),
    'datesInfos' => functions::l10n('between %s and %s'),
    'hideStr' => functions::l10n('Hide'),
    'showStr' => functions::l10n('Show'),
    'userAddedStr' => functions::l10n('User %s added'),
    'strPopinUpdateBtn' => functions::l10n('Update'),
    'filteredUsers' => functions::l10n('<b>%d</b> filtered users'),
    'filteredUser' => functions::l10n('<b>%d</b> filtered user'),
    'historyBaseUrl' => $U_HISTORY ?? '',
    'statusToStr' => $status_to_str,
    'viewSelector' => $view_selector,
    'pagination' => $pagination,
    'months' => $months,
    'connectedUser' => $user['id'],
    'connectedUserStatus' => $user['status'],
    'ownerId' => $conf->webmaster_id,
    'groupsArrName' => $groups_arr_name === [] ? [] : array_map(fn(string $g): string => trim($g, '"'), $groups_arr_name),
    'groupsArrId' => $groups_arr_id,
    'guestId' => $conf->guest_id,
    'nbDays' => functions::l10n('%d days'),
    'nbPhotos' => functions::l10n('%d photos'),
    'nbPhotosPerPage' => functions::l10n('%d photos per page'),
    'pwgToken' => functions::get_pwg_token(),
    'hasGroup' => $_GET['group'] ?? null,
    'registerDatesStr' => implode(',', $register_dates),
];

$template->assign('page_data_json', json_encode($page_data));

// +-----------------------------------------------------------------------+
// | html code display                                                     |
// +-----------------------------------------------------------------------+

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['user_list', 'pwgConfirm']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'user_list');
