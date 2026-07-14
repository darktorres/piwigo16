<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\UserActivityPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/user_activity.php (page slug "user_activity") -- a flat,
 * read-only page, pure delegate. Confirmed via direct read: no write
 * logic at all (aside from the ?type=download_logs CSV-export branch,
 * which streams directly and exits, never touching persistent state).
 */
final class UserActivitySubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new UserActivityPageRenderer()
            ->render();
    }
}
