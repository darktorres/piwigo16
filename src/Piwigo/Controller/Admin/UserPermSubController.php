<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_perm.php (page slug "user_perm") -- a flat page,
 * pure delegate. This batch extracted its raw `DELETE FROM user_access`
 * query into Piwigo\Permission\PermissionRepository::deleteUserAccess()
 * (called via the new PermissionService::removeUserAccess()/
 * grantUserAccess() methods), mirroring GroupService::addAccess()/
 * removeAccess()'s existing (P18) shape for the group-level equivalent.
 */
final class UserPermSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/user_perm.php';
    }
}
