<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\GroupPermView;
use Piwigo\Admin\Request\GroupPermSubmitRequest;
use Piwigo\Audit\AuditService;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Controller\Admin\Projection\DoubleSelectView;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\TypedRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Group\GroupService;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/group_perm.php (page slug "group_perm"). Already uses
 * GroupService/AuditService directly for its own group-category
 * permission grant/deny; nothing new to extract.
 */
final readonly class GroupPermPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentUser $currentUser,
        private AuditService $auditService,
        private CategoryService $categoryService,
        private GroupService $groupService,
        private PermissionService $permissionService,
        private HtmlRenderingInterface $htmlRenderer,
        private InputValidator $inputValidator,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    public function render(): AdminPageResult
    {
        $categoryService = $this->categoryService;

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $groupPermSubmit = GroupPermSubmitRequest::fromGlobals($this->inputValidator);

        if ($groupPermSubmit->isSubmitted) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        $cat_true = $groupPermSubmit->catTrue;
        $cat_false = $groupPermSubmit->catFalse;

        if (! $groupPermSubmit->groupIdPresent) {
            $this->htmlRenderer
                ->fatalError('group_id URL parameter is missing');
        }

        $groupId = $groupPermSubmit->groupId;
        if (! $groupId instanceof GroupId) {
            $this->htmlRenderer
                ->fatalError('group_id URL parameter is missing');
        }

        $group_service = $this->groupService;

        // [SEC-57] actor for either branch below
        $actor_id = $this->currentUser->get()
            ->id;

        if ($groupPermSubmit->isFalsify
            and count($cat_true) > 0) {
            // if you forbid access to a category, all sub-categories become
            // automatically forbidden
            $subcats = $categoryService->getSubcatIds($cat_true);
            $subcat_ids = array_map(intval(...), $subcats);
            $group_service->removeAccess($groupId, array_map(CategoryId::from(...), $subcat_ids));

            $this->auditService
                ->record($actor_id, 'permission_revoke', 'group', $groupId->value, [
                    'category_ids' => $subcat_ids,
                ], null);
        } elseif ($groupPermSubmit->isTrueify
                 and count($cat_false) > 0) {
            $uppercats = $categoryService->getUppercatIds($cat_false);
            $private_uppercat_ids = $this->permissionService
                ->getPrivateCategoryIdsAmong(array_values(array_map(intval(...), $uppercats)));

            // GroupService::addAccess() itself skips categories the group is
            // already authorized for (retrying to authorize an already-authorized
            // category may cause a duplicate-key SQL error otherwise).
            $group_service->addAccess($groupId, array_map(CategoryId::from(...), $private_uppercat_ids));

            $this->auditService
                ->record($actor_id, 'permission_grant', 'group', $groupId->value, null, [
                    'category_ids' => $private_uppercat_ids,
                ]);
        }

        // only private categories are listed
        $categoryOptionTrue = $categoryService->displaySelectPrivateGrantedToGroup($groupId->value, $this->htmlRenderer);

        $authorized_ids = array_map(strval(...), $categoryService->getPrivateCategoryIdsGrantedToGroup($groupId->value));

        $categoryOptionFalse = $categoryService->displaySelectPrivateExcluding($authorized_ids, $this->htmlRenderer);

        $doubleSelect = $this->renderer->render(new DoubleSelectView(
            lCatOptionsTrue: $this->lang->t('Authorized'),
            lCatOptionsFalse: $this->lang->t('Forbidden'),
            categoryOptionTrue: $categoryOptionTrue->options,
            categoryOptionTrueSelected: $categoryOptionTrue->selected,
            categoryOptionFalse: $categoryOptionFalse->options,
            categoryOptionFalseSelected: $categoryOptionFalse->selected,
        ));

        $adminContent = $this->renderer->render(new GroupPermView(
            title: $this->lang->t(
                'Manage permissions for group "%s"',
                TypedRepository::narrow($this->entityManager->getRepository(GroupEntity::class), GroupRepository::class)
                    ->findName($groupId) ?? false
            ),
            // Plain '&', not '&amp;': formAction reaches group_perm.latte as
            // a bare {$formAction|noescape} print (P59 Batch 5).
            formAction: $this->urlService->getRootUrl() .
                'admin.php?page=group_perm&group_id=' .
                $groupId->value,
            pwgToken: $this->csrfService
                ->getToken(),
            doubleSelect: $doubleSelect,
        ));

        return new AdminPageResult(content: $adminContent);
    }
}
