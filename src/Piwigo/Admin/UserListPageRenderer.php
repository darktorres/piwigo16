<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Tables;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;

/**
 * Ported from admin/user_list.php (page slug "user_list") -- add users and
 * manage the users list. Confirmed via direct read: no write logic of its
 * own (user create/delete/status-change go through the WS API, not this
 * page); only defines one page-local helper, webmasterIdIsLocal().
 */
final class UserListPageRenderer
{
    public function render(Lang $lang, UrlServiceInterface $urlService, CoreTabs $coreTabs, \Piwigo\PluginConfig\EventDispatcher $eventDispatcher, \Piwigo\Core\PageState $pageState, \Piwigo\Users\CurrentUser $currentUser, \Piwigo\Template\CurrentTemplate $currentTemplate, UserService $userService, PreferencesService $preferencesService, \Piwigo\Group\GroupService $groupService, \Piwigo\Core\HtmlRenderingInterface $htmlRenderer, \Piwigo\Config\CurrentConfig $currentConfig): void
    {
        $template = $currentTemplate->get();
        $conn = DbConnection::build();

        $userListFilter = Request\UserListFilterRequest::fromGlobals();

        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select('user_list');
        $tabsheet->assign($currentTemplate);

        $groups = [];
        $groups_for_filter = [];

        foreach ($groupService->getListWithMemberCounts() as $row) {
            $groups[$row['id']] = $row['name'];
            $groups_for_filter[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'counter' => $row['nb_users'],
            ];
        }

        $template->assign('groups_for_filter', $groups_for_filter);

        $register_dates = $userService->getDistinctRegistrationYearMonths();

        $template->assign('register_dates', implode(',', $register_dates));

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => $lang->t('Users'),
                'ACTIVATE_COMMENTS' => $currentConfig->activateComments(),
                'Double_Password' => $currentConfig->doublePasswordTypeInAdmin(),
            ]
        );

        $template->set_filenames([
            'user_list' => 'user_list.tpl',
        ]);

        $default_user = $userService->getDefaultUserInfo();
        if (! is_array($default_user)) {
            $htmlRenderer
                ->fatalError('Default user not found');
        }

        // conf's guest_id/default_user_id/webmaster_id are always scalar (raw DB
        // fetch value or int config default -- same normalization already used by
        // functions.inc.php's get_webmaster_mail_address() and build_user()).
        $guest_id = $currentConfig->guestId();
        $default_user_id = $currentConfig->defaultUserId();
        $webmaster_id = $currentConfig->webmasterId();

        $protected_users = [
            $currentUser->get()
                ->id->value,
            $guest_id,
            $default_user_id,
            $webmaster_id,
        ];

        $password_protected_users = [$guest_id];

        // an admin can't delete other admin/webmaster
        if ($currentUser->get()->status === \Piwigo\Users\UserStatus::Admin) {
            $admin_ids = array_map(strval(...), $userService->getAdminIds());

            $protected_users = array_merge($protected_users, $admin_ids);

            $current_user_id = (string) $currentUser->get()
                ->id->value;

            // we add all admin+webmaster users BUT the user herself
            $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$current_user_id]));
        }

        $owner_username = $userService->getUsernameById(\Piwigo\Common\ValueObject\UserId::from($webmaster_id))->value ?? '';

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
                'theme_options' => \Piwigo\Core\ThemeCatalog::getPwgThemes($eventDispatcher),
                'theme_selected' => $userService->getDefaultTheme(),
                'language_options' => \Piwigo\Lang\LangService::getLanguages(),
                'language_selected' => $userService->getDefaultLanguage(),
                'association_options' => $groups,
                'protected_users' => implode(',', array_unique($protected_users)),
                'password_protected_users' => implode(',', array_unique($password_protected_users)),
                'guest_user' => $guest_id,
                'filter_group' => $userListFilter->groupId,
                'search_input' => $userListFilter->userSearchInput,
                'connected_user' => $currentUser->get()
                    ->id->value,
                'connected_user_status' => $currentUser->get()
                    ->status->value,
                'owner' => $webmaster_id,
                'owner_username' => $owner_username,
            ]
        );

        if ($userListFilter->showAddUser) {
            $template->assign('show_add_user', true);
        }

        // Status options
        $label_of_status = [];
        foreach (new DbInfo($conn)->getEnums(Tables::userInfos(), 'status') as $status) {
            $label_of_status[$status] = $lang->t('user_status_' . $status);
        }

        $nb_users_by_status = [];
        foreach ($userService->getUserCountsByStatus($guest_id) as $status => $counter) {
            $nb_users_by_status[$status] = [
                'name' => $lang->t('user_status_' . $status),
                'counter' => $counter,
            ];
        }

        $nb_users_by_status = array_merge($label_of_status, $nb_users_by_status);

        $pref_status_options = $label_of_status;

        // a simple "admin" can't set/remove statuses webmaster/admin
        if ($currentUser->get()->status === \Piwigo\Users\UserStatus::Admin) {
            unset($pref_status_options['webmaster']);
            unset($pref_status_options['admin']);
        }

        $template->assign('label_of_status', $label_of_status);
        $template->assign('pref_status_options', $pref_status_options);
        $template->assign('pref_status_selected', 'normal');
        $template->assign('nb_users_by_status', $nb_users_by_status);

        // user level options
        $available_permission_levels = $currentConfig->availablePermissionLevels();

        $level_options = [];
        foreach ($available_permission_levels as $level) {
            $level_options[$level] = $lang->t(sprintf('Level %d', $level));
        }

        $nb_users_by_level = $level_options;
        foreach ($userService->getUserCountsByLevel($guest_id) as $level => $counter) {
            $nb_users_by_level[$level] = [
                'name' => $lang->t(sprintf('Level %d', $level)),
                'counter' => $counter,
            ];
        }

        $template->assign('level_options', $level_options);
        $template->assign('level_selected', $default_user['level']);
        $template->assign('nb_users_by_level', $nb_users_by_level);

        $groups_arr_id = [];
        $groups_arr_name = [];
        foreach ($groupService->getAllBasic() as $group) {
            $groups_arr_name[] = '"' . addslashes($group->name) . '"';
            $groups_arr_id[] = (string) $group->id->value;
        }

        $template->assign('groups_arr_id', implode(',', $groups_arr_id));
        $template->assign('groups_arr_name', implode(',', $groups_arr_name));
        $template->assign('guest_id', $guest_id);

        $template->assign('view_selector', $preferencesService->getParam('user-manager-view', 'line'));

        if ($preferencesService->getParam('user-manager-view', 'line') === 'line') {
            // Show 5 users by default
            $template->assign('pagination', $preferencesService->getParam('user-manager-pagination', 5));
        } else {
            // Show 10 users by default
            $template->assign('pagination', $preferencesService->getParam('user-manager-pagination', 10));
        }

        if (self::webmasterIdIsLocal()) {
            $pageState->addWarning($lang->t('You have specified <i>$conf[\'webmaster_id\']</i> in your local configuration file, this parameter in deprecated, please remove it!'));
        }

        $template->assign_var_from_handle('ADMIN_CONTENT', 'user_list');
    }

    // PHPStan can't see the @include below mutating $conf, so it thinks
    // this never returns true.
    // @phpstan-ignore return.tooWideBool
    private static function webmasterIdIsLocal(): bool
    {
        // A presence check ("did the site owner override webmaster_id in
        // their OWN local/config/config.inc.php"), not a value read --
        // deliberately does NOT start from CurrentConfig::defaultsArray() the way
        // LegacyFileConf::read()'s value-reading callers do. webmaster_id
        // has a real SCHEMA default (1) now; merging that in first would
        // make isset($conf['webmaster_id']) true on every request even
        // when the site file never touched it, permanently (and wrongly)
        // showing the "please remove it" deprecation warning below. The
        // former config_default.inc.php happened to never set
        // local_dir_site/webmaster_id either, so this always was, and
        // stays, a bare local-file-only read -- "nothing is frozen"
        // gap-closure (2026-07-22) caught this as a real near-miss bug
        // while retiring config_default.inc.php (a naive CurrentConfig::
        // defaultsArray()-first rewrite would have made this warning fire
        // unconditionally, since webmaster_id does have a real SCHEMA
        // default now). Note the deliberate absence of `global $conf;`
        // here -- this $conf is a fresh local shadow the include below
        // populates from scratch, never the real DB-synced global.
        $paths = CurrentPaths::get();
        $conf = [];
        @include $paths->local . 'config/config.inc.php';
        // @phpstan-ignore isset.offset
        if (isset($conf['local_dir_site'])) {
            @include $paths->siteLocal . 'config/config.inc.php';
        }
        // PHPStan can't see the @include above mutating $conf, so it thinks
        // this is always false/never true -- both ignores are that same
        // blind spot, not a real narrowing bug.
        // @phpstan-ignore nullCoalesce.offset, cast.useless
        return (bool) ($conf['webmaster_id'] ?? false);
    }
}
