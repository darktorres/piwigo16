<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\GroupPermPageRenderer;
use Piwigo\Audit\AuditService;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Group\GroupService;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/group_perm.php (page slug "group_perm") -- a flat page,
 * pure delegate. Already uses GroupService/GroupRepository/AuditService
 * directly for its own group-category permission grant/deny; nothing
 * new to extract.
 */
final readonly class GroupPermSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private AuditService $auditService,
        private CategoryService $categoryService,
        private GroupService $groupService,
        private PermissionService $permissionService,
        private HtmlRenderingInterface $htmlRenderer,
        private InputValidator $inputValidator,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new GroupPermPageRenderer($this->lang, $this->accessControl, $this->redirectService, $this->urlService, $this->currentUser, $this->currentTemplate, $this->auditService, $this->categoryService, $this->groupService, $this->permissionService, $this->htmlRenderer, $this->inputValidator, $this->entityManager, $this->csrfService)
            ->render();
    }
}
