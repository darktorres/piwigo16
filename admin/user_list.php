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

check_input_parameter('group', $_GET, false, PATTERN_ID);
check_input_parameter('user_id', $_GET, false, PATTERN_ID);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'user_list';
include(PHPWG_ROOT_PATH.'admin/include/user_tabs.inc.php');

global $template, $user, $page, $persistent_cache, $lang;

// +-----------------------------------------------------------------------+
// |                              groups list                              |
// +-----------------------------------------------------------------------+

$groups = [];
$groups_for_filter = [];

foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class)->findWithMemberCounts() as $row) {
    $groups[is_numeric($row['id']) ? (int)$row['id'] : 0] = $row['name'];
    $groups_for_filter[] = [
      'id' => $row['id'],
      'name' => $row['name'],
      'counter' => $row['nb_users_of'],
    ];
}

$template->assign('groups_for_filter', $groups_for_filter);

// +-----------------------------------------------------------------------+
// |                              Dates for filtering                      |
// +-----------------------------------------------------------------------+

$register_dates = [];
foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)->findRegistrationMonthsYears() as $row) {
    $register_dates[] = $row['registration_year'] . '-' . sprintf('%02u', $row['registration_month']);
}

$template->assign('register_dates', implode(',', $register_dates));


// +-----------------------------------------------------------------------+
// | template                                                              |
// +-----------------------------------------------------------------------+
$template->assign(
    [
    'ADMIN_PAGE_TITLE' => l10n('Users'),
    'ACTIVATE_COMMENTS' => \Piwigo\Config\Config::activateComments(),
    'Double_Password' => \Piwigo\Config\Config::doublePasswordTypeInAdmin(),
  ]
);

$template->set_filenames(['user_list' => 'user_list.tpl']);

$default_user = get_default_user_info(true);

$protected_users = [
  $user['id'],
  \Piwigo\Config\Config::guestId(),
  \Piwigo\Config\Config::defaultUserId(),
  \Piwigo\Config\Config::webmasterId(),
  ];

$password_protected_users = [\Piwigo\Config\Config::guestId()];

// an admin can't delete other admin/webmaster
if ('admin' == $user['status']) {
    $query = '
SELECT
    user_id
  FROM '.USER_INFOS_TABLE.'
  WHERE status IN (\'webmaster\', \'admin\')
;';
    $admin_ids = query2array($query, null, 'user_id');

    $protected_users = array_merge($protected_users, $admin_ids);

    // we add all admin+webmaster users BUT the user herself
    $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$user['id']]));
}

$query = '
SELECT
    '.\Piwigo\Config\Config::userFields()['username'].' AS username
    FROM '.USERS_TABLE.'
    WHERE '.\Piwigo\Config\Config::userFields()['id'].' = '.\Piwigo\Config\Config::webmasterId().'
;';

$owner_username = query2array($query, null, 'username');

$template->assign(
    [
    'U_HISTORY' => get_root_url().'admin.php?page=history&filter_user_id=',
    'PWG_TOKEN' => get_pwg_token(),
    'NB_IMAGE_PAGE' => $default_user['nb_image_page'] ?? null,
    'RECENT_PERIOD' => $default_user['recent_period'] ?? null,
    'theme_options' => get_pwg_themes(),
    'theme_selected' => get_default_theme(),
    'language_options' => get_languages(),
    'language_selected' => get_default_language(),
    'association_options' => $groups,
    'protected_users' => implode(',', array_unique($protected_users)),
    'password_protected_users' => implode(',', array_unique($password_protected_users)),
    'guest_user' => \Piwigo\Config\Config::guestId(),
    'filter_group' => ($_GET['group'] ?? null),
    'search_input' => (isset($_GET['user_id']) ? 'id:'.(is_scalar($_GET['user_id']) ? (string) $_GET['user_id'] : '') : null),
    'connected_user' => $user['id'],
    'connected_user_status' => $user['status'],
    'owner' => \Piwigo\Config\Config::webmasterId(),
    'owner_username' => $owner_username[0],
    ]
);

if (isset($_GET['show_add_user'])) {
    $template->assign('show_add_user', true);
}

// Status options
$label_of_status = [];
foreach (\Piwigo\Db\SchemaHelper::getEnums(USER_INFOS_TABLE, 'status') as $status) {
    $label_of_status[$status] = l10n('user_status_'.$status);
}

