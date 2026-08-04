<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\GroupPermPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/group_perm.php (page slug "group_perm") -- a flat page,
 * pure delegate. Already used GroupService/GroupRepository/AuditService
 * (P18) directly for its own group-category permission grant/deny before
 * this batch; nothing new to extract.
 */
final class GroupPermSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Users\CurrentUser $currentUser,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Audit\AuditService $auditService,
        private readonly \Piwigo\Category\CategoryService $categoryService,
        private readonly \Piwigo\Group\GroupService $groupService,
        private readonly \Piwigo\Permission\PermissionService $permissionService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new GroupPermPageRenderer($this->accessControl, $this->redirectService, $this->urlService, $this->currentUser, $this->currentTemplate, $this->auditService, $this->categoryService, $this->groupService, $this->permissionService, $this->htmlRenderer)
            ->render();
    }
}
