<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * Ported from admin/user_list.php (page slug "user_list") -- add users and
 * manage the users list. Confirmed via direct read: no write logic of its
 * own (user create/delete/status-change go through the WS API, not this
 * page); only defines one page-local helper, webmasterIdIsLocal().
 */
final class UserListPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $conf, $page, $template, $user;

        check_input_parameter('group', $_GET, false, ValidationPattern::ID);
        check_input_parameter('user_id', $_GET, false, ValidationPattern::ID);

        $page['tab'] = 'user_list';
        // The inline tabsheet block below (formerly admin/include/
        // user_tabs.inc.php, folded in P23 batch 8b-5) does a bare
        // top-level $my_base_url = ...; assignment -- without this global
        // declaration it becomes local to this call frame, silently
        // dropping the admin.php?page= prefix from this page's own
        // tab-nav hrefs (see feedback_admindispatcher_breaks_bare_global_bootstrap).
        global $my_base_url;

        $my_base_url = get_root_url() . 'admin.php?page=';

        $tabsheet = new tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        $groups = [];
        $groups_for_filter = [];

        $query = '
SELECT id, name, COUNT(ug.user_id) as nb_users_of
  FROM `' . Tables::groups() . '`
    LEFT JOIN `' . Tables::userGroup() . '` ug ON id = ug.group_id
  GROUP BY name
  ORDER BY name ASC
;';
        $result = pwg_query($query);

        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $group_id = $row['id'];
            if (! is_string($group_id)) {
                continue;
            }
            $groups[$group_id] = $row['name'];
            $groups_for_filter[] = [
                'id' => $group_id,
                'name' => $row['name'],
                'counter' => $row['nb_users_of'],
            ];
        }

        $template->assign('groups_for_filter', $groups_for_filter);

        $query = '
SELECT DISTINCT
      month(registration_date) as registration_month,
      year(registration_date) as registration_year
FROM ' . Tables::userInfos() . '
ORDER BY registration_date
;';
        $result = pwg_query($query);

        $register_dates = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $registration_month = is_numeric($row['registration_month']) ? (int) $row['registration_month'] : 0;
            $register_dates[] = $row['registration_year'] . '-' . sprintf('%02u', $registration_month);
        }

        $template->assign('register_dates', implode(',', $register_dates));

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => l10n('Users'),
                'ACTIVATE_COMMENTS' => $conf['activate_comments'],
                'Double_Password' => $conf['double_password_type_in_admin'],
            ]
        );

        $template->set_filenames([
            'user_list' => 'user_list.tpl',
        ]);

        $default_user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultUserInfo(true);
        if (! is_array($default_user)) {
            fatal_error('Default user not found');
        }

        // conf's guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // fetch value or int config default -- same normalization already used by
        // functions.inc.php's get_webmaster_mail_address() and build_user()).
        $guest_id = $conf['guest_id'];
        $guest_id = is_numeric($guest_id) ? (int) $guest_id : 0;
        $default_user_id = $conf['default_user_id'];
        $default_user_id = is_numeric($default_user_id) ? (int) $default_user_id : 0;
        $webmaster_id = $conf['webmaster_id'];
        $webmaster_id = is_numeric($webmaster_id) ? (int) $webmaster_id : 0;

        $protected_users = [
            $user['id'],
            $guest_id,
            $default_user_id,
            $webmaster_id,
        ];

        $password_protected_users = [$guest_id];

        // an admin can't delete other admin/webmaster
        if ($user['status'] === 'admin') {
            $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
            $admin_ids = query2array($query, null, 'user_id');

            $protected_users = array_merge($protected_users, $admin_ids);

            // user_infos.id (primary key, NOT NULL): a raw DB fetch value is a
            // numeric string, build_user() may also set it as int -- either way
            // it's always scalar and string-castable (same invariant as the
            // equivalent block in functions_user.inc.php's ws_users_setInfo()).
            $current_user_id = $user['id'];
            $current_user_id = is_scalar($current_user_id) ? (string) $current_user_id : '0';

            // we add all admin+webmaster users BUT the user herself
            $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$current_user_id]));
        }

        // user_fields is a string=>string map (see config_default.inc.php's
        // $conf['user_fields']); same invariant relied on by
        // functions_user.inc.php's ws_users_setInfo().
        /** @var array<string, string> $user_fields */
        $user_fields = $conf['user_fields'];

        $query = '
SELECT
    ' . $user_fields['username'] . ' AS username
    FROM ' . Tables::users() . '
    WHERE ' . $user_fields['id'] . ' = ' . $webmaster_id . '
;';

        $owner_username = query2array($query, null, 'username');

        // protected_users/password_protected_users mix $user['id'], several $conf
        // ids (already normalized to int above) and $admin_ids (query2array
        // user_id values, always numeric strings from a NOT NULL primary key);
        // stringify for implode() below.
        $protected_users = array_map(strval(...), array_filter($protected_users, is_scalar(...)));
        $password_protected_users = array_map(strval(...), array_filter($password_protected_users, is_scalar(...)));

        $template->assign(
            [
                'U_HISTORY' => get_root_url() . 'admin.php?page=history&filter_user_id=',
                'PWG_TOKEN' => get_pwg_token(),
                'NB_IMAGE_PAGE' => $default_user['nb_image_page'],
                'RECENT_PERIOD' => $default_user['recent_period'],
                'theme_options' => get_pwg_themes(),
                'theme_selected' => (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultTheme(),
                'language_options' => get_languages(),
                'language_selected' => (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService()))->getDefaultLanguage(),
                'association_options' => $groups,
                'protected_users' => implode(',', array_unique($protected_users)),
                'password_protected_users' => implode(',', array_unique($password_protected_users)),
                'guest_user' => $guest_id,
                'filter_group' => ($_GET['group'] ?? null),
                'search_input' => ((isset($_GET['user_id']) && is_string($_GET['user_id'])) ? 'id:' . $_GET['user_id'] : null),
                'connected_user' => $user['id'],
                'connected_user_status' => $user['status'],
                'owner' => $webmaster_id,
                'owner_username' => $owner_username[0],
            ]
        );

        if (isset($_GET['show_add_user'])) {
            $template->assign('show_add_user', true);
        }

        // Status options
        $label_of_status = [];
        foreach (get_enums(Tables::userInfos(), 'status') as $status) {
            $label_of_status[$status] = l10n('user_status_' . $status);
        }

        $query = '
