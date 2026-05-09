<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Admin\Users\UserTabRenderer;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\Exception\ValidationException;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

final class UsersController
{
    /** @var list<string> */
    public const array PAGES = [
        'user_list',
        'user_perm',
        'user_activity',
    ];

    public function handle(string $page): void
    {
        if ($page === 'user_list') {
            $this->userList();
        } elseif ($page === 'user_perm') {
            $this->userPerm();
        } elseif ($page === 'user_activity') {
            $this->userActivity();
        }
    }

    // ── user_list ─────────────────────────────────────────────────────────────

    private function userList(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        ServiceLocator::get(Util::class)->checkInputParameter('group', $_GET, false, ValidationPattern::ID);
        ServiceLocator::get(Util::class)->checkInputParameter('user_id', $_GET, false, ValidationPattern::ID);

        $page['tab'] = 'user_list';
        ServiceLocator::get(UserTabRenderer::class)->render();

        // ── Groups ──────────────────────────────────────────────────────────

        $groups            = [];
        $groups_for_filter = [];
        foreach (ServiceLocator::get(GroupRepository::class)->findWithMemberCounts() as $row) {
            $groups[is_numeric($row['id']) ? (int) $row['id'] : 0] = $row['name'];
            $groups_for_filter[] = ['id' => $row['id'], 'name' => $row['name'], 'counter' => $row['nb_users_of']];
        }
        $tpl->assign('groups_for_filter', $groups_for_filter);

        // ── Registration dates ───────────────────────────────────────────────

        $register_dates = [];
        foreach (ServiceLocator::get(UserRepository::class)->findRegistrationMonthsYears() as $row) {
            $register_dates[] = $row['registration_year'] . '-' . sprintf('%02u', $row['registration_month']);
        }
        $tpl->assign('register_dates', implode(',', $register_dates));

        // ── Template ─────────────────────────────────────────────────────────

        $tpl->assign([
            'ADMIN_PAGE_TITLE'   => Lang::t('Users'),
            'ACTIVATE_COMMENTS'  => Config::activateComments(),
            'Double_Password'    => Config::doublePasswordTypeInAdmin(),
        ]);
        $tpl->setFilenames(['user_list' => 'user_list.tpl']);

        $default_user = UserService::get()->getDefaultUserInfo(true);
        $userId       = is_numeric($user['id']) ? (int) $user['id'] : 0;
        $userStatus   = is_string($user['status']) ? $user['status'] : '';

        $protected_users = [(string) $userId, (string) Config::guestId(), (string) Config::defaultUserId(), (string) Config::webmasterId()];
        $password_protected_users = [(string) Config::guestId()];

        if ($userStatus === 'admin') {
            $admin_ids = array_column(DbConnection::get()->executeQuery('SELECT user_id FROM ' . Tables::userInfos() . " WHERE status IN ('webmaster', 'admin');")->fetchAllAssociative(), 'user_id');
            $admin_ids_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $admin_ids);
            $protected_users = array_merge($protected_users, $admin_ids_str);
            $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids_str, [(string) $userId]));
        }

        $owner_username = array_column(DbConnection::get()->executeQuery('SELECT ' . Config::userFields()['username'] . ' AS username FROM ' . Tables::users() . ' WHERE ' . Config::userFields()['id'] . ' = ' . Config::webmasterId() . ';')->fetchAllAssociative(), 'username');

        $tpl->assign([
            'U_HISTORY'                 => ServiceLocator::get(UrlGenerator::class)->admin('history') . '&filter_user_id=',
            'PWG_TOKEN'                 => ServiceLocator::get(Util::class)->getPwgToken(),
            'NB_IMAGE_PAGE'             => $default_user['nb_image_page'] ?? null,
            'RECENT_PERIOD'             => $default_user['recent_period'] ?? null,
            'theme_options'             => ServiceLocator::get(Util::class)->getPwgThemes(),
            'theme_selected'            => UserService::get()->getDefaultTheme(),
            'language_options'          => Util::get()->getLanguages(),
            'language_selected'         => UserService::get()->getDefaultLanguage(),
            'association_options'       => $groups,
            'protected_users'           => implode(',', array_unique($protected_users)),
            'password_protected_users'  => implode(',', array_unique($password_protected_users)),
            'guest_user'                => Config::guestId(),
            'filter_group'              => $_GET['group'] ?? null,
            'search_input'              => isset($_GET['user_id']) ? 'id:' . (is_string($_GET['user_id']) ? $_GET['user_id'] : '') : null,
            'connected_user'            => $userId,
            'connected_user_status'     => $userStatus,
            'owner'                     => Config::webmasterId(),
            'owner_username'            => $owner_username[0] ?? '',
        ]);

        if (isset($_GET['show_add_user'])) {
            $tpl->assign('show_add_user', true);
        }

        // Status options
        $label_of_status = [];
        foreach (SchemaHelper::getEnums(Tables::userInfos(), 'status') as $status) {
            $label_of_status[$status] = Lang::t('user_status_' . $status);
        }

        $nb_users_by_status = [];
        foreach (ServiceLocator::get(UserRepository::class)->findStatusDistribution(Config::guestId()) as $status => $count) {
            $nb_users_by_status[$status] = ['name' => Lang::t('user_status_' . $status), 'counter' => $count];
        }
        $nb_users_by_status = array_merge($label_of_status, $nb_users_by_status);

        $pref_status_options = $label_of_status;
        if ($userStatus === 'admin') {
            unset($pref_status_options['webmaster']);
            unset($pref_status_options['admin']);
        }

        $tpl->assign('label_of_status', $label_of_status);
        $tpl->assign('pref_status_options', $pref_status_options);
        $tpl->assign('pref_status_selected', 'normal');
        $tpl->assign('nb_users_by_status', $nb_users_by_status);

        // Level options
        $level_options = [];
        foreach (Config::availablePermissionLevels() as $level) {
            $level_options[$level] = Lang::t(sprintf('Level %d', $level));
        }
        $nb_users_by_level = $level_options;
        foreach (ServiceLocator::get(UserRepository::class)->findLevelDistribution(Config::guestId()) as $level => $count) {
            $nb_users_by_level[$level] = ['name' => Lang::t(sprintf('Level %d', $level)), 'counter' => $count];
        }
        $tpl->assign('level_options', $level_options);
        $defaultUserLevelRaw = $default_user['level'] ?? 0;
        $defaultUserLevel    = is_scalar($defaultUserLevelRaw) ? $defaultUserLevelRaw : 0;
        $tpl->assign('level_selected', $defaultUserLevel);
        $tpl->assign('nb_users_by_level', $nb_users_by_level);

        $groups_arr_id = $groups_arr_name = [];
        foreach (ServiceLocator::get(GroupRepository::class)->findAllOrdered() as $row) {
            $groups_arr_name[] = '"' . addslashes(is_string($row['name'] ?? null) ? $row['name'] : '') . '"';
            $groups_arr_id[]   = is_string($row['id'] ?? null) ? $row['id'] : '';
        }
        $tpl->assign('groups_arr_id', implode(',', $groups_arr_id));
        $tpl->assign('groups_arr_name', implode(',', $groups_arr_name));
        $tpl->assign('guest_id', Config::guestId());
        $viewSelRaw = PreferencesService::get()->userprefsGetParam('user-manager-view', 'line');
        $viewSelStr = is_string($viewSelRaw) ? $viewSelRaw : 'line';
        $tpl->assign('view_selector', $viewSelStr);

        $viewSel = $viewSelStr;
        $paginationRaw = $viewSel === 'line' ? PreferencesService::get()->userprefsGetParam('user-manager-pagination', 5) : PreferencesService::get()->userprefsGetParam('user-manager-pagination', 10);
        $pagination    = is_scalar($paginationRaw) ? $paginationRaw : 5;
        $tpl->assign('pagination', $pagination);

        if ($this->webmasterIdIsLocal()) {
            PageState::current()->addWarning(Lang::t('You have specified <i>' . '$' . 'conf[\'webmaster_id\']</i> in your local configuration file, this parameter in deprecated, please remove it!'));
        }

        $groups_arr_json = [];
        foreach ($groups as $id => $name) {
            $groups_arr_json[] = [$id, $name];
        }

        $rawPagination = PreferencesService::get()->userprefsGetParam('user-manager-pagination', $viewSel === 'line' ? 5 : 10);
        $tpl->assign('page_data_json', json_encode([
            'pwg_token'                => ServiceLocator::get(Util::class)->getPwgToken(),
            'connected_user'           => $userId,
            'connected_user_status'    => $userStatus,
            'owner_id'                 => Config::webmasterId(),
            'owner_username'           => $owner_username[0] ?? '',
            'guest_id'                 => Config::guestId(),
            'has_group'                => $_GET['group'] ?? '',
            'view_selector'            => $viewSel,
            'pagination'               => is_numeric($rawPagination) ? (int) $rawPagination : 0,
            'history_base_url'         => ServiceLocator::get(UrlGenerator::class)->admin('history') . '&filter_user_id=',
            'register_dates'           => $register_dates,
            'groups_arr'               => $groups_arr_json,
            'months'                   => [Lang::t('Jan'), Lang::t('Feb'), Lang::t('Mar'), Lang::t('Apr'), Lang::t('May'), Lang::t('Jun'), Lang::t('Jul'), Lang::t('Aug'), Lang::t('Sep'), Lang::t('Oct'), Lang::t('Nov'), Lang::t('Dec')],
            'status_to_str'            => ['webmaster' => Lang::t('user_status_webmaster'), 'admin' => Lang::t('user_status_admin'), 'normal' => Lang::t('user_status_normal'), 'generic' => Lang::t('user_status_generic'), 'guest' => Lang::t('user_status_guest')],
            'cancel_msg'               => Lang::t('No, I have changed my mind'),
            'cannotSendMail'           => Lang::t("Cannot send an email to this user because he doesn't have an email address"),
            'cantCopy'                 => Lang::t('You cannot copy the password if the connection to this site is not secure.'),
            'confirm_msg'              => Lang::t('Yes, I am sure'),
            'copyLinkStr'              => Lang::t('Copied link'),
            'dates_infos'              => Lang::t('between %s and %s'),
            'errorMailSent'            => Lang::t('Error sending email'),
            'errorMailSentMsg'         => Lang::t('An activation link valid for %s was created but could not be sent. You can now copy the link below and send it to the user.'),
            'errorStr'                 => Lang::t('an error happened'),
            'fieldNotEmpty'            => Lang::t('Name field must not be empty'),
            'filtered_user'            => Lang::t('<b>%d</b> filtered user'),
            'filtered_users'           => Lang::t('<b>%d</b> filtered users'),
            'last_visit_str'           => Lang::t('Last visit'),
            'mailSentAt'               => Lang::t('Mail sent to %s [%s].'),
            'mainAskWebmaster'         => Lang::t('You are not authorised to change the main user, please ask your webmaster'),
            'mainUserContinue'         => Lang::t('You are about to set %s as main user instead of %s, do you wish to continue ?'),
            'mainUserRewrite'          => Lang::t('To be sure, please rewrite the word "%s" below'),
            'mainUserSet'              => Lang::t('Set as main user'),
            'mainUserStr'              => Lang::t('Main user'),
            'mainUserSuccess'          => Lang::t('%s is the new main user'),
            'mainUserUpgradeWebmaster' => Lang::t('This user must first be defined as the webmaster before it can be upgraded to the main user'),
            'mainUserValidate'         => Lang::t('You can now change the main user from %s to %s.'),
            'missingConfirm'           => Lang::t('You need to confirm deletion'),
            'missingConfPassword'      => Lang::t('Password confirmation is missing. Please confirm the chosen password.'),
            'missingField'             => Lang::t('Please complete all fields'),
            'missingPassword'          => Lang::t('Password is missing. Please enter the password.'),
            'missingUsername'          => Lang::t('Please, enter a login'),
            'noMatchPassword'          => Lang::t('The passwords do not match'),
            'nb_days'                  => Lang::t('%d days'),
            'nb_photos'                => Lang::t('%d photos'),
            'passwordCopied'           => Lang::t('Password copied'),
            'registered_str'           => Lang::t('Registered'),
            'str_and_others_tags'      => Lang::t('and %s others'),
            'title_msg'                => Lang::t('Are you sure you want to delete the user "%s"?'),
            'user_added_str'           => Lang::t('User %s added'),
            'validLinkMail'            => Lang::t("An activation link valid for %s has been sent to \"%s\". If the user doesn't receive the link, you can generate and copy a new one by editing the user and managing her password."),
            'validLinkWithoutMail'     => Lang::t('Copy the link below and send it to the user so the password can be set.'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'user_list');
    }

    // ── user_perm ─────────────────────────────────────────────────────────────

    private function userPerm(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        if (!empty($_POST)) {
            ServiceLocator::get(Util::class)->checkPwgToken();
            ServiceLocator::get(Util::class)->checkInputParameter('cat_true', $_POST, true, ValidationPattern::ID);
            ServiceLocator::get(Util::class)->checkInputParameter('cat_false', $_POST, true, ValidationPattern::ID);
        }

        if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
            $page['user'] = $_GET['user_id'];
        } else {
            throw new ValidationException('user_id URL parameter is missing');
        }
        $pageUser = (int) $page['user'];

        $rawCatTrue3  = $_POST['cat_true']  ?? null;
        $rawCatFalse3 = $_POST['cat_false'] ?? null;
        $post_cat_true  = is_array($rawCatTrue3) ? $rawCatTrue3 : [];
        $post_cat_false = is_array($rawCatFalse3) ? $rawCatFalse3 : [];

        if (isset($_POST['falsify']) && count($post_cat_true) > 0) {
            $post_cat_true_ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $post_cat_true);
            $subcats = ServiceLocator::get(CategoryService::class)->getSubcatIds($post_cat_true_ids);
            ServiceLocator::get(PermissionRepository::class)->deleteUserAccessForUser($pageUser, array_map(intval(...), $subcats));
        } elseif (isset($_POST['trueify']) && count($post_cat_false) > 0) {
            $post_cat_false_ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $post_cat_false);
            ServiceLocator::get(CategoryAdminService::class)->addPermissionOnCategory($post_cat_false_ids, $pageUser);
        }

        $tpl->setFilenames(['user_perm' => 'user_perm.tpl', 'double_select' => 'double_select.tpl']);
        $tpl->assign([
            'TITLE'              => Lang::t('Manage permissions for user "%s"', ServiceLocator::get(UserAdminService::class)->getUsername($pageUser)),
            'L_CAT_OPTIONS_TRUE' => Lang::t('Authorized'),
            'L_CAT_OPTIONS_FALSE' => Lang::t('Forbidden'),
            'F_ACTION'           => ServiceLocator::get(UrlGenerator::class)->admin('user_perm') . '&amp;user_id=' . $pageUser,
        ]);

        $group_authorized = [];
        $groupAuthorizedRows = ServiceLocator::get(PermissionRepository::class)->findGroupAuthorizedCategoriesForUser($pageUser);

        if (count($groupAuthorizedRows) > 0) {
            $cats = [];
            foreach ($groupAuthorizedRows as $row) {
                $cats[]           = $row;
                $group_authorized[] = is_numeric($row['cat_id']) ? (int) $row['cat_id'] : 0;
            }
            usort($cats, ServiceLocator::get(CategoryService::class)->globalRankCompare(...));
            foreach ($cats as $category) {
                $tpl->append('categories_because_of_groups', ServiceLocator::get(HtmlService::class)->getCatDisplayNameCache(is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '', null));
            }
        }

        $query_true = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userAccess() . " ON cat_id = id WHERE status = 'private' AND user_id = " . $pageUser;
        if (count($group_authorized) > 0) {
            $query_true .= ' AND cat_id NOT IN (' . implode(',', $group_authorized) . ')';
        }
        $query_true .= ';';
        ServiceLocator::get(CategoryService::class)->displaySelectCatWrapper($query_true, [], 'category_option_true');

        $authorized_ids = ServiceLocator::get(PermissionRepository::class)->findDirectUserCatIds($pageUser, $group_authorized);

        $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE status = 'private'";
        if (count($authorized_ids) > 0) {
            $query_false .= ' AND id NOT IN (' . implode(',', $authorized_ids) . ')';
        }
        if (count($group_authorized) > 0) {
            $query_false .= ' AND id NOT IN (' . implode(',', $group_authorized) . ')';
        }
        $query_false .= ';';
        ServiceLocator::get(CategoryService::class)->displaySelectCatWrapper($query_false, [], 'category_option_false');

        $tpl->assign('PWG_TOKEN', ServiceLocator::get(Util::class)->getPwgToken());
        $tpl->assignVarFromHandle('DOUBLE_SELECT', 'double_select');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'user_perm');
    }

    // ── user_activity ─────────────────────────────────────────────────────────

    private function userActivity(): void
    {
        $tpl = TemplateRegistry::current();
        ServiceLocator::get(Util::class)->checkInputParameter('photo', $_GET, false, ValidationPattern::ID);
        ServiceLocator::get(Util::class)->checkInputParameter('album', $_GET, false, ValidationPattern::ID);
        ServiceLocator::get(Util::class)->checkInputParameter('group', $_GET, false, ValidationPattern::ID);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        $page['tab'] = 'user_activity';
        ServiceLocator::get(UserTabRenderer::class)->render();

        if (isset($_GET['type']) && 'download_logs' == $_GET['type']) {
            $usernameField = Config::userFields()['username'];
            $idField       = Config::userFields()['id'];
            $activityRows  = ServiceLocator::get(Connection::class)
                ->executeQuery("SELECT activity_id, performed_by, object, object_id, action, ip_address, occured_on, details, $usernameField AS username FROM " . Tables::activity() . ' JOIN ' . Tables::users() . " AS u ON performed_by = u.$idField WHERE object = 'user' ORDER BY activity_id DESC")
                ->fetchAllAssociative();

            $output_lines = [['User', 'ID_User', 'Object', 'Object_ID', 'Action', 'Date', 'Hour', 'IP_Address', 'Details']];
            foreach ($activityRows as $row) {
                $row['details'] = str_replace('`groups`', 'groups', is_string($row['details'] ?? null) ? $row['details'] : '');
                $row['details'] = str_replace('`rank`', 'rank', $row['details']);
                $occurredOnRaw = $row['occured_on'] ?? null;
                [$date, $hour] = explode(' ', is_string($occurredOnRaw) ? $occurredOnRaw : '');
                $output_lines[] = ['username' => $row['username'], 'user_id' => $row['performed_by'], 'object' => $row['object'], 'object_id' => $row['object_id'], 'action' => $row['action'], 'date' => $date, 'hour' => $hour, 'ip_address' => $row['ip_address'], 'details' => $row['details']];
            }

            header('Content-type: application/csv');
            header('Content-Disposition: attachment; filename=' . date('YmdGis') . 'piwigo_activity_log.csv');
            header('Content-Transfer-Encoding: UTF-8');
            $f = fopen('php://output', 'w');
            if ($f !== false) {
                foreach ($output_lines as $line) {
                    fputcsv($f, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $line), ';', '"', '\\');
                }
                fclose($f);
            }
            exit();
        }

        $tpl->setFilename('user_activity', 'user_activity.tpl');
        $tpl->assign('ADMIN_PAGE_TITLE', Lang::t('Users'));

        $cache_keys = ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys(['users']);
        $tpl->assign([
            'PWG_TOKEN'                    => ServiceLocator::get(Util::class)->getPwgToken(),
            'INHERIT'                      => Config::inheritanceByDefault(),
            'CACHE_KEYS'                   => $cache_keys,
            'user_activity_page_data_json' => json_encode(['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => UrlService::getRootUrl(), 'str_create' => Lang::t('Create')]),
        ]);

        $nb_lines_for_user = array_column(DbConnection::get()->executeQuery('SELECT performed_by, COUNT(*) as counter FROM ' . Tables::activity() . " WHERE object != 'system' GROUP BY performed_by;")->fetchAllAssociative(), 'counter', 'performed_by');

        $query = 'SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . Tables::users() . ' WHERE ' . Config::userFields()['id'] . ' IN (0);';
        if (count($nb_lines_for_user) > 0) {
            $query = 'SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . Tables::users() . ' WHERE ' . Config::userFields()['id'] . ' IN (' . implode(',', array_keys($nb_lines_for_user)) . ');';
        }

        $username_of = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'username', 'id');

        $filterable_users = [];
        foreach ($nb_lines_for_user as $id => $nb_line) {
            $filterable_users[] = ['id' => $id, 'username' => $username_of[$id] ?? 'user#' . $id, 'nb_lines' => $nb_line];
        }
        $tpl->assign('ulist', $filterable_users);

        $nb_users = ServiceLocator::get(UserRepository::class)->countAll(Tables::users());
        $tpl->assign('nb_users', $nb_users);

        $actRepo  = ServiceLocator::get(ActivityRepository::class);
        $min_date = $actRepo->findOldestDate();
        $max_date = $actRepo->findNewestDate();

        $tpl->assign('ACTIVITY_DATES', ['min' => ($min_date === null || $min_date === '') ? '' : substr($min_date, 0, 10), 'max' => ($max_date === null || $max_date === '') ? '' : substr($max_date, 0, 10)]);

        $additional_filt_type  = false;
        $additional_filt_name  = null;
        $additional_filt_value = null;

        foreach (['photo' => Tables::images(), 'album' => Tables::categories(), 'group' => Tables::groups()] as $filter_key => $filter_table) {
            if (isset($_GET[$filter_key])) {
                $filterId = is_string($_GET[$filter_key]) ? $_GET[$filter_key] : '0';
                $rows = DbConnection::get()->executeQuery('SELECT name FROM ' . $filter_table . ' WHERE id = ' . $filterId . ';')->fetchAllAssociative();
                if (count($rows) == 0) {
                    HtmlService::fatalError($filter_key . ' #' . $filterId . ' does not exist');
                }
                $additional_filt_type  = $filter_key;
                $additional_filt_name  = $rows[0]['name'];
                $additional_filt_value = $filterId;
                break;
            }
        }

        $tpl->assign('ADDITIONAL_FILT', ['type' => $additional_filt_type, 'name' => $additional_filt_name, 'value' => $additional_filt_value]);

        $query = 'SELECT object, action, count(*) AS counter FROM ' . Tables::activity() . " WHERE object != 'system'";
        if ($additional_filt_type) {
            $query .= ' AND object = "' . $additional_filt_type . '"';
        }
        $query .= ' GROUP BY action, object ORDER BY object ASC;';

        $actions = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
        foreach ($actions as &$action) {
            $action['value'] = (is_string($action['object'] ?? null) ? $action['object'] : '') . '/' . (is_string($action['action'] ?? null) ? $action['action'] : '');
        }
        unset($action);

        $tpl->assign('ACTIONS', $actions);
        $tpl->assign('page_data_json', json_encode([
            'nb_users'                      => $nb_users,
            'additional_filt_type'          => $additional_filt_type ?: null,
            'additional_filt_value'         => $additional_filt_value !== null ? (is_numeric($additional_filt_value) ? (int) $additional_filt_value : 0) : null,
            'date_min'                      => ($min_date === null || $min_date === '') ? '' : substr($min_date, 0, 10),
            'date_max'                      => ($max_date === null || $max_date === '') ? '' : substr($max_date, 0, 10),
            'color_icons'                   => ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'],
            'page_ellipsis'                 => '<span>...</span>',
            'page_item'                     => '<a data-page="%d">%d</a>',
            'users_key'                     => Lang::t('Users'),
            'line_key'                      => Lang::t('%s line'),
            'lines_key'                     => Lang::t('%s lines'),
            'actionType_add'                => Lang::t('add'),
            'actionType_delete'             => Lang::t('deletion'),
            'actionType_move'               => Lang::t('move'),
            'actionType_edit'               => Lang::t('edit'),
            'actionType_login'              => Lang::t('login'),
            'actionType_logout'             => Lang::t('logout'),
            'actionInfos_album_added'       => Lang::t('%d album added'),
            'actionInfos_album_deleted'     => Lang::t('%d album deleted'),
            'actionInfos_album_edited'      => Lang::t('%d album edited'),
            'actionInfos_album_moved'       => Lang::t('%d album moved'),
            'actionInfos_albums_added'      => Lang::t('%d albums added'),
            'actionInfos_albums_deleted'    => Lang::t('%d albums deleted'),
            'actionInfos_albums_edited'     => Lang::t('%d albums edited'),
            'actionInfos_albums_moved'      => Lang::t('%d albums moved'),
            'actionInfos_user_added'        => Lang::t('%d user added'),
            'actionInfos_user_deleted'      => Lang::t('%d user deleted'),
            'actionInfos_user_edited'       => Lang::t('%d user edited'),
            'actionInfos_user_logged_in'    => Lang::t('%d user logged in'),
            'actionInfos_user_logged_out'   => Lang::t('%d user logged out'),
            'actionInfos_users_added'       => Lang::t('%d users added'),
            'actionInfos_users_deleted'     => Lang::t('%d users deleted'),
            'actionInfos_users_edited'      => Lang::t('%d users edited'),
            'actionInfos_users_logged_in'   => Lang::t('%d users logged in'),
            'actionInfos_users_logged_out'  => Lang::t('%d users logged out'),
            'actionInfos_photo_added'       => Lang::t('%d photo added'),
            'actionInfos_photo_deleted'     => Lang::t('%d photo deleted'),
            'actionInfos_photo_edited'      => Lang::t('%d photo edited'),
            'actionInfos_photo_moved'       => Lang::t('%d photo moved'),
            'actionInfos_photos_added'      => Lang::t('%d photos added'),
            'actionInfos_photos_deleted'    => Lang::t('%d photos deleted'),
            'actionInfos_photos_edited'     => Lang::t('%d photos edited'),
            'actionInfos_photos_moved'      => Lang::t('%d photos moved'),
            'actionInfos_group_added'       => Lang::t('%d group added'),
            'actionInfos_group_deleted'     => Lang::t('%d group deleted'),
            'actionInfos_group_edited'      => Lang::t('%d group edited'),
            'actionInfos_group_moved'       => Lang::t('%d group moved'),
            'actionInfos_groups_added'      => Lang::t('%d groups added'),
            'actionInfos_groups_deleted'    => Lang::t('%d groups deleted'),
            'actionInfos_groups_edited'     => Lang::t('%d groups edited'),
            'actionInfos_groups_moved'      => Lang::t('%d groups moved'),
            'actionInfos_tag_added'         => Lang::t('%d tag added'),
            'actionInfos_tag_deleted'       => Lang::t('%d tag deleted'),
            'actionInfos_tag_edited'        => Lang::t('%d tag edited'),
            'actionInfos_tag_moved'         => Lang::t('%d tag moved'),
            'actionInfos_tags_added'        => Lang::t('%d tags added'),
            'actionInfos_tags_deleted'      => Lang::t('%d tags deleted'),
            'actionInfos_tags_edited'       => Lang::t('%d tags edited'),
            'actionInfos_tags_moved'        => Lang::t('%d tags moved'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'user_activity');
    }

    private function webmasterIdIsLocal(): bool
    {
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
}
