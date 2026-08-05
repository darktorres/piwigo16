<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\MaintenanceActionsPageRenderer;
use Piwigo\Admin\MaintenanceEnvPageRenderer;
use Piwigo\Admin\MaintenanceSysPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Request\MaintenanceDispatchRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
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
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly MaintenanceEnvPageRenderer $maintenanceEnvPageRenderer,
        private readonly MaintenanceActionsPageRenderer $maintenanceActionsPageRenderer,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'maintenance' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $maintenanceDispatch = MaintenanceDispatchRequest::fromGlobals($this->inputValidator);

        if ($maintenanceDispatch->requiresCsrfCheck) {
            new CsrfService()
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

        // +-------------------------------------------------------------------+
        // | Commons parameters                                                    |
        // +-------------------------------------------------------------------+

        $maintActions = [
            'derivatives' => [
                'icon' => 'icon-trash-1',
                'label' => $this->lang->t('Delete multiple size images'),
            ],
            'lock_gallery' => [
                'icon' => 'icon-lock',
                'label' => $this->lang->t('Lock gallery'),
            ],
            'unlock_gallery' => [
                'icon' => 'icon-lock',
                'label' => $this->lang->t('Unlock gallery'),
            ],
            'categories' => [
                'icon' => 'icon-folder-open',
                'label' => $this->lang->t('Update albums informations'),
            ],
            'images' => [
                'icon' => 'icon-info-circled-1',
                'label' => $this->lang->t('Update photos information'),
            ],
            'empty_lounge' => [
                'icon' => 'icon-thumbs-up',
                'label' => $this->lang->t('Empty lounge'),
            ],
            'delete_orphan_tags' => [
                'icon' => 'icon-tags',
                'label' => $this->lang->t('Delete orphan tags'),
            ],
            'user_cache' => [
                'icon' => 'icon-user-1',
                'label' => $this->lang->t('Purge user cache'),
            ],
            'history_detail' => [
                'icon' => 'icon-back-in-time',
                'label' => $this->lang->t('Purge history detail'),
            ],
            'history_summary' => [
                'icon' => 'icon-back-in-time',
                'label' => $this->lang->t('Purge history summary'),
            ],
            'sessions' => [
                'icon' => 'icon-th-list',
                'label' => $this->lang->t('Purge sessions'),
            ],
            'feeds' => [
                'icon' => 'icon-bell',
                'label' => $this->lang->t('Purge never used notification feeds'),
            ],
            'database' => [
                'icon' => 'icon-database',
                'label' => $this->lang->t('Repair and optimize database'),
            ],
            'c13y' => [
                'icon' => 'icon-ok',
                'label' => $this->lang->t('Reinitialize check integrity'),
            ],
            'search' => [
                'icon' => 'icon-search',
                'label' => $this->lang->t('Purge search history'),
            ],
            'compiled-templates' => [
                'icon' => 'icon-file-code',
                'label' => $this->lang->t('Purge compiled templates'),
            ],
        ];

        // +-------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-------------------------------------------------------------------+

        $tab = $maintenanceDispatch->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('maintenance');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        if ($tab === 'env') {
            $this->maintenanceEnvPageRenderer
                ->render();
        } elseif ($tab === 'sys') {
            new MaintenanceSysPageRenderer()
                ->render($this->lang, $this->accessControl, $maintActions, $this->pageState, $this->currentTemplate, $this->currentConfig);
        } else {
            $this->maintenanceActionsPageRenderer
                ->render($maintActions);
        }

        $template->assign(
            [
                'ADMIN_PAGE_TITLE' => $this->lang->t('Maintenance'),
            ]
        );
    }
}
