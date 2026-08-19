<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\DoubleSelectView;
use Piwigo\Admin\Projection\UserPermView;
use Piwigo\Admin\Request\UserPermSubmitRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/user_perm.php (page slug "user_perm"). Its raw
 * `DELETE FROM user_access` query was already extracted into
 * Piwigo\Permission\PermissionRepository::deleteUserAccess() (called via
 * PermissionService::removeUserAccess()), mirroring
 * GroupService::addAccess()/removeAccess()'s
 * equivalent shape for the group-level case.
 */
final readonly class UserPermPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentTemplate $currentTemplate,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private UserService $userService,
        private HtmlRenderingInterface $htmlRenderer,
        private InputValidator $inputValidator,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;

        $permissionService = $this->permissionService;
        $categoryService = $this->categoryService;

        $this->accessControl->checkStatus(AccessLevel::Administrator);

        $userPermSubmit = UserPermSubmitRequest::fromGlobals($this->inputValidator);

        if ($userPermSubmit->isSubmitted) {
            $this->csrfService
                ->checkOrFail($htmlRenderer, $this->redirectService);
        }

        $cat_true = $userPermSubmit->catTrue;
        $cat_false = $userPermSubmit->catFalse;

        if (! $userPermSubmit->userId instanceof UserId) {
            $htmlRenderer->fatalError('user_id URL parameter is missing');
        }

        $user_id = $userPermSubmit->userId->value;

        if ($userPermSubmit->isFalsify
            and count($cat_true) > 0) {
            // if you forbid access to a category, all sub-categories become
            // automatically forbidden
            $subcats = array_map(intval(...), $categoryService->getSubcatIds($cat_true));
            $permissionService->removeUserAccess($user_id, $subcats);
        } elseif ($userPermSubmit->isTrueify
            and count($cat_false) > 0) {
            $permissionService->addPermissionOnCategory($cat_false, $user_id);
        }

        // retrieve category ids authorized to the groups the user belongs to
        $group_authorized = [];
        $categories_because_of_groups = null;

        $group_rows = $categoryService->getCategoriesAuthorizedViaGroupsForUser($user_id);

        if (count($group_rows) > 0) {
            $cats = [];
            foreach ($group_rows as $row) {
                $cats[] = $row;
                $group_authorized[] = (string) $row['cat_id'];
            }
            usort($cats, CategoryService::compareByGlobalRank(...));

            $categories_because_of_groups = [];
            foreach ($cats as $category) {
                $categories_because_of_groups[] = $htmlRenderer->getCatDisplayNameCache($category['uppercats'], null);
            }
        }

        // only private categories are listed
        $categoryOptionTrue = $categoryService->displaySelectPrivateGrantedToUser($user_id, $group_authorized, $htmlRenderer);

        $authorized_ids = array_map(
            strval(...),
            $categoryService->getPrivateCategoryIdsGrantedToUser($user_id, array_map(intval(...), $group_authorized))
        );

        $categoryOptionFalse = $categoryService->displaySelectPrivateExcluding([...$authorized_ids, ...$group_authorized], $htmlRenderer);

        $doubleSelect = $this->renderer->render(new DoubleSelectView(
            lCatOptionsTrue: $this->lang->t('Authorized'),
            lCatOptionsFalse: $this->lang->t('Forbidden'),
            categoryOptionTrue: $categoryOptionTrue->options,
            categoryOptionTrueSelected: $categoryOptionTrue->selected,
            categoryOptionFalse: $categoryOptionFalse->options,
            categoryOptionFalseSelected: $categoryOptionFalse->selected,
        ));

        $adminContent = $this->renderer->render(new UserPermView(
            title: $this->lang->t(
                'Manage permissions for user "%s"',
                $this->userService
                    ->getUsername(UserId::from($user_id))->value ?? ''
            ),
            categoriesBecauseOfGroups: $categories_because_of_groups,
            formAction: $this->urlService->getRootUrl() .
                'admin.php?page=user_perm' .
                '&amp;user_id=' . $user_id,
            pwgToken: $this->csrfService
                ->getToken(),
            doubleSelect: $doubleSelect,
        ));

        $template->assignContext(new AdminContentPageContext(adminContent: $adminContent));
    }
}
