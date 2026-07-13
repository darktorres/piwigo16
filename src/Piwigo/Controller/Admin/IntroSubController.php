<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/intro.php (the admin dashboard, page slug "intro" -- also
 * admin.php's own default `?page=` fallback) -- a flat page, pure delegate.
 * Its dashboard queries (activity chart, storage chart, general stats via
 * the existing get_pwg_general_statitics()) are single-purpose view-shaping
 * for this one page, the same "page/template glue stays inline" precedent
 * as admin.php's own dashboard-badge queries (pending comments/orphans/
 * locked albums), so no new Admin\Dashboard service was warranted.
 */
final class IntroSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/intro.php';
    }
}
