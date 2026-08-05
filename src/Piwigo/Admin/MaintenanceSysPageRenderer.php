<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityEntity;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Admin\Request\MaintenanceSysMethodRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Template\CurrentTemplate;

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
    /**
     * @param array<string, array{icon: string, label: string}> $maintActions
     */
    public function render(Lang $lang, AccessControl $accessControl, array $maintActions, PageState $pageState, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig): void
    {
        $template = $currentTemplate->get();

        // +-------------------------------------------------------------------+
        // |                    Only Webmaster can see this tab                    |
        // +-------------------------------------------------------------------+

        if ($accessControl->isWebmaster()) {
            // Get system activities data
            if (MaintenanceSysMethodRequest::fromGlobals()->isActivitySysGetList) {
                $data = [];

                $activity_log = EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class)
                    ->findSystemObjectLogWithUsernames();

                $formatter = new ActivityLogEntryFormatter();

                // Format our data for frontend
                foreach ($activity_log as $rows) {
                    $data[] = $formatter->format($lang, $rows, $maintActions);
                }

                // Now we good to send our response data
                $response = [
                    'data' => $data,
                ];
                echo json_encode($response);
                exit;
            }
        } else {
            $pageState->addWarning(str_replace('%s', $lang->t('user_status_webmaster'), $lang->t('%s status is required to edit parameters.')));
        }

        // +-------------------------------------------------------------------+
        // |                             template init                             |
        // +-------------------------------------------------------------------+

        $template->assign('isWebmaster', ($accessControl->isWebmaster()) ? 1 : 0);
        $template->set_filenames([
            'maintenance' => 'maintenance_sys.tpl',
        ]);

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
    }
}
