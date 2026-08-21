<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\UserListView;
use Piwigo\Admin\Request\UserListFilterRequest;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
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
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\Projection\DefaultUserInfo;
use Piwigo\Users\UserService;
use Piwigo\Users\UserStatus;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/user_list.php (page slug "user_list") -- add users and
 * manage the users list. No write logic of its own (user create/delete/
 * status-change go through `/api/v1/users`, not this
 * page); only defines one page-local helper, webmasterIdIsLocal().
 */
final class UserListPageRenderer
{
    public function render(Lang $lang, UrlServiceInterface $urlService, CoreTabs $coreTabs, EventDispatcher $eventDispatcher, PageState $pageState, CurrentUser $currentUser, CurrentTemplate $currentTemplate, UserService $userService, PreferencesService $preferencesService, GroupService $groupService, HtmlRenderingInterface $htmlRenderer, CurrentConfig $currentConfig, CsrfService $csrfService, InputValidator $inputValidator, Paths $paths, EntityManagerInterface $entityManager, Renderer $renderer): AdminPageResult
    {
        $template = $currentTemplate->get();

        $userListFilter = UserListFilterRequest::fromGlobals($inputValidator);

        $coreTabs->setContext(new CoreTabsContext(myBaseUrl: $urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->setId('users');
        $tabsheet->select('user_list', $eventDispatcher);
        $tabsheet->assign($currentTemplate, $renderer);

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

        $default_user = $userService->getDefaultUserInfo();
        if (! $default_user instanceof DefaultUserInfo) {
            $htmlRenderer
                ->fatalError('Default user not found');
        }

        // conf's guest_id/webmaster_id are always scalar (raw DB fetch value
        // or int config default -- same normalization already used by
        // functions.inc.php's get_webmaster_mail_address() and build_user()).
        $guest_id = $currentConfig->guestId;
        $webmaster_id = $currentConfig->webmasterId;

        $owner_username = $userService->getUsernameById(UserId::from($webmaster_id))->value ?? '';

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
        $available_permission_levels = $currentConfig->availablePermissionLevels;

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
            $groups_arr_name[] = $group->name;
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

        $adminContent = $renderer->render(new UserListView(
            groupsForFilter: $groups_for_filter,
            registerDates: implode(',', $register_dates),
            activateComments: $currentConfig->activateComments,
            uHistory: $urlService->getRootUrl() . 'admin.php?page=history&filter_user_id=',
            csrfToken: $csrfService
                ->getToken(),
            themeOptions: ThemeCatalog::getPwgThemes($paths, $currentConfig, $lang, $entityManager),
            themeSelected: $userService->getDefaultTheme(),
            languageOptions: LangService::getLanguages($paths, $entityManager),
            languageSelected: $userService->getDefaultLanguage(),
            associationOptions: $groups,
            filterGroup: $userListFilter->groupId,
            searchInput: $userListFilter->userSearchInput,
            connectedUser: $currentUser->get()
                ->id->value,
            connectedUserStatus: $currentUser->get()
                ->status->value,
            owner: $webmaster_id,
            ownerUsername: $owner_username,
            prefStatusOptions: $pref_status_options,
            prefStatusSelected: 'normal',
            nbUsersByStatus: $nb_users_by_status,
            levelOptions: $level_options,
            levelSelected: $default_user->level,
            nbUsersByLevel: $nb_users_by_level,
            groupsArrId: implode(',', $groups_arr_id),
            // A complete JSON array literal, not a comma-joined list of
            // hand-quoted names: this is spliced unescaped into an inline
            // <script> in user_list.latte, and the addslashes() this
            // replaces escaped only quotes and backslashes -- not `</script>`,
            // literal newlines, or U+2028/U+2029, each of which breaks or
            // escapes the script context. The HEX flags keep `<`, `>`, `&`,
            // `'` and `"` out of the emitted string entirely.
            groupsArrName: json_encode(
                $groups_arr_name,
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
            ),
            guestId: $guest_id,
            viewSelector: $view_selector,
            pagination: $pagination,
            colorscheme: $template->themeConf('colorscheme'),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $lang->t('Users'),
        );
    }

    private static function webmasterIdIsLocal(Paths $paths): bool
    {
        // A presence check ("did the site owner override webmaster_id in
        // their OWN local/config/config.inc.php"), not a value read, so
        // $conf deliberately starts empty rather than pre-seeded with any
        // default. webmaster_id has a real SCHEMA default (1); seeding it
        // first would make isset($conf['webmaster_id']) true on every
        // request even when the site file never touched it, permanently
        // (and wrongly) showing the "please remove it" deprecation warning
        // below. Note the deliberate absence of `global $conf;` here --
        // this $conf is a fresh local shadow the include below populates
        // from scratch, never the real DB-synced global.
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
