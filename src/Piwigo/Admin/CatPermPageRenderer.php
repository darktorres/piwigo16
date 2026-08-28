<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Projection\CatPermView;
use Piwigo\Admin\Request\CatPermSubmitRequest;
use Piwigo\Category\Projection\Category;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupService;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
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
final readonly class CatPermPageRenderer
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentTemplate $currentTemplate,
        private CategoryAdminService $categoryAdminService,
        private GroupService $groupService,
        private UserService $userService,
        private HtmlService $htmlService,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    /**
     * $category is AlbumSubController::handle()'s own real
     * {@see \Piwigo\Category\Projection\Category} instance, shared verbatim
     * with AlbumNotificationPageRenderer/CatModifyPageRenderer's own
     * render() calls from that same dispatch site. `Category` is
     * `readonly`, so the post-permission-update 'status' value this method
     * used to write back into the array is tracked as a local `$status`
     * variable instead, seeded from `$category->status` and reassigned
     * after the update -- read at every site that used to read the
     * post-mutation array value.
     */
    public function render(string $admin_album_base_url, Category $category): AdminPageResult
    {
        // $page is a local scratch array for this method's own body only.
        /** @var array<string, mixed> $page */
        $page = [];
        $template = $this->currentTemplate->get();

        $page['cat'] = $category->id->value;

        $status = $category->status;

        $save_success = null;
        $catPermSubmit = CatPermSubmitRequest::fromGlobals();
        if ($catPermSubmit->isSubmitted) {
            $this->csrfService
                ->checkOrFail($this->htmlService, $this->redirectService);

            $post_status = $catPermSubmit->status;
            $current_status = $status;
            $apply_on_sub = $catPermSubmit->applyOnSub;

            $post_groups = $catPermSubmit->groups;
            $post_users = $catPermSubmit->users;

            $this->categoryAdminService->setCategoryPermissions($page['cat'], $current_status, $post_status, $apply_on_sub, $post_groups, $post_users);
            $status = $post_status;

            $save_success = $this->lang->t('Album updated successfully');
        }

        // groups denied are the groups not granted. So we need to find all groups
        // minus groups granted to find groups denied.

        $groups = [];
        foreach ($this->groupService->getAllBasic() as $g) {
            $groups[$g->id->value] = $g->name;
        }

        // groups granted to access the category
        $permissionRepository = new PermissionRepository($this->entityManager);
        $cat_id = $page['cat'];
        $group_granted_ids = $permissionRepository->findGrantedGroupIdsByCategory([$cat_id])[$cat_id] ?? [];

        // users...
        $users = $this->userService->getAllUsernamesById();

        $user_granted_direct_ids = $permissionRepository->findGrantedUserIdsByCategory([$cat_id])[$cat_id] ?? [];

        $nb_users_granted_indirect = null;
        // Defaults to the empty list, not null (P58-A's §11). Both this and
        // $nb_users_granted_indirect are filled together in the branch
        // below, and cat_perm.latte guards the whole block on the counter,
        // so the list is only ever read when that branch ran -- but the
        // foreach itself was unguarded, so a null here was a PHP warning
        // away from being visible. An empty list iterates the same way,
        // without one.
        $user_granted_indirect_groups = [];
        $user_granted_indirect_ids = [];
        if (count($group_granted_ids) > 0) {
            $user_granted_indirect_groups = [];
            $granted_groups = [];

            foreach ($this->groupService->getMembersByGroupIds($group_granted_ids) as $row) {
                $row_group_id = $row['group_id'];
                $row_user_id = $row['user_id'];
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
                    if (in_array($user_id, $user_granted_indirect_ids, true) && isset($users[$user_id])) {
                        $group_usernames[] = $users[$user_id];
                    }
                }

                $user_granted_indirect_groups[] = [
                    'group_name' => $groups[$group_id],
                    'group_users' => implode(', ', $group_usernames),
                ];
            }
        }

        $adminContent = $this->renderer->render(new CatPermView(
            fAction: $admin_album_base_url . '-permissions',
            private: ($status === 'private'),
            groups: $groups,
            groupsSelected: $group_granted_ids,
            usersSelected: $user_granted_direct_ids,
            nbUsersGrantedIndirect: $nb_users_granted_indirect,
            userGrantedIndirectGroups: $user_granted_indirect_groups,
            inherit: $this->currentConfig->inheritanceByDefault,
            cacheKeys: AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['groups', 'users']),
            saveSuccess: $save_success,
            csrfToken: $this->csrfService
                ->getToken(),
            colorscheme: $template->themeConf('colorscheme'),
            rootUrl: $this->urlService->getRootUrl(),
        ));

        return new AdminPageResult(
            content: $adminContent,
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=cat_perm',
        );
    }
}
