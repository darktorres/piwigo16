<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\UserPermPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_perm.php (page slug "user_perm") -- a flat page,
 * pure delegate. Its raw `DELETE FROM
 * user_access` query was extracted into Piwigo\Permission\PermissionRepository::
 * deleteUserAccess() (called via PermissionService::removeUserAccess()/
 * grantUserAccess()), mirroring GroupService::addAccess()/removeAccess()'s
 * existing shape for the group-level equivalent.
 */
final class UserPermSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly UserPermPageRenderer $userPermPageRenderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $this->userPermPageRenderer
            ->render();
    }
}