$nb_users_by_status = [];
foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
    ->findStatusDistribution(\Piwigo\Config\Config::guestId()) as $status => $count) {
    $nb_users_by_status[$status] = [
        'name'    => l10n('user_status_' . $status),
        'counter' => $count,
    ];
}

$nb_users_by_status = array_merge($label_of_status, $nb_users_by_status);

$pref_status_options = $label_of_status;

// a simple "admin" can't set/remove statuses webmaster/admin
if ('admin' == $user['status']) {
    unset($pref_status_options['webmaster']);
    unset($pref_status_options['admin']);
}

$template->assign('label_of_status', $label_of_status);
$template->assign('pref_status_options', $pref_status_options);
$template->assign('pref_status_selected', 'normal');
$template->assign('nb_users_by_status', $nb_users_by_status);

// user level options
$level_options = [];
foreach (\Piwigo\Config\Config::availablePermissionLevels() as $level) {
    $level_options[$level] = l10n(sprintf('Level %d', $level));
}

$nb_users_by_level = $level_options;
foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Users\UserRepository::class)
    ->findLevelDistribution(\Piwigo\Config\Config::guestId()) as $level => $count) {
    $nb_users_by_level[$level] = [
        'name'    => l10n(sprintf('Level %d', $level)),
        'counter' => $count,
    ];
}

$template->assign('level_options', $level_options);
$template->assign('level_selected', $default_user['level'] ?? 0);
$template->assign('nb_users_by_level', $nb_users_by_level);

$groups_arr_id = [];
$groups_arr_name = [];
foreach (\Piwigo\Core\ServiceLocator::get(\Piwigo\Group\GroupRepository::class)->findAllOrdered() as $row) {
    $groups_arr_name[] = '"' . addslashes(is_scalar($row['name']) ? (string) $row['name'] : '') . '"';
    $groups_arr_id[] = is_scalar($row['id']) ? (string) $row['id'] : '';
}

$template->assign('groups_arr_id', implode(',', $groups_arr_id));
$template->assign('groups_arr_name', implode(',', $groups_arr_name));
$template->assign('guest_id', \Piwigo\Config\Config::guestId());

$template->assign('view_selector', userprefs_get_param('user-manager-view', 'line'));

if (userprefs_get_param('user-manager-view', 'line') == 'line') {
    //Show 5 users by default
    $template->assign('pagination', userprefs_get_param('user-manager-pagination', 5));
} else {
    //Show 10 users by default
    $template->assign('pagination', userprefs_get_param('user-manager-pagination', 10));
}

function webmaster_id_is_local(): bool
{
    // Detects whether the user has set $conf['webmaster_id'] in their local
    // config file. The previous implementation included config_default.inc.php
    // (which always sets webmaster_id) into a fresh local $conf and then
    // checked isset() — which was always true. Static text scan is cheaper and
    // actually matches the warning's intent.
    $candidates = [
        PHPWG_ROOT_PATH . 'local/config/config.inc.php',
        PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/config.inc.php',
    ];
    foreach ($candidates as $path) {
        $real = realpath($path);
        if ($real === false) {
            continue;
        }
        $content = is_readable($real) ? file_get_contents($real) : false;
        if ($content !== false && preg_match('/\$conf\s*\[\s*[\'"]webmaster_id[\'"]\s*\]\s*=/', $content) === 1) {
            return true;
        }
    }
    return false;
}

if (webmaster_id_is_local()) {
    \Piwigo\Core\PageState::current()->addWarning(l10n('You have specified <i>' . '$' . 'conf[\'webmaster_id\']</i> in your local configuration file, this parameter in deprecated, please remove it!'));
}
// Build groups_arr as [[id, name], ...] pairs for the JSON block
$groups_arr_json = [];
foreach ($groups as $id => $name) {
    $groups_arr_json[] = [(int)$id, $name];
}

