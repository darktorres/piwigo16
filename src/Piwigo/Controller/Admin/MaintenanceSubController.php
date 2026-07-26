<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\MaintenanceEnvPageRenderer;
use Piwigo\Admin\MaintenanceSysPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/maintenance.php's own tab-dispatch shell (page slug
 * "maintenance"), folded directly into this controller -- same shape as
 * every prior P23 batch 6 sub-batch's shell folding. Its own tab dispatch
 * is already validated (`/^(actions|env|sys)$/`). admin.php itself already
 * gates every page behind check_status(AccessLevel::Administrator) before
 * dispatch, so the shell's own (redundant) check_status() call is dropped
 * here. The shell's own check_pwg_token() gate for every $_GET['action']
 * IS real and load-bearing (no CSRF gap found in this sub-batch, unlike
 * 6d-6g) -- kept unchanged.
 *
 * Correction (found during 6i-4): `$my_base_url` is NOT dead code, despite
 * this docblock originally claiming so. It's consumed indirectly by
 * `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'maintenance':` branch
 * (formerly `admin/include/add_core_tabs.inc.php`'s `add_core_tabs()`,
 * folded in P23 batch 8b-6), read via `global $my_base_url;` when
 * `Tabsheet::select()` fires its `tabsheet_before_select` event a few
 * lines below -- dropping it silently degraded every tab href (missing
 * the `admin.php?page=` prefix entirely). Restored here.
 *
 * A prior P21-era pass had already closed SEC-22 (both phpinfo() call
 * sites, in the "actions" and "env" tabs, replaced with Piwigo\Admin\
 * Maintenance\ServerInfoService's curated output) and extracted the raw
 * SQL shared between the "actions" and "env" tabs into Piwigo\Admin\
 * Maintenance\DbMaintenanceRepository, plus the "sys" tab's own
 * activity-log query onto Piwigo\Activity\ActivityRepository::
 * findSystemObjectLogWithUsernames(). This batch (P23 batch 6h) finishes
 * the job that same class's own docblock had already flagged as
 * outstanding: the "actions"/"env" tabs' own ~18-case action-dispatch
 * switch was still duplicated between them (and had drifted while unused
 * -- see Piwigo\Admin\Maintenance\MaintenanceActionDispatcher's own
 * docblock for the 2 real bugs found and fixed by consolidating it there),
 * ports the 3 tab bodies into Piwigo\Admin\MaintenanceActionsPageRenderer/
 * MaintenanceEnvPageRenderer/MaintenanceSysPageRenderer. (That same P21
 * pass had already fixed a real bug in the "actions" tab's own 'search'
 * case -- a dead sprintf(...) statement whose message text was a
 * copy-paste of the 'c13y' case's own -- carried forward unchanged into
 * MaintenanceActionDispatcher, not re-fixed here.)
 */
final class MaintenanceSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'maintenance' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $maintenanceDispatch = Request\MaintenanceDispatchRequest::fromGlobals();

        if ($maintenanceDispatch->requiresCsrfCheck) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);
        }

        // +-------------------------------------------------------------------+
        // | Commons parameters                                                    |
        // +-------------------------------------------------------------------+

        $maintActions = [
            'derivatives' => [
                'icon' => 'icon-trash-1',
                'label' => Lang::t('Delete multiple size images'),
            ],
            'lock_gallery' => [
                'icon' => 'icon-lock',
                'label' => Lang::t('Lock gallery'),
            ],
            'unlock_gallery' => [
                'icon' => 'icon-lock',
                'label' => Lang::t('Unlock gallery'),
            ],
            'categories' => [
                'icon' => 'icon-folder-open',
                'label' => Lang::t('Update albums informations'),
            ],
            'images' => [
                'icon' => 'icon-info-circled-1',
                'label' => Lang::t('Update photos information'),
            ],
            'empty_lounge' => [
                'icon' => 'icon-thumbs-up',
                'label' => Lang::t('Empty lounge'),
            ],
            'delete_orphan_tags' => [
                'icon' => 'icon-tags',
                'label' => Lang::t('Delete orphan tags'),
            ],
            'user_cache' => [
                'icon' => 'icon-user-1',
                'label' => Lang::t('Purge user cache'),
            ],
            'history_detail' => [
                'icon' => 'icon-back-in-time',
                'label' => Lang::t('Purge history detail'),
            ],
            'history_summary' => [
                'icon' => 'icon-back-in-time',
                'label' => Lang::t('Purge history summary'),
            ],
            'sessions' => [
                'icon' => 'icon-th-list',
                'label' => Lang::t('Purge sessions'),
            ],
            'feeds' => [
                'icon' => 'icon-bell',
                'label' => Lang::t('Purge never used notification feeds'),
            ],
            'database' => [
                'icon' => 'icon-database',
                'label' => Lang::t('Repair and optimize database'),
            ],
            'c13y' => [
                'icon' => 'icon-ok',
                'label' => Lang::t('Reinitialize check integrity'),
            ],
            'search' => [
                'icon' => 'icon-search',
                'label' => Lang::t('Purge search history'),
            ],
            'compiled-templates' => [
                'icon' => 'icon-file-code',
                'label' => Lang::t('Purge compiled templates'),
            ],
        ];

        // +-------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-------------------------------------------------------------------+

        $tab = $maintenanceDispatch->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('maintenance');
        $tabsheet->select($tab);
        $tabsheet->assign();

        if ($tab === 'env') {
            \Piwigo\Bootstrap\AdminAccessor::maintenanceEnvPageRenderer()
                ->render();
        } elseif ($tab === 'sys') {
            new MaintenanceSysPageRenderer()
                ->render($maintActions);
        } else {
            \Piwigo\Bootstrap\AdminAccessor::maintenanceActionsPageRenderer()
                ->render($maintActions);
        }

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => Lang::t('Maintenance'),
            ]
        );
    }
}
