<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/site_update.php (page slug "site_update") -- pure
 * delegate. By far the largest file in this batch (1,100+ lines): a full
 * filesystem/DB synchronization pass (new/deleted category detection, new/
 * deleted/updated element detection, metadata sync), all self-contained
 * business logic directly in the page file rather than delegating to a
 * separate admin/include/*.inc.php helper the way Upload/Albums/Users'
 * pages did. The P21 plan's own scope for this batch names only
 * ConfigService/ConfigRepository/SiteRepository/PermalinkService/
 * MenubarLayoutRepository as reused pieces -- no new "SiteSyncService" is
 * called for, and extracting one now would be a large, high-risk
 * undertaking disproportionate to this batch (matching task #343's own
 * deferral precedent in the Users batch). Left as legacy glue, same
 * "keep page/template logic inline, only extract data access" split used
 * throughout P21 for oversized pages.
 */
final class SiteUpdateSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/site_update.php';
    }
}
