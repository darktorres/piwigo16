<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\Users\UserTabRenderer;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\SchemaHelper;
use Piwigo\Exception\ValidationException;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\UserRepository;

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

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        check_input_parameter('group', $_GET, false, PATTERN_ID);
        check_input_parameter('user_id', $_GET, false, PATTERN_ID);

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
            'ADMIN_PAGE_TITLE'   => l10n('Users'),
            'ACTIVATE_COMMENTS'  => Config::activateComments(),
            'Double_Password'    => Config::doublePasswordTypeInAdmin(),
        ]);
        $tpl->set_filenames(['user_list' => 'user_list.tpl']);

        $default_user = get_default_user_info(true);
        $userId       = is_numeric($user['id']) ? (int) $user['id'] : 0;
        $userStatus   = is_string($user['status']) ? $user['status'] : '';

        $protected_users = [(string) $userId, (string) Config::guestId(), (string) Config::defaultUserId(), (string) Config::webmasterId()];
        $password_protected_users = [(string) Config::guestId()];

        if ($userStatus === 'admin') {
            $admin_ids = array_column(get_dbal_connection()->executeQuery('SELECT user_id FROM ' . USER_INFOS_TABLE . " WHERE status IN ('webmaster', 'admin');")->fetchAllAssociative(), 'user_id');
            $admin_ids_str = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $admin_ids);
            $protected_users = array_merge($protected_users, $admin_ids_str);
            $password_protected_users = array_merge($password_protected_users, array_diff($admin_ids_str, [(string) $userId]));
        }

        $owner_username = array_column(get_dbal_connection()->executeQuery('SELECT ' . Config::userFields()['username'] . ' AS username FROM ' . USERS_TABLE . ' WHERE ' . Config::userFields()['id'] . ' = ' . Config::webmasterId() . ';')->fetchAllAssociative(), 'username');

        $tpl->assign([
            'U_HISTORY'                 => ServiceLocator::get(UrlGenerator::class)->admin('history') . '&filter_user_id=',
            'PWG_TOKEN'                 => get_pwg_token(),
            'NB_IMAGE_PAGE'             => $default_user['nb_image_page'] ?? null,
            'RECENT_PERIOD'             => $default_user['recent_period'] ?? null,
            'theme_options'             => get_pwg_themes(),
            'theme_selected'            => get_default_theme(),
            'language_options'          => get_languages(),
            'language_selected'         => get_default_language(),
            'association_options'       => $groups,
            'protected_users'           => implode(',', array_unique($protected_users)),
            'password_protected_users'  => implode(',', array_unique($password_protected_users)),
            'guest_user'                => Config::guestId(),
            'filter_group'              => $_GET['group'] ?? null,
            'search_input'              => isset($_GET['user_id']) ? 'id:' . (is_scalar($_GET['user_id']) ? (string) $_GET['user_id'] : '') : null,
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
        foreach (SchemaHelper::getEnums(USER_INFOS_TABLE, 'status') as $status) {
            $label_of_status[$status] = l10n('user_status_' . $status);
        }

        $nb_users_by_status = [];
        foreach (ServiceLocator::get(UserRepository::class)->findStatusDistribution(Config::guestId()) as $status => $count) {
            $nb_users_by_status[$status] = ['name' => l10n('user_status_' . $status), 'counter' => $count];
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
            $level_options[$level] = l10n(sprintf('Level %d', $level));
        }
        $nb_users_by_level = $level_options;
        foreach (ServiceLocator::get(UserRepository::class)->findLevelDistribution(Config::guestId()) as $level => $count) {
            $nb_users_by_level[$level] = ['name' => l10n(sprintf('Level %d', $level)), 'counter' => $count];
        }
        $tpl->assign('level_options', $level_options);
        $tpl->assign('level_selected', $default_user['level'] ?? 0);
        $tpl->assign('nb_users_by_level', $nb_users_by_level);

        $groups_arr_id = $groups_arr_name = [];
        foreach (ServiceLocator::get(GroupRepository::class)->findAllOrdered() as $row) {
            $groups_arr_name[] = '"' . addslashes(is_scalar($row['name']) ? (string) $row['name'] : '') . '"';
            $groups_arr_id[]   = is_scalar($row['id']) ? (string) $row['id'] : '';
        }
        $tpl->assign('groups_arr_id', implode(',', $groups_arr_id));
        $tpl->assign('groups_arr_name', implode(',', $groups_arr_name));
        $tpl->assign('guest_id', Config::guestId());
        $tpl->assign('view_selector', userprefs_get_param('user-manager-view', 'line'));

        $viewSel = userprefs_get_param('user-manager-view', 'line');
        $tpl->assign('pagination', $viewSel === 'line' ? userprefs_get_param('user-manager-pagination', 5) : userprefs_get_param('user-manager-pagination', 10));

        if ($this->webmasterIdIsLocal()) {
            PageState::current()->addWarning(l10n('You have specified <i>' . '$' . 'conf[\'webmaster_id\']</i> in your local configuration file, this parameter in deprecated, please remove it!'));
        }

        $groups_arr_json = [];
        foreach ($groups as $id => $name) {
            $groups_arr_json[] = [$id, $name];
        }

        $rawPagination = userprefs_get_param('user-manager-pagination', $viewSel === 'line' ? 5 : 10);
        $tpl->assign('page_data_json', json_encode([
            'pwg_token'                => get_pwg_token(),
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
            'months'                   => [l10n('Jan'), l10n('Feb'), l10n('Mar'), l10n('Apr'), l10n('May'), l10n('Jun'), l10n('Jul'), l10n('Aug'), l10n('Sep'), l10n('Oct'), l10n('Nov'), l10n('Dec')],
            'status_to_str'            => ['webmaster' => l10n('user_status_webmaster'), 'admin' => l10n('user_status_admin'), 'normal' => l10n('user_status_normal'), 'generic' => l10n('user_status_generic'), 'guest' => l10n('user_status_guest')],
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

        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'user_list');
    }

    // ── user_perm ─────────────────────────────────────────────────────────────

    private function userPerm(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        if (!empty($_POST)) {
            check_pwg_token();
            check_input_parameter('cat_true', $_POST, true, PATTERN_ID);
            check_input_parameter('cat_false', $_POST, true, PATTERN_ID);
        }

        if (isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
            $page['user'] = $_GET['user_id'];
        } else {
            throw new ValidationException('user_id URL parameter is missing');
        }
        $pageUser = (int) $page['user'];

        $post_cat_true  = is_array($_POST['cat_true'] ?? null) ? $_POST['cat_true'] : [];
        $post_cat_false = is_array($_POST['cat_false'] ?? null) ? $_POST['cat_false'] : [];

        if (isset($_POST['falsify']) && count($post_cat_true) > 0) {
            $post_cat_true_ids = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $post_cat_true);
            $subcats = get_subcat_ids($post_cat_true_ids);
            ServiceLocator::get(PermissionRepository::class)->deleteUserAccessForUser($pageUser, array_map(intval(...), $subcats));
        } elseif (isset($_POST['trueify']) && count($post_cat_false) > 0) {
            $post_cat_false_ids = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $post_cat_false);
            add_permission_on_category($post_cat_false_ids, $pageUser);
        }

        $tpl->set_filenames(['user_perm' => 'user_perm.tpl', 'double_select' => 'double_select.tpl']);
        $tpl->assign([
            'TITLE'              => l10n('Manage permissions for user "%s"', get_username($pageUser)),
            'L_CAT_OPTIONS_TRUE' => l10n('Authorized'),
            'L_CAT_OPTIONS_FALSE' => l10n('Forbidden'),
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
            usort($cats, global_rank_compare(...));
            foreach ($cats as $category) {
                $tpl->append('categories_because_of_groups', get_cat_display_name_cache(is_scalar($category['uppercats'] ?? null) ? (string) $category['uppercats'] : '', null));
            }
        }

        $query_true = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . USER_ACCESS_TABLE . " ON cat_id = id WHERE status = 'private' AND user_id = " . $pageUser;
        if (count($group_authorized) > 0) {
            $query_true .= ' AND cat_id NOT IN (' . implode(',', $group_authorized) . ')';
        }
        $query_true .= ';';
        display_select_cat_wrapper($query_true, [], 'category_option_true');

        $authorized_ids = ServiceLocator::get(PermissionRepository::class)->findDirectUserCatIds($pageUser, $group_authorized);

        $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE status = 'private'";
        if (count($authorized_ids) > 0) {
            $query_false .= ' AND id NOT IN (' . implode(',', $authorized_ids) . ')';
        }
        if (count($group_authorized) > 0) {
            $query_false .= ' AND id NOT IN (' . implode(',', $group_authorized) . ')';
        }
        $query_false .= ';';
        display_select_cat_wrapper($query_false, [], 'category_option_false');

        $tpl->assign('PWG_TOKEN', get_pwg_token());
        $tpl->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'user_perm');
    }

    // ── user_activity ─────────────────────────────────────────────────────────

    private function userActivity(): void
    {
        $tpl = TemplateRegistry::current();

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        check_input_parameter('photo', $_GET, false, PATTERN_ID);
        check_input_parameter('album', $_GET, false, PATTERN_ID);
        check_input_parameter('group', $_GET, false, PATTERN_ID);

        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        $page['tab'] = 'user_activity';
        ServiceLocator::get(UserTabRenderer::class)->render();

        if (isset($_GET['type']) && 'download_logs' == $_GET['type']) {
            $usernameField = Config::userFields()['username'];
            $idField       = Config::userFields()['id'];
            $activityRows  = ServiceLocator::get(Connection::class)
                ->executeQuery("SELECT activity_id, performed_by, object, object_id, action, ip_address, occured_on, details, $usernameField AS username FROM " . ACTIVITY_TABLE . ' JOIN ' . USERS_TABLE . " AS u ON performed_by = u.$idField WHERE object = 'user' ORDER BY activity_id DESC")
                ->fetchAllAssociative();

            $output_lines = [['User', 'ID_User', 'Object', 'Object_ID', 'Action', 'Date', 'Hour', 'IP_Address', 'Details']];
            foreach ($activityRows as $row) {
                $row['details'] = str_replace('`groups`', 'groups', is_scalar($row['details']) ? (string) $row['details'] : '');
                $row['details'] = str_replace('`rank`', 'rank', $row['details']);
                [$date, $hour] = explode(' ', is_scalar($row['occured_on']) ? (string) $row['occured_on'] : '');
                $output_lines[] = ['username' => $row['username'], 'user_id' => $row['performed_by'], 'object' => $row['object'], 'object_id' => $row['object_id'], 'action' => $row['action'], 'date' => $date, 'hour' => $hour, 'ip_address' => $row['ip_address'], 'details' => $row['details']];
            }

            header('Content-type: application/csv');
            header('Content-Disposition: attachment; filename=' . date('YmdGis') . 'piwigo_activity_log.csv');
            header('Content-Transfer-Encoding: UTF-8');
            $f = fopen('php://output', 'w');
            if ($f !== false) {
                foreach ($output_lines as $line) {
                    fputcsv($f, array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $line), ';', '"', '\\');
                }
                fclose($f);
            }
            exit();
        }

        $tpl->set_filename('user_activity', 'user_activity.tpl');
        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Users'));

        $cache_keys = get_admin_client_cache_keys(['users']);
        $tpl->assign([
            'PWG_TOKEN'                    => get_pwg_token(),
            'INHERIT'                      => Config::inheritanceByDefault(),
            'CACHE_KEYS'                   => $cache_keys,
            'user_activity_page_data_json' => json_encode(['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => get_root_url(), 'str_create' => l10n('Create')]),
        ]);

        $nb_lines_for_user = array_column(get_dbal_connection()->executeQuery('SELECT performed_by, COUNT(*) as counter FROM ' . ACTIVITY_TABLE . " WHERE object != 'system' GROUP BY performed_by;")->fetchAllAssociative(), 'counter', 'performed_by');

        $query = 'SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . USERS_TABLE . ' WHERE ' . Config::userFields()['id'] . ' IN (0);';
        if (count($nb_lines_for_user) > 0) {
            $query = 'SELECT ' . Config::userFields()['id'] . ' AS id, ' . Config::userFields()['username'] . ' AS username FROM ' . USERS_TABLE . ' WHERE ' . Config::userFields()['id'] . ' IN (' . implode(',', array_keys($nb_lines_for_user)) . ');';
        }

        $username_of = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'username', 'id');

        $filterable_users = [];
        foreach ($nb_lines_for_user as $id => $nb_line) {
            $filterable_users[] = ['id' => $id, 'username' => $username_of[$id] ?? 'user#' . $id, 'nb_lines' => $nb_line];
        }
        $tpl->assign('ulist', $filterable_users);

        $nb_users = ServiceLocator::get(UserRepository::class)->countAll(USERS_TABLE);
        $tpl->assign('nb_users', $nb_users);

        $actRepo  = ServiceLocator::get(ActivityRepository::class);
        $min_date = $actRepo->findOldestDate();
        $max_date = $actRepo->findNewestDate();

        $tpl->assign('ACTIVITY_DATES', ['min' => empty($min_date) ? '' : substr((string) $min_date, 0, 10), 'max' => empty($max_date) ? '' : substr((string) $max_date, 0, 10)]);

        $additional_filt_type  = false;
        $additional_filt_name  = null;
        $additional_filt_value = null;

        foreach (['photo' => IMAGES_TABLE, 'album' => CATEGORIES_TABLE, 'group' => GROUPS_TABLE] as $filter_key => $filter_table) {
            if (isset($_GET[$filter_key])) {
                $filterId = is_scalar($_GET[$filter_key]) ? (string) $_GET[$filter_key] : '0';
                $rows = get_dbal_connection()->executeQuery('SELECT name FROM ' . $filter_table . ' WHERE id = ' . $filterId . ';')->fetchAllAssociative();
                if (count($rows) == 0) {
                    fatal_error($filter_key . ' #' . $filterId . ' does not exist');
                }
                $additional_filt_type  = $filter_key;
                $additional_filt_name  = $rows[0]['name'];
                $additional_filt_value = $filterId;
                break;
            }
        }

        $tpl->assign('ADDITIONAL_FILT', ['type' => $additional_filt_type, 'name' => $additional_filt_name, 'value' => $additional_filt_value]);

        $query = 'SELECT object, action, count(*) AS counter FROM ' . ACTIVITY_TABLE . " WHERE object != 'system'";
        if ($additional_filt_type) {
            $query .= ' AND object = "' . $additional_filt_type . '"';
        }
        $query .= ' GROUP BY action, object ORDER BY object ASC;';

        $actions = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        foreach ($actions as &$action) {
            $action['value'] = (is_scalar($action['object']) ? (string) $action['object'] : '') . '/' . (is_scalar($action['action']) ? (string) $action['action'] : '');
        }
        unset($action);

        $tpl->assign('ACTIONS', $actions);
        $tpl->assign('page_data_json', json_encode([
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

        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'user_activity');
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
