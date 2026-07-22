<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\UserPermPageRenderer;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_perm.php (page slug "user_perm") -- a flat page,
 * pure delegate. A prior P21 batch extracted its raw `DELETE FROM
 * user_access` query into Piwigo\Permission\PermissionRepository::
 * deleteUserAccess() (called via PermissionService::removeUserAccess()/
 * grantUserAccess()), mirroring GroupService::addAccess()/removeAccess()'s
 * existing (P18) shape for the group-level equivalent.
 */
final class UserPermSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new UserPermPageRenderer($this->redirectService, $this->urlService)
            ->render();
    }
}
