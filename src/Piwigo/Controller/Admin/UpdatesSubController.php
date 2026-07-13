<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/updates.php (page slug "updates") -- a tab dispatcher
 * (pwg/ext) that stays a pure delegate. This batch also fixed a real,
 * verified bug in the dispatched-to file: its ?tab= value was never
 * validated before being spliced into `include admin/updates_<tab>.php`
 * (unlike plugins.php/themes.php/languages.php's own tab dispatch, which
 * all allowlist their tab values) -- a genuine local file inclusion, not
 * just a hypothetical one, reachable by any authenticated admin.
 *
 * The 2 leaf files this dispatches to (updates_pwg.php/updates_ext.php)
 * were migrated off the updates.class.php god-class onto the new
 * CoreUpdateService (Piwigo-core self-update) and ExtensionUpdateChecker
 * (cross-type "any installed extension outdated" check), both built on
 * PemCatalog/ExtensionScanner (this batch's real scope). updates.class.php
 * itself is NOT deleted -- install.php/upgrade.php/include/functions.inc.php's
 * telemetry sender/include/ws_functions/pwg.extensions.php all still
 * construct it directly, and none of those are admin pages (P21's real
 * scope).
 */
final class UpdatesSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/updates.php';
    }
}