SELECT
    status,
    COUNT(*) AS nb_users_of
  FROM ' . Tables::userInfos() . '
  WHERE user_id != ' . $guest_id . '
  GROUP BY status
';

        $result = pwg_query($query);
        $nb_users_by_status = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $status = $row['status'];
            if (! is_string($status)) {
                continue;
            }
            $nb_users_by_status[$status] = [
                'name' => l10n('user_status_' . $status),
                'counter' => $row['nb_users_of'],
            ];
        }

        $nb_users_by_status = array_merge($label_of_status, $nb_users_by_status);

        $pref_status_options = $label_of_status;

        // a simple "admin" can't set/remove statuses webmaster/admin
        if ($user['status'] === 'admin') {
            unset($pref_status_options['webmaster']);
            unset($pref_status_options['admin']);
        }

        $template->assign('label_of_status', $label_of_status);
        $template->assign('pref_status_options', $pref_status_options);
        $template->assign('pref_status_selected', 'normal');
        $template->assign('nb_users_by_status', $nb_users_by_status);

        // user level options
        // $conf['available_permission_levels'] defaults to [0, 1, 2, 4, 8] (see
        // include/config_default.inc.php), always a list of ints -- same invariant
        // relied on by functions.inc.php's get_privacy_level_options().
        $available_permission_levels = $conf['available_permission_levels'];
        $available_permission_levels = is_array($available_permission_levels) ? $available_permission_levels : [];

        $level_options = [];
        foreach ($available_permission_levels as $level) {
            if (! is_int($level)) {
                continue;
            }
            $level_options[$level] = l10n(sprintf('Level %d', $level));
        }

        $query = '
SELECT
    level,
    COUNT(*) AS nb_users_of
  FROM ' . Tables::userInfos() . '
  WHERE user_id != ' . $guest_id . '
  GROUP BY level
';

        $result = pwg_query($query);
        $nb_users_by_level = $level_options;
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $level = $row['level'];
            if (! is_numeric($level)) {
                continue;
            }
            $level = (int) $level;
            $nb_users_by_level[$level] = [
                'name' => l10n(sprintf('Level %d', $level)),
                'counter' => $row['nb_users_of'],
            ];
        }

        $template->assign('level_options', $level_options);
        $template->assign('level_selected', $default_user['level']);
        $template->assign('nb_users_by_level', $nb_users_by_level);

        $query = '
SELECT id, name, is_default
  FROM `' . Tables::groups() . '`
  ORDER BY name ASC
;';
        $result = pwg_query($query);

        $groups_arr_id = [];
        $groups_arr_name = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            $groups_arr_name[] = '"' . pwg_db_real_escape_string($row['name']) . '"';
            $groups_arr_id[] = $row['id'];
        }

        $template->assign('groups_arr_id', implode(',', $groups_arr_id));
        $template->assign('groups_arr_name', implode(',', $groups_arr_name));
        $template->assign('guest_id', $guest_id);

        $template->assign('view_selector', (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->getParam('user-manager-view', 'line'));

        if ((new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->getParam('user-manager-view', 'line') === 'line') {
            // Show 5 users by default
            $template->assign('pagination', (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->getParam('user-manager-pagination', 5));
        } else {
            // Show 10 users by default
            $template->assign('pagination', (new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build())))->getParam('user-manager-pagination', 10));
        }

        if ((bool) self::webmasterIdIsLocal()) {
            // include/common.inc.php seeds $page['warnings'] as [] -- always an
            // array; defensively re-initialized here in case that invariant is
            // ever broken by a prior include.
            $page_warnings = $page['warnings'] ?? [];
            if (! is_array($page_warnings)) {
                $page_warnings = [];
            }
            $page_warnings[] = l10n('You have specified <i>$conf[\'webmaster_id\']</i> in your local configuration file, this parameter in deprecated, please remove it!');
            $page['warnings'] = $page_warnings;
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'user_list');
    }

    private static function webmasterIdIsLocal(): mixed
    {
        // include/config_default.inc.php never sets local_dir_site/webmaster_id
        // (confirmed: no such keys in that file at all) -- they only ever come
        // from an optional, site-owner-authored local/config/config.inc.php
        // loaded at runtime, whose content isn't knowable statically. Note the
        // deliberate absence of `global $conf;` here -- this $conf is a fresh
        // local shadow the two includes below populate from scratch, never the
        // real DB-synced global.
        $conf = [];
        include PHPWG_ROOT_PATH . 'include/config_default.inc.php';
        @include PHPWG_ROOT_PATH . 'local/config/config.inc.php';
        // @phpstan-ignore isset.offset
        if (isset($conf['local_dir_site'])) {
            @include PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'config/config.inc.php';
        }
        // @phpstan-ignore nullCoalesce.offset
        return $conf['webmaster_id'] ?? false;
    }
}
