<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\GroupPermPageRenderer;
use Piwigo\Audit\AuditService;
use Piwigo\Auth\AccessControl;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Group\GroupService;
use Piwigo\Permission\PermissionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Validation\InputValidator;
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
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTemplate $currentTemplate,
        private readonly AuditService $auditService,
        private readonly CategoryService $categoryService,
        private readonly GroupService $groupService,
        private readonly PermissionService $permissionService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly InputValidator $inputValidator,
        private readonly CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new GroupPermPageRenderer($this->lang, $this->accessControl, $this->redirectService, $this->urlService, $this->currentUser, $this->currentTemplate, $this->auditService, $this->categoryService, $this->groupService, $this->permissionService, $this->htmlRenderer, $this->inputValidator, $this->currentConfig)
            ->render();
    }
}
