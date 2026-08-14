<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\LanguagesInstalledPageRenderer;
use Piwigo\Admin\LanguagesNewPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\LanguagesSubControllerPageContext;
use Piwigo\Controller\Admin\Request\ExtensionTabRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Page slug "languages" -- a flat tab-dispatch shell. Tab dispatch is
 * validated (`/^(installed|update|new)$/`).
 *
 * `$my_base_url` (set via CoreTabs::setContext() below) is consumed by
 * `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'languages':`
 * branch, read via `global $my_base_url;` when `Tabsheet::select()`
 * fires its `tabsheet_before_select` event. Omitting it degrades every
 * tab href by dropping the `admin.php?page=languages` prefix.
 *
 * The "installed"/"new" tabs render via
 * Piwigo\Admin\LanguagesInstalledPageRenderer/LanguagesNewPageRenderer.
 * The "update" tab calls the shared Piwigo\Admin\UpdatesExtPageRenderer,
 * the same renderer ThemesSubController/PluginsSubController/
 * UpdatesSubController's own "ext" tab call uses. This controller's own
 * `ADMIN_PAGE_TITLE` override still applies after that renderer call.
 */
final readonly class LanguagesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private CoreTabs $coreTabs,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private ExtensionUpdateChecker $extensionUpdateChecker,
        private LanguagesNewPageRenderer $languagesNewPageRenderer,
        private LanguagesInstalledPageRenderer $languagesInstalledPageRenderer,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'languages' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=languages'));

        $tab = ExtensionTabRequest::fromGlobals('/^(installed|update|new)$/', $this->inputValidator)->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->setId('languages');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        if ($tab === 'update') {
            new UpdatesExtPageRenderer()
                ->render($this->lang, $this->accessControl, 'languages', $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->extensionUpdateChecker, $this->htmlRenderer, $this->currentConfig, $this->csrfService);
            $template->assignContext(new LanguagesSubControllerPageContext(adminPageTitle: $this->lang->t('Languages')));
        } elseif ($tab === 'new') {
            $this->languagesNewPageRenderer
                ->render('languages', $tab);
        } else {
            $this->languagesInstalledPageRenderer
                ->render('languages');
        }
    }
}
