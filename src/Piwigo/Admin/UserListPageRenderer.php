<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;

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
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        (new \Piwigo\Validation\InputValidator())->validate('group', $_GET, false, ValidationPattern::ID);
        (new \Piwigo\Validation\InputValidator())->validate('user_id', $_GET, false, ValidationPattern::ID);

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
        $result = \Piwigo\Db\MysqliDb::query($query);

        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
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
        $result = \Piwigo\Db\MysqliDb::query($query);

        $register_dates = [];
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            $registration_month = is_numeric($row['registration_month']) ? (int) $row['registration_month'] : 0;
            $register_dates[] = $row['registration_year'] . '-' . sprintf('%02u', $registration_month);
        }

        $template->assign('register_dates', implode(',', $register_dates));

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => l10n('Users'),
                'ACTIVATE_COMMENTS' => \Piwigo\Config\Config::activateComments(),
                'Double_Password' => \Piwigo\Config\Config::doublePasswordTypeInAdmin(),
            ]
        );

        $template->set_filenames([
            'user_list' => 'user_list.tpl',
        ]);

        $default_user = (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getDefaultUserInfo(true);
        if (! is_array($default_user)) {
            new HtmlService()
                ->fatalError('Default user not found');
        }

        // conf's guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // fetch value or int config default -- same normalization already used by
        // functions.inc.php's get_webmaster_mail_address() and build_user()).
        $guest_id = \Piwigo\Config\Config::guestId();
        $default_user_id = \Piwigo\Config\Config::defaultUserId();
        $webmaster_id = \Piwigo\Config\Config::webmasterId();

        $protected_users = [
            \Piwigo\Users\CurrentUser::get()->id,
            $guest_id,
            $default_user_id,
            $webmaster_id,
        ];

        $password_protected_users = [$guest_id];

        // an admin can't delete other admin/webmaster
        if (\Piwigo\Users\CurrentUser::get()->status === \Piwigo\Users\UserStatus::Admin) {
            $query = '
SELECT
    user_id
  FROM ' . Tables::userInfos() . '
  WHERE status IN (\'webmaster\', \'admin\')
;';
            $admin_ids = \Piwigo\Db\MysqliDb::query2Array($query, null, 'user_id');

            $protected_users = array_merge($protected_users, $admin_ids);

            $current_user_id = (string) \Piwigo\Users\CurrentUser::get()->id;

            // we add all admin+webmaster users BUT the user herself
            $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$current_user_id]));
        }

        $user_fields = \Piwigo\Config\Config::userFields();

        $query = '
SELECT
    ' . $user_fields['username'] . ' AS username
    FROM ' . Tables::users() . '
    WHERE ' . $user_fields['id'] . ' = ' . $webmaster_id . '
;';

        $owner_username = \Piwigo\Db\MysqliDb::query2Array($query, null, 'username');

        // protected_users/password_protected_users mix CurrentUser::get()->id, several $conf
        // ids (already normalized to int above) and $admin_ids (query2array
        // user_id values, always numeric strings from a NOT NULL primary key);
        // stringify for implode() below.
        $protected_users = array_map(strval(...), array_filter($protected_users, is_scalar(...)));
        $password_protected_users = array_map(strval(...), array_filter($password_protected_users, is_scalar(...)));

        $template->assign(
            [
                'U_HISTORY' => get_root_url() . 'admin.php?page=history&filter_user_id=',
                'PWG_TOKEN' => (new \Piwigo\Csrf\CsrfService())->getToken(),
                'NB_IMAGE_PAGE' => $default_user['nb_image_page'],
                'RECENT_PERIOD' => $default_user['recent_period'],
                'theme_options' => \Piwigo\Core\ThemeCatalog::getPwgThemes(),
                'theme_selected' => (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getDefaultTheme(),
                'language_options' => \Piwigo\Lang\LangService::getLanguages(),
                'language_selected' => (new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Group\GroupRepository(\Piwigo\Db\DbConnection::build()), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())), new HtmlService()))->getDefaultLanguage(),
                'association_options' => $groups,
                'protected_users' => implode(',', array_unique($protected_users)),
                'password_protected_users' => implode(',', array_unique($password_protected_users)),
                'guest_user' => $guest_id,
                'filter_group' => ($_GET['group'] ?? null),
                'search_input' => ((isset($_GET['user_id']) && is_string($_GET['user_id'])) ? 'id:' . $_GET['user_id'] : null),
                'connected_user' => \Piwigo\Users\CurrentUser::get()->id,
                'connected_user_status' => \Piwigo\Users\CurrentUser::get()->status->value,
                'owner' => $webmaster_id,
                'owner_username' => $owner_username[0],
            ]
        );

        if (isset($_GET['show_add_user'])) {
            $template->assign('show_add_user', true);
        }

        // Status options
        $label_of_status = [];
        foreach (\Piwigo\Db\MysqliDb::getEnums(Tables::userInfos(), 'status') as $status) {
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

        $result = \Piwigo\Db\MysqliDb::query($query);
        $nb_users_by_status = [];
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
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
        if (\Piwigo\Users\CurrentUser::get()->status === \Piwigo\Users\UserStatus::Admin) {
            unset($pref_status_options['webmaster']);
            unset($pref_status_options['admin']);
        }

        $template->assign('label_of_status', $label_of_status);
        $template->assign('pref_status_options', $pref_status_options);
        $template->assign('pref_status_selected', 'normal');
        $template->assign('nb_users_by_status', $nb_users_by_status);

        // user level options
        $available_permission_levels = \Piwigo\Config\Config::availablePermissionLevels();

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

        $result = \Piwigo\Db\MysqliDb::query($query);
        $nb_users_by_level = $level_options;
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
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
        $result = \Piwigo\Db\MysqliDb::query($query);

        $groups_arr_id = [];
        $groups_arr_name = [];
        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            $groups_arr_name[] = '"' . \Piwigo\Db\MysqliDb::realEscapeString($row['name']) . '"';
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
