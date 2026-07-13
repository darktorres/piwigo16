<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_activity.php (page slug "user_activity") -- a flat,
 * read-only page, pure delegate. Confirmed via direct read: no write
 * logic at all.
 */
final class UserActivitySubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/user_activity.php';
    }
}
