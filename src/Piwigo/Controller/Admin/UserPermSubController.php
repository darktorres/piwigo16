<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\UserPermPageRenderer;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_perm.php (page slug "user_perm") -- a flat page,
 * pure delegate. Its raw `DELETE FROM
 * user_access` query was extracted into Piwigo\Permission\PermissionRepository::
 * deleteUserAccess() (called via PermissionService::removeUserAccess()),
 * mirroring GroupService::addAccess()/removeAccess()'s
 * existing shape for the group-level equivalent.
 */
final readonly class UserPermSubController implements AdminSubControllerInterface
{
    public function __construct(
        private UserPermPageRenderer $userPermPageRenderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        return $this->userPermPageRenderer
            ->render();
    }
}
