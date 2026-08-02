<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Permission\PermissionService;

/**
 * Ported from admin/user_perm.php (page slug "user_perm"). Its raw
 * `DELETE FROM user_access` query was already extracted into
 * Piwigo\Permission\PermissionRepository::deleteUserAccess() (called via
 * PermissionService::removeUserAccess()/grantUserAccess()) during a prior
 * P21 batch, mirroring GroupService::addAccess()/removeAccess()'s
 * equivalent shape for the group-level case.
 */
final class UserPermPageRenderer
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly PermissionService $permissionService,
        private readonly CategoryService $categoryService,
        private readonly \Piwigo\Users\UserService $userService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;

        $permissionService = $this->permissionService;
        $categoryService = $this->categoryService;

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $userPermSubmit = Request\UserPermSubmitRequest::fromGlobals();

        if ($userPermSubmit->isSubmitted) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer, $this->redirectService);
        }

        $cat_true = $userPermSubmit->catTrue;
        $cat_false = $userPermSubmit->catFalse;

        if (is_numeric($userPermSubmit->userId)) {
            $user_id = (int) $userPermSubmit->userId;
        } else {
            $htmlRenderer->fatalError('user_id URL parameter is missing');
        }

        if ($userPermSubmit->isFalsify
            and count($cat_true) > 0) {
            // if you forbid access to a category, all sub-categories become
            // automatically forbidden
            $subcats = array_map(intval(...), $categoryService->getSubcatIds($cat_true));
            $permissionService->removeUserAccess($user_id, $subcats);
        } elseif ($userPermSubmit->isTrueify
            and count($cat_false) > 0) {
            $permissionService->grantUserAccess($user_id, $cat_false);
        }

        $template->set_filenames(
            [
                'user_perm' => 'user_perm.tpl',
                'double_select' => 'double_select.tpl',
            ]
        );

        $template->assign(
            [
                'TITLE' => $this->lang->t(
                    'Manage permissions for user "%s"',
                    $this->userService
                        ->getUsername(\Piwigo\Common\ValueObject\UserId::from($user_id))->value ?? ''
                ),
                'L_CAT_OPTIONS_TRUE' => $this->lang->t('Authorized'),
                'L_CAT_OPTIONS_FALSE' => $this->lang->t('Forbidden'),

                'F_ACTION' => $this->urlService->getRootUrl() .
                    'admin.php?page=user_perm' .
                    '&amp;user_id=' . $user_id,
            ]
        );

        // retrieve category ids authorized to the groups the user belongs to
        $group_authorized = [];

        $group_rows = $categoryService->getCategoriesAuthorizedViaGroupsForUser($user_id);

        if (count($group_rows) > 0) {
            $cats = [];
            foreach ($group_rows as $row) {
                $cats[] = $row;
                if (is_int($row['cat_id']) || is_string($row['cat_id'])) {
                    $group_authorized[] = (string) $row['cat_id'];
                }
            }
            usort($cats, CategoryService::compareByGlobalRank(...));

            foreach ($cats as $category) {
                if ($category['uppercats'] === null || ! is_string($category['uppercats'])) {
                    continue;
                }

                $template->append(
                    'categories_because_of_groups',
                    $htmlRenderer->getCatDisplayNameCache($category['uppercats'], null)
                );
            }
        }

        // only private categories are listed
        $categoryService->displaySelectPrivateGrantedToUser($user_id, $group_authorized, 'category_option_true', $htmlRenderer, $template);

        $authorized_ids = array_map(
            strval(...),
            $categoryService->getPrivateCategoryIdsGrantedToUser($user_id, array_map(intval(...), $group_authorized))
        );

        $categoryService->displaySelectPrivateExcluding([...$authorized_ids, ...$group_authorized], 'category_option_false', $htmlRenderer, $template);

        $template->assign('PWG_TOKEN', new \Piwigo\Csrf\CsrfService()->getToken());

        $template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'user_perm');
    }
}
