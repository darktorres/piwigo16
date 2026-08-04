<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

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
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/themes.php's own tab-dispatch shell (page slug "themes"),
 * folded directly into this controller (P23 sub-batch 6i-2) -- same shape
 * as `LanguagesSubController`. Its own tab dispatch is already validated
 * (`/^(installed|update|new|standard_pages)$/`).
 *
 * Correction (found during 6i-4): `$my_base_url` is NOT dead code, despite
 * this docblock originally claiming so. It's consumed indirectly by
 * `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'themes':` branch
 * (formerly `admin/include/add_core_tabs.inc.php`'s `add_core_tabs()`,
 * folded in P23 batch 8b-6) -- dropping it silently degraded every tab
 * href (missing the `admin.php?page=themes` prefix entirely). Restored
 * here via `CoreTabs::setContext(new CoreTabsContext(myBaseUrl: ...))`
 * below; `CoreTabs::addCoreTabs()`'s `'themes'` case now reads it via
 * `self::contextField(self::context()->myBaseUrl, 'myBaseUrl')`, not the
 * `global $my_base_url;` read this paragraph originally described (that
 * mechanism was retired by P24 phase 8g's CoreTabsContext migration).
 *
 * The "installed"/"new" tab bodies were migrated off the themes.class.php
 * god-class (already replaced by PemCatalog/ExtensionScanner/
 * ExtensionLifecycle/ExtensionRepository in a prior P21-era pass) onto
 * Piwigo\Admin\ThemesInstalledPageRenderer/ThemesNewPageRenderer -- see
 * ThemesInstalledPageRenderer's own docblock for a real CSRF gap found and
 * fixed there. The "standard_pages" tab now calls the shared
 * Piwigo\Admin\ThemesStandardPagesPageRenderer, the same class the
 * standalone "themes_standard_pages" page slug's own
 * ThemesStandardPagesSubController calls -- both routes reached the same
 * file before this port too, now they share one real class instead of one
 * `include`-ing the other. The "update" tab now calls the shared
 * Piwigo\Admin\UpdatesExtPageRenderer (P23 sub-batch 6i-4) instead of its
 * own raw `include admin/updates_ext.php` -- the same class
 * `LanguagesSubController`/`PluginsSubController`/`UpdatesSubController`'s
 * own "ext" tab call. This controller's own `ADMIN_PAGE_TITLE` override
 * still applies after the renderer call, exactly as it did after the raw
 * include before this port.
 */
final class ThemesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly ExtensionUpdateChecker $extensionUpdateChecker,
        private readonly ThemesNewPageRenderer $themesNewPageRenderer,
        private readonly ThemesStandardPagesPageRenderer $themesStandardPagesPageRenderer,
        private readonly ThemesInstalledPageRenderer $themesInstalledPageRenderer,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'themes' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=themes'));

        $tab = Request\ExtensionTabRequest::fromGlobals('/^(installed|update|new|standard_pages)$/')->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('themes');
        $tabsheet->select($tab);
        $tabsheet->assign($this->currentTemplate);

        if ($tab === 'update') {
            new UpdatesExtPageRenderer()
                ->render($this->lang, $this->accessControl, 'themes', $this->urlService, $this->configService, $this->pageState, $this->currentTemplate, $this->extensionUpdateChecker, $this->htmlRenderer);
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
