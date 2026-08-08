<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\UserListPageContext;
use Piwigo\Admin\Request\UserListFilterRequest;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ThemeCatalog;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupService;
use Piwigo\Lang\LangService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/user_list.php (page slug "user_list") -- add users and
 * manage the users list. Confirmed via direct read: no write logic of its
 * own (user create/delete/status-change go through the WS API, not this
 * page); only defines one page-local helper, webmasterIdIsLocal().
 */
final class UserListPageRenderer
{
    public function render(Lang $lang, UrlServiceInterface $urlService, CoreTabs $coreTabs, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentTemplate $currentTemplate, UserService $userService, PreferencesService $preferencesService, GroupService $groupService, HtmlRenderingInterface $htmlRenderer, CurrentConfig $currentConfig, InputValidator $inputValidator, Paths $paths): void
    {
        $template = $currentTemplate->get();

        $userListFilter = UserListFilterRequest::fromGlobals($inputValidator);

        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('users');
        $tabsheet->select('user_list', $eventDispatcher);
        $tabsheet->assign($currentTemplate);

        $groups = [];
        $groups_for_filter = [];

        foreach ($groupService->getListWithMemberCounts() as $row) {
            $groups[$row->id->value] = $row->name;
            $groups_for_filter[] = [
                'id' => $row->id->value,
                'name' => $row->name,
                'counter' => $row->nbUsers,
            ];
        }

        $register_dates = $userService->getDistinctRegistrationYearMonths();

        $template->set_filenames([
            'user_list' => 'user_list.tpl',
        ]);

        $default_user = $userService->getDefaultUserInfo();
        if ($default_user === null) {
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
        if ($currentUser->get()->status === UserStatus::Admin) {
            $admin_ids = array_map(strval(...), $userService->getAdminIds());

            $protected_users = array_merge($protected_users, $admin_ids);

            $current_user_id = (string) $currentUser->get()
                ->id->value;

            // we add all admin+webmaster users BUT the user herself
            $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids, [$current_user_id]));
        }

        $owner_username = $userService->getUsernameById(UserId::from($webmaster_id))->value ?? '';

        // protected_users/password_protected_users mix CurrentUser::get()->id, several $conf
        // ids (already normalized to int above) and $admin_ids (query2array
        // user_id values, always numeric strings from a NOT NULL primary key);
        // stringify for implode() below.
        $protected_users = array_map(strval(...), array_filter($protected_users, is_scalar(...)));
        $password_protected_users = array_map(strval(...), array_filter($password_protected_users, is_scalar(...)));

        // Status options
        $label_of_status = [];
        foreach (UserStatus::cases() as $userStatus) {
            $label_of_status[$userStatus->value] = $lang->t('user_status_' . $userStatus->value);
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
        if ($currentUser->get()->status === UserStatus::Admin) {
            unset($pref_status_options['webmaster']);
            unset($pref_status_options['admin']);
        }

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

        $groups_arr_id = [];
        $groups_arr_name = [];
        foreach ($groupService->getAllBasic() as $group) {
            $groups_arr_name[] = '"' . addslashes($group->name) . '"';
            $groups_arr_id[] = (string) $group->id->value;
        }

        $view_selector = $preferencesService->getUserManagerView() ?? 'line';

        if ($view_selector === 'line') {
            // Show 5 users by default
            $pagination = $preferencesService->getUserManagerPagination() ?? 5;
        } else {
            // Show 10 users by default
            $pagination = $preferencesService->getUserManagerPagination() ?? 10;
        }

        if (self::webmasterIdIsLocal($paths)) {
            $pageState->addWarning($lang->t('You have specified <i>$conf[\'webmaster_id\']</i> in your local configuration file, this parameter in deprecated, please remove it!'));
        }

        $template->assignContext(new UserListPageContext(
            groupsForFilter: $groups_for_filter,
            registerDates: implode(',', $register_dates),
            adminPageTitle: $lang->t('Users'),
            activateComments: $currentConfig->activateComments(),
            doublePassword: $currentConfig->doublePasswordTypeInAdmin(),
            uHistory: $urlService->getRootUrl() . 'admin.php?page=history&filter_user_id=',
            pwgToken: new CsrfService($currentConfig)
                ->getToken(),
            nbImagePage: $default_user->nbImagePage,
            recentPeriod: $default_user->recentPeriod,
            themeOptions: ThemeCatalog::getPwgThemes($eventDispatcher, $paths, $currentConfig, $lang),
            themeSelected: $userService->getDefaultTheme(),
            languageOptions: LangService::getLanguages($paths),
            languageSelected: $userService->getDefaultLanguage(),
            associationOptions: $groups,
            protectedUsers: implode(',', array_unique($protected_users)),
            passwordProtectedUsers: implode(',', array_unique($password_protected_users)),
            guestUser: $guest_id,
            filterGroup: $userListFilter->groupId,
            searchInput: $userListFilter->userSearchInput,
            connectedUser: $currentUser->get()
                ->id->value,
            connectedUserStatus: $currentUser->get()
                ->status->value,
            owner: $webmaster_id,
            ownerUsername: $owner_username,
            showAddUser: $userListFilter->showAddUser,
            labelOfStatus: $label_of_status,
            prefStatusOptions: $pref_status_options,
            prefStatusSelected: 'normal',
            nbUsersByStatus: $nb_users_by_status,
            levelOptions: $level_options,
            levelSelected: $default_user->level,
            nbUsersByLevel: $nb_users_by_level,
            groupsArrId: implode(',', $groups_arr_id),
            groupsArrName: implode(',', $groups_arr_name),
            guestId: $guest_id,
            viewSelector: $view_selector,
            pagination: $pagination,
        ));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'user_list');
    }

    private static function webmasterIdIsLocal(Paths $paths): bool
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
        $conf = [];
        @include $paths->local . 'config/config.inc.php';
        // PHPStan can't see the @include above mutating $conf -- it's a
        // site owner's own arbitrary, user-editable file -- so the real
        // runtime shape has to be told explicitly rather than inferred.
        /** @var array<string, mixed> $conf */
        if (isset($conf['local_dir_site'])) {
            @include $paths->siteLocal . 'config/config.inc.php';
        }
        /** @var array<string, mixed> $conf */
        return (bool) ($conf['webmaster_id'] ?? false);
    }
}
