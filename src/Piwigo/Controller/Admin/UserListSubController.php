<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_list.php (page slug "user_list") -- a flat page,
 * pure delegate. Confirmed via direct read: no write logic of its own
 * (user create/delete/status-change go through the WS API, not this
 * page); only defines one page-local helper, webmaster_id_is_local().
 */
final class UserListSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/user_list.php';
    }
}
