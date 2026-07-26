<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Audit\AuditService;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\GroupService;

/**
 * Ported from admin/group_perm.php (page slug "group_perm"). Already used
 * GroupService/GroupRepository/AuditService (P18) directly for its own
 * group-category permission grant/deny before this batch; nothing new to
 * extract.
 */
final class GroupPermPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    private static function auditService(): AuditService
    {
        return \Piwigo\Bootstrap\CoreDomainAccessor::auditService();
    }

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $conn = DbConnection::build();
        $categoryService = \Piwigo\Bootstrap\CoreDomainAccessor::categoryService();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        $groupPermSubmit = Request\GroupPermSubmitRequest::fromGlobals();

        if ($groupPermSubmit->isSubmitted) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
        }

        $cat_true = $groupPermSubmit->catTrue;
        $cat_false = $groupPermSubmit->catFalse;

        if (! $groupPermSubmit->groupIdPresent) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('group_id URL parameter is missing');
        }

        if (! is_numeric($groupPermSubmit->groupId)) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('group_id URL parameter is missing');
        }

        $group_id = (int) $groupPermSubmit->groupId;

        $group_service = \Piwigo\Bootstrap\CoreDomainAccessor::groupService();

        // [SEC-57] actor for either branch below
        $actor_id = \Piwigo\Users\CurrentUser::get()->id;

        if ($groupPermSubmit->isFalsify
            and count($cat_true) > 0) {
            // if you forbid access to a category, all sub-categories become
            // automatically forbidden
            $subcats = $categoryService->getSubcatIds($cat_true);
            $subcat_ids = array_map(intval(...), $subcats);
            $group_service->removeAccess($group_id, $subcat_ids);

            self::auditService()
                ->record($actor_id, 'permission_revoke', 'group', $group_id, [
                    'category_ids' => $subcat_ids,
                ], null);
        } elseif ($groupPermSubmit->isTrueify
                 and count($cat_false) > 0) {
            $uppercats = $categoryService->getUppercatIds($cat_false);
            $private_uppercats = [];

            $query = '
SELECT id
  FROM ' . Tables::categories() . '
  WHERE id IN (' . implode(',', $uppercats) . ')
  AND status = \'private\'
;';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                if (is_int($row['id']) || is_string($row['id'])) {
                    $private_uppercats[] = (string) $row['id'];
                }
            }

            // GroupService::addAccess() itself skips categories the group is
            // already authorized for (retrying to authorize an already-authorized
            // category may cause a duplicate-key SQL error otherwise).
            $private_uppercat_ids = array_map(intval(...), $private_uppercats);
            $group_service->addAccess($group_id, $private_uppercat_ids);

            self::auditService()
                ->record($actor_id, 'permission_grant', 'group', $group_id, null, [
                    'category_ids' => $private_uppercat_ids,
                ]);
        }

        $template->set_filenames(
            [
                'group_perm' => 'group_perm.tpl',
                'double_select' => 'double_select.tpl',
            ]
        );

        $template->assign(
            [
                'TITLE' => Lang::t(
                    'Manage permissions for group "%s"',
                    \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Group\GroupEntity::class)
                        ->findName($group_id) ?? false
                ),
                'L_CAT_OPTIONS_TRUE' => Lang::t('Authorized'),
                'L_CAT_OPTIONS_FALSE' => Lang::t('Forbidden'),

                'F_ACTION' => $this->urlService->getRootUrl() .
                    'admin.php?page=group_perm&amp;group_id=' .
                    $group_id,
            ]
        );

        // only private categories are listed
        $query_true = '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . ' INNER JOIN ' . Tables::groupAccess() . ' ON cat_id = id
  WHERE status = \'private\'
    AND group_id = ' . $group_id . '
;';
        $categoryService->displaySelectCatWrapper($query_true, [], 'category_option_true', \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $template);

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
        $query_false .= '
;';
        $categoryService->displaySelectCatWrapper($query_false, [], 'category_option_false', \Piwigo\Bootstrap\PresentationAccessor::htmlService(), $template);

        $template->assign('PWG_TOKEN', new \Piwigo\Csrf\CsrfService()->getToken());

        $template->assign_var_from_handle('DOUBLE_SELECT', 'double_select');
        $template->assign_var_from_handle('ADMIN_CONTENT', 'group_perm');
    }
}
