<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Activity\ActivityEntity;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Admin\Projection\MaintenanceSysPageContext;
use Piwigo\Admin\Request\MaintenanceSysMethodRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Template\CurrentTemplate;

/**
 * Renders the "sys" tab of the "maintenance" admin page (dispatched by
 * MaintenanceSubController) -- webmaster-only system activity log viewer,
 * backed by a GET-only JSON ajax endpoint
 * (`?method=pwg.activity_sys.getList`). Read-only, so no CSRF concern.
 *
 * The is_webmaster() check is a real, stricter guard layered on top of
 * admin.php's AccessLevel::Administrator gate, not a redundant one.
 *
 * Per-row icon/color/label/detail formatting lives in
 * Piwigo\Admin\Maintenance\ActivityLogEntryFormatter.
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

        $template->assignContext(new MaintenanceSysPageContext(isWebmaster: $accessControl->isWebmaster()));
        $template->set_filenames([
            'maintenance' => 'maintenance_sys.tpl',
        ]);

        // +-------------------------------------------------------------------+
        // |                           sending html code                           |
        // +-------------------------------------------------------------------+

        $template->assign_var_from_handle('ADMIN_CONTENT', 'maintenance');
    }
}
