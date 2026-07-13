<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/group_perm.php (page slug "group_perm") -- a flat page,
 * pure delegate. Already used GroupService/GroupRepository/AuditService
 * (P18) directly for its own group-category permission grant/deny before
 * this batch; nothing new to extract.
 */
final class GroupPermSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/group_perm.php';
    }
}
