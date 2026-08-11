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
use Piwigo\Controller\Admin\Projection\MaintenanceSubControllerPageContext;
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
 * "maintenance"). Its own tab dispatch is validated against
 * `/^(actions|env|sys)$/`. admin.php already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so this
 * controller does not repeat that check. Every $_GET['action'] mutation is
 * still gated by its own CSRF token check.
 *
 * `CoreTabsContext`'s `myBaseUrl` must be set before `Tabsheet::select()`
 * is called: `CoreTabs::addCoreTabs()`'s `'maintenance'` case reads it
 * during the `tabsheet_before_select` event that call fires, and without it
 * every tab href loses its `admin.php?page=` prefix.
 *
 * The "actions"/"env"/"sys" tab bodies render via
 * Piwigo\Admin\MaintenanceActionsPageRenderer/MaintenanceEnvPageRenderer/
 * MaintenanceSysPageRenderer.
 */
final readonly class MaintenanceSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private MaintenanceEnvPageRenderer $maintenanceEnvPageRenderer,
        private MaintenanceActionsPageRenderer $maintenanceActionsPageRenderer,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private EventDispatcher $eventDispatcher,
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
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);
        }

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

        $tab = $maintenanceDispatch->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->setId('maintenance');
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

        $template->assignContext(new MaintenanceSubControllerPageContext(adminPageTitle: $this->lang->t('Maintenance')));
    }
}
