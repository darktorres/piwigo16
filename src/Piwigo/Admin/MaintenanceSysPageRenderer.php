<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Db\DbConnection;
use Piwigo\Template\Template;

/**
 * Ported from admin/maintenance_sys.php (the "sys" tab of the
 * "maintenance" page slug, dispatched by MaintenanceSubController) --
 * webmaster-only system activity log viewer, backed by a GET-only JSON
 * ajax endpoint (`?method=pwg.activity_sys.getList`). Read-only, so no
 * CSRF concern (matching admin.php's own gate + this file's own
 * is_webmaster() check, which is a real, stricter, still-load-bearing
 * guard on top of admin.php's AccessLevel::Administrator gate, not a
 * redundant one to drop).
 *
 * The per-row icon/color/label/detail formatting is extracted into
 * Piwigo\Admin\Maintenance\ActivityLogEntryFormatter (P23 batch 6h) --
 * see that class's own docblock.
 */
final class MaintenanceSysPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $maint_actions
         * @var array<string, mixed> $page
         */
        global $conf, $maint_actions, $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        // +-------------------------------------------------------------------+
        // |                    Only Webmaster can see this tab                    |
        // +-------------------------------------------------------------------+

        if (\Piwigo\Auth\AccessControl::isWebmaster()) {
            // Get system activities data
            if (isset($_GET['method']) && $_GET['method'] === 'pwg.activity_sys.getList') {
                $data = [];
                $maint_actions_arr = $maint_actions;

                // \Piwigo\Config\Config::userFields() maps generic field names to actual DB column
                // names (see include/config_default.inc.php); its values are
                // configuration-supplied, not statically typed, hence the fallback.
                $user_fields = \Piwigo\Config\Config::userFields();
                $username_field = is_string($user_fields['username'] ?? null) ? $user_fields['username'] : 'username';
                $id_field = is_string($user_fields['id'] ?? null) ? $user_fields['id'] : 'id';

                $activity_log = new ActivityRepository(DbConnection::build())
                    ->findSystemObjectLogWithUsernames($username_field, $id_field);

                $formatter = new ActivityLogEntryFormatter();

                // Format our data for frontend
                foreach ($activity_log as $rows) {
                    $data[] = $formatter->format($rows, $maint_actions_arr);
                }

                // Now we good to send our response data
                $response = [
                    'data' => $data,
                ];
                echo json_encode($response);
                exit;
            }
        } else {
            if (! is_array($page['warnings'] ?? null)) {
                $page['warnings'] = [];
            }
            $page['warnings'][] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
        }

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);
        $template->set_filenames([
            'maintenance' => 'maintenance_sys.tpl',
        ]);

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
    }
}
