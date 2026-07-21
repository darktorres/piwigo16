<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

/**
 * Ported from admin/user_list.php (page slug "user_list") -- add users and
 * manage the users list. Confirmed via direct read: no write logic of its
 * own (user create/delete/status-change go through the WS API, not this
 * page); only defines one page-local helper, webmasterIdIsLocal().
 */
final class UserListPageRenderer
{
    private static function userService(Connection $conn): UserService
    {
        return new UserService(
            new UserRepository($conn),
            new GroupRepository($conn),
            new MailService(),
            new ActivityService(new ActivityRepository($conn)),
            new HtmlService(),
            $conn
        );
    }

    private static function preferencesService(Connection $conn): PreferencesService
    {
        return new PreferencesService(new UserRepository($conn));
    }

    public function render(UrlServiceInterface $urlService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();
        $conn = DbConnection::build();

        new \Piwigo\Validation\InputValidator()
            ->validate('group', $_GET, false, ValidationPattern::ID);
        new \Piwigo\Validation\InputValidator()
            ->validate('user_id', $_GET, false, ValidationPattern::ID);

        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select('user_list');
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
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $group_id = $row['id'];
            if (! is_int($group_id) && ! is_string($group_id)) {
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

        // ORDER BY registration_year, registration_month (the SELECTed
        // aliases), not the raw registration_date column -- Piwigo\Db\
        // DbConnection doesn't accept a DISTINCT query's ORDER BY
        // referencing a column absent from its own SELECT list the way the
        // legacy mysqli connection did (same class of strictness gap as
        // CommentsController's own ONLY_FULL_GROUP_BY fix).
        $query = '
SELECT DISTINCT
      month(registration_date) as registration_month,
      year(registration_date) as registration_year
FROM ' . Tables::userInfos() . '
ORDER BY registration_year, registration_month
;';
        $register_dates = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $registration_month = is_numeric($row['registration_month']) ? (int) $row['registration_month'] : 0;
            $registration_year = is_scalar($row['registration_year']) ? (string) $row['registration_year'] : '';
            $register_dates[] = $registration_year . '-' . sprintf('%02u', $registration_month);
        }

        $template->assign('register_dates', implode(',', $register_dates));

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => Lang::t('Users'),
                'ACTIVATE_COMMENTS' => \Piwigo\Config\Config::activateComments(),
                'Double_Password' => \Piwigo\Config\Config::doublePasswordTypeInAdmin(),
            ]
        );

        $template->set_filenames([
            'user_list' => 'user_list.tpl',
        ]);

        $default_user = self::userService($conn)->getDefaultUserInfo(true);
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
            $admin_ids = array_map(
                static fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
                array_column($conn->fetchAllAssociative($query), 'user_id')
            );

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

        $owner_username = array_column($conn->fetchAllAssociative($query), 'username');

        // protected_users/password_protected_users mix CurrentUser::get()->id, several $conf
        // ids (already normalized to int above) and $admin_ids (query2array
        // user_id values, always numeric strings from a NOT NULL primary key);
        // stringify for implode() below.
        $protected_users = array_map(strval(...), array_filter($protected_users, is_scalar(...)));
        $password_protected_users = array_map(strval(...), array_filter($password_protected_users, is_scalar(...)));

        $template->assign(
            [
                'U_HISTORY' => $urlService->getRootUrl() . 'admin.php?page=history&filter_user_id=',
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
                'NB_IMAGE_PAGE' => $default_user['nb_image_page'],
                'RECENT_PERIOD' => $default_user['recent_period'],
                'theme_options' => \Piwigo\Core\ThemeCatalog::getPwgThemes(),
                'theme_selected' => self::userService($conn)->getDefaultTheme(),
                'language_options' => \Piwigo\Lang\LangService::getLanguages(),
                'language_selected' => self::userService($conn)->getDefaultLanguage(),
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
        foreach (new DbInfo($conn)->getEnums(Tables::userInfos(), 'status') as $status) {
            $label_of_status[$status] = Lang::t('user_status_' . $status);
        }

        $query = '
SELECT
    status,
    COUNT(*) AS nb_users_of
  FROM ' . Tables::userInfos() . '
  WHERE user_id != ' . $guest_id . '
  GROUP BY status
';

        $nb_users_by_status = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $status = $row['status'];
            if (! is_string($status)) {
                continue;
            }
            $nb_users_by_status[$status] = [
                'name' => Lang::t('user_status_' . $status),
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
            $level_options[$level] = Lang::t(sprintf('Level %d', $level));
        }

        $query = '
SELECT
    level,
    COUNT(*) AS nb_users_of
  FROM ' . Tables::userInfos() . '
  WHERE user_id != ' . $guest_id . '
  GROUP BY level
';

        $nb_users_by_level = $level_options;
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $level = $row['level'];
            if (! is_numeric($level)) {
                continue;
            }
            $level = (int) $level;
            $nb_users_by_level[$level] = [
                'name' => Lang::t(sprintf('Level %d', $level)),
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
        $groups_arr_id = [];
        $groups_arr_name = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {
            $groups_arr_name[] = '"' . addslashes(is_scalar($row['name']) ? (string) $row['name'] : '') . '"';
            if (is_int($row['id']) || is_string($row['id'])) {
                $groups_arr_id[] = (string) $row['id'];
            }
        }

        $template->assign('groups_arr_id', implode(',', $groups_arr_id));
        $template->assign('groups_arr_name', implode(',', $groups_arr_name));
        $template->assign('guest_id', $guest_id);

        $template->assign('view_selector', self::preferencesService($conn)->getParam('user-manager-view', 'line'));

        if (self::preferencesService($conn)->getParam('user-manager-view', 'line') === 'line') {
            // Show 5 users by default
            $template->assign('pagination', self::preferencesService($conn)->getParam('user-manager-pagination', 5));
        } else {
            // Show 10 users by default
            $template->assign('pagination', self::preferencesService($conn)->getParam('user-manager-pagination', 10));
        }

        if ((bool) self::webmasterIdIsLocal()) {
            \Piwigo\Core\PageState::current()->addWarning(Lang::t('You have specified <i>$conf[\'webmaster_id\']</i> in your local configuration file, this parameter in deprecated, please remove it!'));
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
