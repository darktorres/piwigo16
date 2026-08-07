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
use Piwigo\Controller\Admin\Request\ExtensionTabRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
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
final class ThemesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly CoreTabs $coreTabs,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ExtensionUpdateChecker $extensionUpdateChecker,
        private readonly ThemesNewPageRenderer $themesNewPageRenderer,
        private readonly ThemesStandardPagesPageRenderer $themesStandardPagesPageRenderer,
        private readonly ThemesInstalledPageRenderer $themesInstalledPageRenderer,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'themes' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=themes'));

        $tab = ExtensionTabRequest::fromGlobals('/^(installed|update|new|standard_pages)$/', $this->inputValidator)->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('themes');
        $tabsheet->select($tab, $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        if ($tab === 'update') {
            new UpdatesExtPageRenderer()
                ->render($this->lang, $this->accessControl, 'themes', $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->extensionUpdateChecker, $this->htmlRenderer, $this->currentConfig);
            $template->assign('ADMIN_PAGE_TITLE', $this->lang->t('Themes'));
        } elseif ($tab === 'new') {
            $this->themesNewPageRenderer
                ->render('themes', $tab);
        } elseif ($tab === 'standard_pages') {
            $this->themesStandardPagesPageRenderer
                ->render();
        } else {
            $this->themesInstalledPageRenderer
                ->render('themes');
        }
    }
}
