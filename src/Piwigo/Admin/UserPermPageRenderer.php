<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Category\CategoryService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
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
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();

        $conn = DbConnection::build();
        // Built once, reused below -- was the same PermissionService recipe
        // repeated verbatim at 2 sites in this method (Phase 1k DI-chain audit).
        $permissionService = \Piwigo\Bootstrap\CoreDomainAccessor::permissionService();
        $categoryService = \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

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
                'TITLE' => Lang::t(
                    'Manage permissions for user "%s"',
                    \Piwigo\Bootstrap\CoreDomainAccessor::userService()
                        ->getUsername($user_id)
                ),
                'L_CAT_OPTIONS_TRUE' => Lang::t('Authorized'),
                'L_CAT_OPTIONS_FALSE' => Lang::t('Forbidden'),

                'F_ACTION' => $this->urlService->getRootUrl() .
                    'admin.php?page=user_perm' .
                    '&amp;user_id=' . $user_id,
            ]
        );

        // retrieve category ids authorized to the groups the user belongs to
        $group_authorized = [];

        $query = '
SELECT DISTINCT cat_id, c.uppercats, c.global_rank
  FROM ' . Tables::userGroup() . ' AS ug
    INNER JOIN ' . Tables::groupAccess() . ' AS ga
      ON ug.group_id = ga.group_id
    INNER JOIN ' . Tables::categories() . ' AS c
      ON c.id = ga.cat_id
  WHERE ug.user_id = ' . $user_id . '
;';
        $group_rows = $conn->fetchAllAssociative($query);

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
        $query_true = '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::userAccess() . ' ON cat_id = id
  WHERE status = \'private\'
    AND user_id = ' . $user_id;
        if (count($group_authorized) > 0) {
            $query_true .= '
    AND cat_id NOT IN (' . implode(',', $group_authorized) . ')';
        }
        $query_true .= '
;';
        $categoryService->displaySelectCatWrapper($query_true, [], 'category_option_true', $htmlRenderer, $template);

        $authorized_ids = [];
        foreach ($conn->fetchAllAssociative($query_true) as $row) {
            if (is_int($row['id']) || is_string($row['id'])) {
                $authorized_ids[] = (string) $row['id'];
            }
        }

        $query_false = '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE status = \'private\'';
        if (count($authorized_ids) > 0) {
            $query_false .= '
    AND id NOT IN (' . implode(',', $authorized_ids) . ')';
        }
        if (count($group_authorized) > 0) {
            $query_false .= '
    AND id NOT IN (' . implode(',', $group_authorized) . ')';
        }
        $query_false .= '
;';
        $categoryService->displaySelectCatWrapper($query_false, [], 'category_option_false', $htmlRenderer, $template);

        $template->assign('PWG_TOKEN', new \Piwigo\Csrf\CsrfService()->getToken());

        $template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'user_perm');
    }
}