$template->assign('page_data_json', json_encode([
    'pwg_token'                => get_pwg_token(),
    'connected_user'           => (int)$user['id'],
    'connected_user_status'    => $user['status'],
    'owner_id'                 => (int)\Piwigo\Config\Config::webmasterId(),
    'owner_username'           => $owner_username[0] ?? '',
    'guest_id'                 => (int)\Piwigo\Config\Config::guestId(),
    'has_group'                => $_GET['group'] ?? '',
    'view_selector'            => userprefs_get_param('user-manager-view', 'line'),
    'pagination'               => (function () {
        $v = userprefs_get_param('user-manager-pagination', userprefs_get_param('user-manager-view', 'line') === 'line' ? 5 : 10);
        return is_numeric($v) ? (int) $v : 0;
    })(),
    'history_base_url'         => get_root_url() . 'admin.php?page=history&filter_user_id=',
    'register_dates'           => $register_dates,
    'groups_arr'               => $groups_arr_json,
    'months'                   => [
        l10n('Jan'), l10n('Feb'), l10n('Mar'), l10n('Apr'),
        l10n('May'), l10n('Jun'), l10n('Jul'), l10n('Aug'),
        l10n('Sep'), l10n('Oct'), l10n('Nov'), l10n('Dec'),
    ],
    'status_to_str'            => [
        'webmaster' => l10n('user_status_webmaster'),
        'admin'     => l10n('user_status_admin'),
        'normal'    => l10n('user_status_normal'),
        'generic'   => l10n('user_status_generic'),
        'guest'     => l10n('user_status_guest'),
    ],
    // translation strings
    'cancel_msg'               => l10n('No, I have changed my mind'),
    'cannotSendMail'           => l10n("Cannot send an email to this user because he doesn't have an email address"),
    'cantCopy'                 => l10n('You cannot copy the password if the connection to this site is not secure.'),
    'confirm_msg'              => l10n('Yes, I am sure'),
    'copyLinkStr'              => l10n('Copied link'),
    'dates_infos'              => l10n('between %s and %s'),
    'errorMailSent'            => l10n('Error sending email'),
    'errorMailSentMsg'         => l10n('An activation link valid for %s was created but could not be sent. You can now copy the link below and send it to the user.'),
    'errorStr'                 => l10n('an error happened'),
    'fieldNotEmpty'            => l10n('Name field must not be empty'),
    'filtered_user'            => l10n('<b>%d</b> filtered user'),
    'filtered_users'           => l10n('<b>%d</b> filtered users'),
    'last_visit_str'           => l10n('Last visit'),
    'mailSentAt'               => l10n('Mail sent to %s [%s].'),
    'mainAskWebmaster'         => l10n('You are not authorised to change the main user, please ask your webmaster'),
    'mainUserContinue'         => l10n('You are about to set %s as main user instead of %s, do you wish to continue ?'),
    'mainUserRewrite'          => l10n('To be sure, please rewrite the word "%s" below'),
    'mainUserSet'              => l10n('Set as main user'),
    'mainUserStr'              => l10n('Main user'),
    'mainUserSuccess'          => l10n('%s is the new main user'),
    'mainUserUpgradeWebmaster' => l10n('This user must first be defined as the webmaster before it can be upgraded to the main user'),
    'mainUserValidate'         => l10n('You can now change the main user from %s to %s.'),
    'missingConfirm'           => l10n('You need to confirm deletion'),
    'missingConfPassword'      => l10n('Password confirmation is missing. Please confirm the chosen password.'),
    'missingField'             => l10n('Please complete all fields'),
    'missingPassword'          => l10n('Password is missing. Please enter the password.'),
    'missingUsername'          => l10n('Please, enter a login'),
    'noMatchPassword'          => l10n('The passwords do not match'),
    'nb_days'                  => l10n('%d days'),
    'nb_photos'                => l10n('%d photos'),
    'passwordCopied'           => l10n('Password copied'),
    'registered_str'           => l10n('Registered'),
    'str_and_others_tags'      => l10n('and %s others'),
    'title_msg'                => l10n('Are you sure you want to delete the user "%s"?'),
    'user_added_str'           => l10n('User %s added'),
    'validLinkMail'            => l10n("An activation link valid for %s has been sent to \"%s\". If the user doesn't receive the link, you can generate and copy a new one by editing the user and managing her password."),
    'validLinkWithoutMail'     => l10n('Copy the link below and send it to the user so the password can be set.'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

// +-----------------------------------------------------------------------+
// | html code display                                                     |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'user_list');
