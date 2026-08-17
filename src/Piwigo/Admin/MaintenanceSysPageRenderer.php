<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Admin\Projection\MaintenanceSysPageContext;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Template\CurrentTemplate;

/**
 * Renders the "sys" tab of the "maintenance" admin page (dispatched by
 * MaintenanceSubController) -- webmaster-only system activity log viewer,
 * server-rendered directly, no ajax round-trip.
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
    public function render(Lang $lang, AccessControl $accessControl, array $maintActions, PageState $pageState, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EntityManagerInterface $entityManager): void
    {
        $template = $currentTemplate->get();

        $activity_log_entries = [];
        if ($accessControl->isWebmaster()) {
            $activity_log = $entityManager->getRepository(ActivityEntity::class)
                ->findSystemObjectLogWithUsernames();

            $formatter = new ActivityLogEntryFormatter();
            foreach ($activity_log as $row) {
                $activity_log_entries[] = $formatter->format($lang, $row, $maintActions);
            }
        } else {
            $pageState->addWarning(str_replace('%s', $lang->t('user_status_webmaster'), $lang->t('%s status is required to edit parameters.')));
        }

        $template->assignContext(new MaintenanceSysPageContext(
            isWebmaster: $accessControl->isWebmaster(),
            activityLogEntries: $activity_log_entries,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'maintenance_sys.latte');
    }
}
