<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\PluginsInstalledPageRenderer;
use Piwigo\Admin\PluginsNewPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Request\ExtensionTabRequest;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs the "plugins" admin page's tab dispatch (the tab is restricted to
 * `/^(installed|update|new)$/`).
 *
 * `CoreTabs::setContext(...)` must run before `Tabsheet::select()` below --
 * `CoreTabs::addCoreTabs()`'s own `case 'plugins':` branch reads that
 * context value synchronously during `select()`'s `tabsheet_before_select`
 * event; without it, every tab href is missing its `admin.php?page=plugins`
 * prefix.
 *
 * The "installed" and "new" tabs are rendered by
 * Piwigo\Admin\PluginsInstalledPageRenderer and
 * Piwigo\Admin\PluginsNewPageRenderer; the "update" tab uses the shared
 * Piwigo\Admin\UpdatesExtPageRenderer, after which this controller's own
 * `ADMIN_PAGE_TITLE` override still applies.
 */
final class PluginsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly CurrentLogger $currentLogger,
        private readonly CoreTabs $coreTabs,
        private readonly SessionService $sessionService,
        private readonly EventDispatcher $eventDispatcher,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ExtensionUpdateChecker $extensionUpdateChecker,
        private readonly PluginsNewPageRenderer $pluginsNewPageRenderer,
        private readonly PreferencesService $preferencesService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly CurrentUser $currentUser,
        private readonly Paths $paths,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'plugins' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=plugins'));

        $tab = ExtensionTabRequest::fromGlobals('/^(installed|update|new)$/', $this->inputValidator)->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('plugins');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        if ($tab === 'update') {
            new UpdatesExtPageRenderer()
                ->render($this->lang, $this->accessControl, 'plugins', $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->extensionUpdateChecker, $this->htmlRenderer, $this->currentConfig);
            $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Plugins'));
        } elseif ($tab === 'new') {
            $this->pluginsNewPageRenderer
                ->render('plugins', $tab);
        } else {
            new PluginsInstalledPageRenderer()
                ->render($this->lang, $this->accessControl, 'plugins', $this->urlService, $this->currentLogger, $this->sessionService, $this->eventDispatcher, $this->currentTemplate, $this->preferencesService, $this->htmlRenderer, $this->currentConfig, $this->currentUser, $this->paths);
        }
    }
}
