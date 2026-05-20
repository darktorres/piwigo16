<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Latte\Runtime\Html;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\BoolUtil;
use Piwigo\Core\Lang;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Validation\InputValidator;

final readonly class GroupsController implements AdminSubControllerInterface
{
    /** @var list<string> */
    public const array PAGES = [
        'group_list',
        'group_perm',
    ];

    public function __construct(
        private AdminService $adminService,
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private GroupRepository $groupRepository,
        private PermissionRepository $permissionRepository,
        private UrlGenerator $urlGenerator,
        private UserAdminService $userAdminService,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
    ) {
    }

    #[\Override]
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

        $tabsheet = new Tabsheet();
        $tabsheet->setId('groups');
        $tabsheet->select('group_list');
        $tabsheet->assign();

        if (!empty($_POST) || isset($_GET['delete']) || isset($_GET['toggle_is_default'])) {
            $this->csrfService->check();
        }

        $cache_keys = $this->adminService->getAdminClientCacheKeys(['groups', 'users']);
        $tpl->assign([
            'F_ADD_ACTION'              => $this->urlGenerator->admin('group_list'),
            'PWG_TOKEN'                 => $this->csrfService->getToken(),
            'CACHE_KEYS'                => $cache_keys,
            'ROOT_URL'                  => UrlService::getRootUrl(),
            'group_list_page_data_json' => json_encode(['CACHE_KEYS' => $cache_keys, 'ROOT_URL' => UrlService::getRootUrl(), 'str_create' => Lang::t('Create')]),
            'page_data_json'            => json_encode([
                'pwg_token'                    => $this->csrfService->getToken(),
                'rootUrl'                      => UrlService::getRootUrl(),
                'serverId'                     => $cache_keys['_hash'],
                'serverKey'                    => $cache_keys['users'],
                'str_copy'                     => Lang::t(' (copy)'),
                'str_delete'                   => Lang::t('Are you sure you want to delete group "%s"?'),
                'str_group_created'            => Lang::t('Group added'),
                'str_group_deleted'            => Lang::t('Group "%s" succesfully deleted'),
                'str_groups_deleted'           => Lang::t('Groups {%s} succesfully deleted'),
                'str_member_default'           => Lang::t('member'),
                'str_members_default'          => Lang::t('members'),
                'str_merged_into'              => Lang::t('Group(s) {%s1} succesfully merged into "%s2"'),
                'str_name_not_empty'           => Lang::t('Name field must not be empty'),
                'str_name_taken'               => Lang::t('Name is already taken'),
                'str_no_delete_confirmation'   => Lang::t('No, I have changed my mind'),
                'str_other_copy'               => Lang::t(' (copy %s)'),
                'str_renaming_done'            => Lang::t('Group renamed'),
                'str_set_default'              => Lang::t('Set as group for new users'),
                'str_unset_default'            => Lang::t('Unset as group for new users'),
                'str_user_associated'          => Lang::t('User associated'),
                'str_user_dissociate'          => Lang::t('Dissociate user from this group'),
                'str_user_dissociated'         => Lang::t('User "%s" dissociated from this group'),
                'str_user_list'                => Lang::t('Manage the members'),
                'str_yes_delete_confirmation'  => Lang::t('Yes, delete'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $groupRepo  = $this->groupRepository;
        $userFields = Config::userFields();

        $admin_url             = $this->urlGenerator->admin() . '&page=';
        $perm_url              = $admin_url . 'group_perm&group_id=';
        $users_url             = $admin_url . 'user_list&group=';
        $del_url               = $admin_url . 'group_list&delete=';
        $toggle_is_default_url = $admin_url . 'group_list&toggle_is_default=';
        $group_counter         = 0;

        foreach ($groupRepo->findAllOrdered() as $row) {
            $row_id_str = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
            $members    = $groupRepo->findMemberUsernamesByGroupId($userFields->username, $userFields->id, Tables::users(), is_numeric($row['id']) ? (int) $row['id'] : 0);
            $tpl->append('groups', [
                'NAME'      => $row['name'],
                'ID'        => $row['id'],
                'IS_DEFAULT' => (BoolUtil::fromMixed(is_string($row['is_default']) || is_int($row['is_default']) || is_float($row['is_default']) ? $row['is_default'] : null) ? ' [' . Lang::t('default') . ']' : ''),
                'NB_MEMBERS' => count($members),
                'L_MEMBERS'  => implode(' <span class="userSeparator">&middot;</span> ', $members),
                'MEMBERS'    => Translator::get()->plural('%d member', '%d members', count($members)),
                'U_DELETE'   => $del_url . $row_id_str . '&pwg_token=' . $this->csrfService->getToken(),
                'U_PERM'     => $perm_url . $row_id_str,
                'U_USERS'    => $users_url . $row_id_str,
                'U_ISDEFAULT' => $toggle_is_default_url . $row_id_str . '&pwg_token=' . $this->csrfService->getToken(),
            ]);
            $group_counter++;
        }

        $tpl->assign('ADMIN_PAGE_TITLE', new Html(Lang::t('Groups') . ' <span class="badge-number">' . $group_counter . '</span>'));
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'group_list.latte');
    }

    // ── group_perm ────────────────────────────────────────────────────────────

    private function groupPerm(): void
    {
        $tpl = TemplateRegistry::current();
        if (!empty($_POST)) {
            $this->csrfService->check();
            $this->inputValidator->check('cat_true', $_POST, true, ValidationPattern::ID);
            $this->inputValidator->check('cat_false', $_POST, true, ValidationPattern::ID);
        }

        if (!isset($_GET['group_id'])) {
            HtmlService::fatalError('group_id URL parameter is missing');
        }

        $this->inputValidator->check('group_id', $_GET, false, ValidationPattern::ID);
        $getGroupId = $_GET['group_id'];
        $group_id   = is_numeric($getGroupId) ? (int) $getGroupId : 0;

        $rawCatTrue  = $_POST['cat_true']  ?? null;
        $rawCatFalse = $_POST['cat_false'] ?? null;
        $post_cat_true  = is_array($rawCatTrue) ? $rawCatTrue : [];
        $post_cat_false = is_array($rawCatFalse) ? $rawCatFalse : [];

        if (isset($_POST['falsify']) && count($post_cat_true) > 0) {
            $post_cat_true_ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $post_cat_true);
            $subcats = $this->categoryService->getSubcatIds($post_cat_true_ids);
            $this->permissionRepository->deleteGroupAccessForGroup($group_id, array_map(intval(...), $subcats));
        } elseif (isset($_POST['trueify']) && count($post_cat_false) > 0) {
            $post_cat_false_ids = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $post_cat_false);
            $uppercats     = $this->categoryAdminService->getUppercatIds($post_cat_false_ids);
            $uppercats_str = array_map(fn (string $v): string => $v, $uppercats);

            $permRepo = $this->permissionRepository;
            $catRepo  = $this->categoryRepository;

            $private_uppercats = $catRepo->findPrivateByIds(array_map(intval(...), $uppercats_str));
            $authorized_ids    = $permRepo->findAuthorizedCatIdsByGroup($group_id);

            $inserts = [];
            foreach (array_diff($private_uppercats, $authorized_ids) as $to_autorize_id) {
                $inserts[] = ['group_id' => $group_id, 'cat_id' => $to_autorize_id];
            }
            $this->permissionRepository->insertGroupAccessRows($inserts);
            $this->userAdminService->invalidateUserCache();
        }

        $tpl->assign([
            'TITLE'              => Lang::t('Manage permissions for group "%s"', $this->userAdminService->getGroupname($group_id)),
            'L_CAT_OPTIONS_TRUE' => Lang::t('Authorized'),
            'L_CAT_OPTIONS_FALSE' => Lang::t('Forbidden'),
            'F_ACTION'           => $this->urlGenerator->admin('group_perm') . '&group_id=' . $group_id,
        ]);

        $query_true = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::groupAccess() . " ON cat_id = id WHERE status = 'private' AND group_id = " . $group_id . ';';
        $this->categoryService->displaySelectCatWrapper($query_true, [], 'category_option_true');

        $authorized_ids = $this->permissionRepository->findAuthorizedPrivateCatIdsByGroup($group_id);

        $query_false = 'SELECT id,name,uppercats,global_rank FROM ' . Tables::categories() . " WHERE status = 'private'";
        if (count($authorized_ids) > 0) {
            $query_false .= ' AND id NOT IN (' . implode(',', $authorized_ids) . ')';
        }
        $query_false .= ';';
        $this->categoryService->displaySelectCatWrapper($query_false, [], 'category_option_false');

        $tpl->assign('PWG_TOKEN', $this->csrfService->getToken());
        $tpl->assignVarFromTemplate('DOUBLE_SELECT', 'double_select.latte');
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'group_perm.latte');
    }
}
