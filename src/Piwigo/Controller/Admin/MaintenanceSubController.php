<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/maintenance.php (page slug "maintenance") -- a tab
 * dispatcher (actions/env/sys) that stays a pure delegate; its own tab
 * dispatch is already validated (/^(actions|env|sys)$/). This batch closed
 * SEC-22 (both phpinfo() call sites, in the "actions" and "env" tabs,
 * replaced with Piwigo\Admin\Maintenance\ServerInfoService's curated
 * output -- PHP/SAPI/OS version, loaded extensions, key ini settings, not
 * a full phpinfo() dump) and extracted the raw SQL shared (as near-
 * identical duplicate code, confirmed by direct read, not assumed) between
 * the "actions" and "env" tabs into Piwigo\Admin\Maintenance\
 * DbMaintenanceRepository. The "sys" tab's own raw activity-log query
 * moved onto a new Piwigo\Activity\ActivityRepository::
 * findSystemObjectLogWithUsernames() method instead (a natural sibling of
 * that repository's existing findUserObjectLogWithUsernames(), not a new
 * Maintenance-namespaced class, since it's squarely an Activity-domain
 * query).
 *
 * Real bugs found and fixed in the "actions" tab's own 'search' case: a
 * dead sprintf(...) statement (result never assigned to $page['infos'][])
 * and its message text was a copy-paste of the 'c13y' case's own
 * ("Reinitialize check integrity") instead of "Purge search history".
 */
final class MaintenanceSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/maintenance.php';
    }
}
