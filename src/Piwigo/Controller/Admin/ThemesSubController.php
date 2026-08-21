<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Admin\ThemesNewPageRenderer;
use Piwigo\Admin\ThemesStandardPagesPageRenderer;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Controller\Admin\Request\ExtensionTabRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/themes.php's own tab-dispatch shell (page slug "themes").
 * Its own tab dispatch is validated against
 * `/^(installed|update|new|standard_pages)$/`.
 *
 * `CoreTabsContext`'s `myBaseUrl` must be set (via `CoreTabs::setContext()`
 * below) before `Tabsheet::select()` triggers `CoreTabs::addCoreTabs()`'s
 * `'themes'` case, which reads it via
 * `self::contextField(self::context()->myBaseUrl, 'myBaseUrl')`; without it
 * every tab href loses its `admin.php?page=themes` prefix.
 *
 * The "installed" and "new" tabs render via `ThemesInstalledPageRenderer`/
 * `ThemesNewPageRenderer`. The "standard_pages" tab renders via the shared
 * `ThemesStandardPagesPageRenderer` (also used by the standalone
 * `ThemesStandardPagesSubController`). The "update" tab renders via the
 * shared `UpdatesExtPageRenderer` (also used by `LanguagesSubController`,
 * `PluginsSubController`, and `UpdatesSubController` for their own "ext"
 * tab); this controller's `ADMIN_PAGE_TITLE` assignment still overrides
 * after that call.
 */
final readonly class ThemesSubController implements AdminSubControllerInterface
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
        private ThemesNewPageRenderer $themesNewPageRenderer,
        private ThemesStandardPagesPageRenderer $themesStandardPagesPageRenderer,
        private ThemesInstalledPageRenderer $themesInstalledPageRenderer,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private EventDispatcher $eventDispatcher,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        // Consumed by CoreTabs::addCoreTabs()'s own 'themes' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=themes'));

        $tab = ExtensionTabRequest::fromGlobals('/^(installed|update|new|standard_pages)$/', $this->inputValidator)->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->setId('themes');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate, $this->renderer);

        if ($tab === 'update') {
            $result = new UpdatesExtPageRenderer()
                ->render($this->lang, $this->accessControl, 'themes', $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->extensionUpdateChecker, $this->htmlRenderer, $this->currentConfig, $this->csrfService, $this->renderer);

            // This controller's own ADMIN_PAGE_TITLE override always wins
            // over UpdatesExtPageRenderer's own -- matches this class's
            // own docblock.
            return new AdminPageResult(
                content: $result->content,
                pageTitle: $this->lang->t('Themes'),
                helpUrl: $result->helpUrl,
            );
        }
        if ($tab === 'new') {
            return $this->themesNewPageRenderer
                ->render('themes', $tab);
        }
        if ($tab === 'standard_pages') {
            return $this->themesStandardPagesPageRenderer
                ->render();
        }

        return $this->themesInstalledPageRenderer
            ->render('themes');
    }
}
