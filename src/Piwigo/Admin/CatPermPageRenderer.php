<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Projection\CatPermPageContext;
use Piwigo\Admin\Request\CatPermSubmitRequest;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupService;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\UserService;

/**
 * Ported from admin/cat_perm.php (the "permissions" tab of the "album"
 * page slug, dispatched by AlbumSubController).
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65,
 * unconditional, before $_GET['page']/['section'] are even validated), so
 * the original cat_perm.php's own (redundant) check_status() call is
 * dropped here -- same precedent as PhotosAddSubController.
 */
final class CatPermPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CurrentTemplate $currentTemplate,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly GroupService $groupService,
        private readonly UserService $userService,
        private readonly HtmlService $htmlService,
        private readonly CurrentConfig $currentConfig,
    ) {}

    /**
     * $category is AlbumSubController::handle()'s own
     * {@see \Piwigo\Category\Projection\Category::toArray()} result, shared
     * verbatim with AlbumNotificationPageRenderer/CatModifyPageRenderer's
     * own render() calls from that same dispatch site. Unlike
     * CatModifyPageRenderer, this method's own 'status' reassignment below
     * stays the same string type, so the full Projection shape applies
     * safely throughout.
     *
     * @param array{id: int, name: string, id_uppercat: ?int, comment: ?string,
     *   dir: ?string, rank: ?int, status: string, site_id: ?int, visible: bool,
     *   representative_picture_id: ?int, uppercats: string, commentable: bool,
     *   global_rank: ?string, image_order: ?string, permalink: ?string, lastmodified: string} $category
     */
    public function render(string $admin_album_base_url, array $category): void
    {
        // $page is a local scratch array for this method's own body only.
        /** @var array<string, mixed> $page */
        $page = [];
        $template = $this->currentTemplate->get();
        $conn = DbConnection::build();

        // +-------------------------------------------------------------------+
        // |                       variable initialization                     |
        // +-------------------------------------------------------------------+

        $page['cat'] = $category['id'];

        // +-------------------------------------------------------------------+
        // |                           form submission                         |
        // +-------------------------------------------------------------------+

        $save_success = null;
        $catPermSubmit = CatPermSubmitRequest::fromGlobals();
        if ($catPermSubmit->isSubmitted) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlService, $this->redirectService);

            $post_status = $catPermSubmit->status;
            $current_status = $category['status'];
            $apply_on_sub = $catPermSubmit->applyOnSub;

            $post_groups = $catPermSubmit->groups;
            $post_users = $catPermSubmit->users;

            $this->categoryAdminService->setCategoryPermissions($page['cat'], $current_status, $post_status, $apply_on_sub, $post_groups, $post_users);
            $category['status'] = $post_status;

            $save_success = $this->lang->t('Album updated successfully');
        }

        // +-------------------------------------------------------------------+
        // |                       template initialization                     |
        // +-------------------------------------------------------------------+

        $template->setFilename('cat_perm', 'cat_perm.tpl');

        $categories_nav = $this->htmlService
            ->getCatDisplayNameFromId(
                $page['cat'],
                'admin.php?page=album-'
            );

        // +-------------------------------------------------------------------+
        // |                          form construction                        |
        // +-------------------------------------------------------------------+

        // groups denied are the groups not granted. So we need to find all groups
        // minus groups granted to find groups denied.

        $groups = [];
        foreach ($this->groupService->getAllBasic() as $g) {
            $groups[$g->id->value] = $g->name;
        }

        // groups granted to access the category
        $permissionRepository = new PermissionRepository(EntityManagerFactory::build($conn));
        $cat_id = $page['cat'];
        $group_granted_ids = $permissionRepository->findGrantedGroupIdsByCategory([$cat_id])[$cat_id] ?? [];

        // users...
        $users = $this->userService->getAllUsernamesById();

        $user_granted_direct_ids = $permissionRepository->findGrantedUserIdsByCategory([$cat_id])[$cat_id] ?? [];

        $nb_users_granted_indirect = null;
        $user_granted_indirect_groups = null;
        $user_granted_indirect_ids = [];
        if (count($group_granted_ids) > 0) {
            $user_granted_indirect_groups = [];
            $granted_groups = [];

            foreach ($this->groupService->getMembersByGroupIds($group_granted_ids) as $row) {
                // group_id/user_id are NOT NULL numeric columns; DBAL can hand
                // back a native int for either (mysqli always gave a numeric
                // string), so accept both before using group_id as an array key
                // and collecting user_id.
                $row_group_id = $row['group_id'];
                $row_user_id = $row['user_id'];
                if ((! is_int($row_group_id) && ! is_string($row_group_id)) || (! is_int($row_user_id) && ! is_string($row_user_id))) {
                    continue;
                }
                $row_group_id = (int) $row_group_id;
                $row_user_id = (int) $row_user_id;
                if (! isset($granted_groups[$row_group_id])) {
                    $granted_groups[$row_group_id] = [];
                }
                $granted_groups[$row_group_id][] = $row_user_id;
            }

            $user_granted_by_group_ids = [];

            foreach ($granted_groups as $group_users) {
                $user_granted_by_group_ids = array_merge($user_granted_by_group_ids, $group_users);
            }

            $user_granted_by_group_ids = array_unique($user_granted_by_group_ids);

            $user_granted_indirect_ids = array_diff(
                $user_granted_by_group_ids,
                $user_granted_direct_ids
            );

            $nb_users_granted_indirect = count($user_granted_indirect_ids);

            foreach ($granted_groups as $group_id => $group_users) {
                $group_usernames = [];
                foreach ($group_users as $user_id) {
                    // $users is array_column(..., 'username', 'id')'s result,
                    // value type mixed; narrow to the real username string
                    if (in_array($user_id, $user_granted_indirect_ids, true) && isset($users[$user_id]) && is_string($users[$user_id])) {
                        $group_usernames[] = $users[$user_id];
                    }
                }

                $user_granted_indirect_groups[] = [
                    'group_name' => $groups[$group_id],
                    'group_users' => implode(', ', $group_usernames),
                ];
            }
        }

        // +-------------------------------------------------------------------+
        // |                           sending html code                       |
        // +-------------------------------------------------------------------+
        $template->assignContext(new CatPermPageContext(
            saveSuccess: $save_success,
            categoriesNav: $categories_nav,
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=cat_perm',
            fAction: $admin_album_base_url . '-permissions',
            private: ($category['status'] === 'private'),
            groups: $groups,
            groupsSelected: $group_granted_ids,
            users: $users,
            usersSelected: $user_granted_direct_ids,
            nbUsersGrantedIndirect: $nb_users_granted_indirect,
            pwgToken: new CsrfService($this->currentConfig)
                ->getToken(),
            inherit: $this->currentConfig->inheritanceByDefault,
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['groups', 'users']),
            userGrantedIndirectGroups: $user_granted_indirect_groups,
        ));

        $template->assignVarFromHandle('ADMIN_CONTENT', 'cat_perm');
    }
}
