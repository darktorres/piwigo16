<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\ServiceLocator;
use Piwigo\Group\GroupRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;

final class GroupsController
{
    /** @var list<string> */
    public const array PAGES = [
        'group_list',
        'group_perm',
    ];

    public function handle(string $page): void
    {
        if ($page === 'group_list') {
            $this->groupList();
        } elseif ($page === 'group_perm') {
            $this->groupPerm();
        }
    }

    // ── group_list ────────────────────────────────────────────────────────────

    private function groupList(): void
    {
        $tpl = TemplateRegistry::current();
        $GLOBALS['my_base_url'] = $my_base_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';

        $tabsheet = new Tabsheet();
        $tabsheet->setId('groups');
        $tabsheet->select('group_list');
        $tabsheet->assign();

        if (!empty($_POST) || isset($_GET['delete']) || isset($_GET['toggle_is_default'])) {
            check_pwg_token();
        }

        $tpl->setFilenames(['group_list' => 'group_list.tpl']);

        $cache_keys = ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys(['groups', 'users']);
        $tpl->assign([
            'F_ADD_ACTION'              => ServiceLocator::get(UrlGenerator::class)->admin('group_list'),
            'PWG_TOKEN'                 => get_pwg_token(),
            'CACHE_KEYS'                => $cache_keys,
            'ROOT_URL'                  => get_root_url(),
            'group_list_page_data_json' => json_encode(['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => get_root_url(), 'str_create' => l10n('Create')]),
            'page_data_json'            => json_encode([
                'pwg_token'                    => get_pwg_token(),
                'rootUrl'                      => get_root_url(),
                'serverId'                     => $cache_keys['_hash'],
                'serverKey'                    => $cache_keys['users'],
                'str_copy'                     => l10n(' (copy)'),
                'str_delete'                   => l10n('Are you sure you want to delete group "%s"?'),
                'str_group_created'            => l10n('Group added'),
                'str_group_deleted'            => l10n('Group "%s" succesfully deleted'),
                'str_groups_deleted'           => l10n('Groups {%s} succesfully deleted'),
                'str_member_default'           => l10n('member'),
                'str_members_default'          => l10n('members'),
                'str_merged_into'              => l10n('Group(s) {%s1} succesfully merged into "%s2"'),
                'str_name_not_empty'           => l10n('Name field must not be empty'),
                'str_name_taken'               => l10n('Name is already taken'),
                'str_no_delete_confirmation'   => l10n('No, I have changed my mind'),
                'str_other_copy'               => l10n(' (copy %s)'),
                'str_renaming_done'            => l10n('Group renamed'),
                'str_set_default'              => l10n('Set as group for new users'),
                'str_unset_default'            => l10n('Unset as group for new users'),
                'str_user_associated'          => l10n('User associated'),
                'str_user_dissociate'          => l10n('Dissociate user from this group'),
                'str_user_dissociated'         => l10n('User "%s" dissociated from this group'),
                'str_user_list'                => l10n('Manage the members'),
                'str_yes_delete_confirmation'  => l10n('Yes, delete'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $groupRepo  = ServiceLocator::get(GroupRepository::class);
        $userFields = Config::userFields();

        $admin_url             = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=';
        $perm_url              = $admin_url . 'group_perm&amp;group_id=';
        $users_url             = $admin_url . 'user_list&amp;group=';
        $del_url               = $admin_url . 'group_list&amp;delete=';
        $toggle_is_default_url = $admin_url . 'group_list&amp;toggle_is_default=';
        $group_counter         = 0;

        foreach ($groupRepo->findAllOrdered() as $row) {
            $row_id_str = is_scalar($row['id']) ? (string) $row['id'] : '';
            $members    = $groupRepo->findMemberUsernamesByGroupId($userFields['username'], $userFields['id'], USERS_TABLE, is_numeric($row['id']) ? (int) $row['id'] : 0);
            $tpl->append('groups', [
                'NAME'      => $row['name'],
                'ID'        => $row['id'],
                'IS_DEFAULT' => (BoolUtil::fromMixed($row['is_default']) ? ' [' . l10n('default') . ']' : ''),
                'NB_MEMBERS' => count($members),
                'L_MEMBERS'  => implode(' <span class="userSeparator">&middot;</span> ', $members),
                'MEMBERS'    => l10n_dec('%d member', '%d members', count($members)),
                'U_DELETE'   => $del_url . $row_id_str . '&amp;pwg_token=' . get_pwg_token(),
                'U_PERM'     => $perm_url . $row_id_str,
                'U_USERS'    => $users_url . $row_id_str,
                'U_ISDEFAULT' => $toggle_is_default_url . $row_id_str . '&amp;pwg_token=' . get_pwg_token(),
            ]);
            $group_counter++;
        }

        $tpl->assign('ADMIN_PAGE_TITLE', l10n('Groups') . ' <span class="badge-number">' . $group_counter . '</span>');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'group_list');
    }

    // ── group_perm ────────────────────────────────────────────────────────────

    private function groupPerm(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        if (!empty($_POST)) {
            check_pwg_token();
            check_input_parameter('cat_true', $_POST, true, PATTERN_ID);
            check_input_parameter('cat_false', $_POST, true, PATTERN_ID);
        }

        if (!isset($_GET['group_id'])) {
            fatal_error('group_id URL parameter is missing');
        }

        check_input_parameter('group_id', $_GET, false, PATTERN_ID);
        $page['group'] = $_GET['group_id'];
        $group_id      = is_scalar($page['group']) ? (int) $page['group'] : 0;

        $post_cat_true  = is_array($_POST['cat_true'] ?? null) ? $_POST['cat_true'] : [];
        $post_cat_false = is_array($_POST['cat_false'] ?? null) ? $_POST['cat_false'] : [];

        if (isset($_POST['falsify']) && count($post_cat_true) > 0) {
            $post_cat_true_ids = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $post_cat_true);
            $subcats = get_subcat_ids($post_cat_true_ids);
            ServiceLocator::get(PermissionRepository::class)->deleteGroupAccessForGroup($group_id, array_map(intval(...), $subcats));
        } elseif (isset($_POST['trueify']) && count($post_cat_false) > 0) {
            $post_cat_false_ids = array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $post_cat_false);
            $uppercats     = ServiceLocator::get(CategoryAdminService::class)->getUppercatIds($post_cat_false_ids);
            $uppercats_str = array_map(fn (string $v): string => $v, $uppercats);

            $permRepo = ServiceLocator::get(PermissionRepository::class);
            $catRepo  = ServiceLocator::get(CategoryRepository::class);

            $private_uppercats = $catRepo->findPrivateByIds(array_map(intval(...), $uppercats_str));
            $authorized_ids    = $permRepo->findAuthorizedCatIdsByGroup($group_id);

            $inserts = [];
            foreach (array_diff($private_uppercats, $authorized_ids) as $to_autorize_id) {
                $inserts[] = ['group_id' => $group_id, 'cat_id' => $to_autorize_id];
            }
            mass_inserts(GROUP_ACCESS_TABLE, ['group_id', 'cat_id'], $inserts);
            ServiceLocator::get(UserAdminService::class)->invalidateUserCache();
        }

        $tpl->setFilenames(['group_perm' => 'group_perm.tpl', 'double_select' => 'double_select.tpl']);
        $tpl->assign([
            'TITLE'              => l10n('Manage permissions for group "%s"', ServiceLocator::get(UserAdminService::class)->getGroupname($group_id)),
            'L_CAT_OPTIONS_TRUE' => l10n('Authorized'),
            'L_CAT_OPTIONS_FALSE' => l10n('Forbidden'),
            'F_ACTION'           => ServiceLocator::get(UrlGenerator::class)->admin('group_perm') . '&amp;group_id=' . $group_id,
        ]);

        $query_true = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . ' INNER JOIN ' . GROUP_ACCESS_TABLE . " ON cat_id = id WHERE status = 'private' AND group_id = " . $group_id . ';';
        display_select_cat_wrapper($query_true, [], 'category_option_true');

        $authorized_ids = ServiceLocator::get(PermissionRepository::class)->findAuthorizedPrivateCatIdsByGroup($group_id);

        $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . CATEGORIES_TABLE . " WHERE status = 'private'";
        if (count($authorized_ids) > 0) {
            $query_false .= ' AND id NOT IN (' . implode(',', $authorized_ids) . ')';
        }
        $query_false .= ';';
        display_select_cat_wrapper($query_false, [], 'category_option_false');

        $tpl->assign('PWG_TOKEN', get_pwg_token());
        $tpl->assignVarFromHandle('DOUBLE_SELECT', 'double_select');
        $tpl->assignVarFromHandle('ADMIN_CONTENT', 'group_perm');
    }
}
