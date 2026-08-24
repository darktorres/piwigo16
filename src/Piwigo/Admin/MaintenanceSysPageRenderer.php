<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\Maintenance\ActivityLogEntryFormatter;
use Piwigo\Admin\Projection\MaintenanceSysView;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Db\TypedRepository;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;

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
    public function render(Lang $lang, AccessControl $accessControl, array $maintActions, PageState $pageState, CurrentTemplate $currentTemplate, CurrentConfig $currentConfig, EntityManagerInterface $entityManager, Renderer $renderer): AdminPageResult
    {
        $activity_log_entries = [];
        if ($accessControl->isWebmaster()) {
            $activity_log = TypedRepository::narrow($entityManager->getRepository(ActivityEntity::class), ActivityRepository::class)
                ->findSystemObjectLogWithUsernames();

            $formatter = new ActivityLogEntryFormatter();
            foreach ($activity_log as $row) {
                $activity_log_entries[] = $formatter->format($lang, $row, $maintActions);
            }
        } else {
            $pageState->addWarning(str_replace('%s', $lang->t('user_status_webmaster'), $lang->t('%s status is required to edit parameters.')));
        }

        $adminContent = $renderer->render(new MaintenanceSysView(
            isWebmaster: $accessControl->isWebmaster(),
            activityLogEntries: $activity_log_entries,
        ));

        return new AdminPageResult(content: $adminContent);
    }
}
