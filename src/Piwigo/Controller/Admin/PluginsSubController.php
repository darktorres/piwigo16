<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\PluginsInstalledPageRenderer;
use Piwigo\Admin\PluginsNewPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugins.php's own tab-dispatch shell (page slug
 * "plugins"), folded directly into this controller (P23 sub-batch 6i-3) --
 * same shape as `LanguagesSubController`/`ThemesSubController`. Its own tab
 * dispatch is already validated (`/^(installed|update|new)$/`).
 *
 * Correction (found during 6i-4): `$my_base_url` is NOT dead code, despite
 * this docblock originally claiming so. It's consumed indirectly by
 * `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'plugins':` branch
 * (formerly `admin/include/add_core_tabs.inc.php`'s `add_core_tabs()`,
 * folded in P23 batch 8b-6), read via `global $my_base_url;` when
 * `Tabsheet::select()` fires its `tabsheet_before_select` event a few
 * lines below -- dropping it silently degraded every tab href (missing
 * the `admin.php?page=plugins` prefix entirely). Restored here.
 *
 * The "installed"/"new" tab bodies were migrated off the plugins.class.php
 * god-class (already replaced by PemCatalog/ExtensionScanner/
 * ExtensionLifecycle/ExtensionRepository in a prior P21-era pass) onto
 * Piwigo\Admin\PluginsInstalledPageRenderer/PluginsNewPageRenderer -- no
 * CSRF gap found in this cluster (real mutations already go through
 * token-protected ws.php?method=pwg.plugins.performAction), see
 * PluginsInstalledPageRenderer's own docblock for a confirmed-dead
 * template link removed instead. The "update" tab now calls the shared
 * Piwigo\Admin\UpdatesExtPageRenderer (P23 sub-batch 6i-4) instead of its
 * own raw `include admin/updates_ext.php` -- the same class
 * `LanguagesSubController`/`ThemesSubController`/`UpdatesSubController`'s
 * own "ext" tab call. This controller's own `ADMIN_PAGE_TITLE` override
 * still applies after the renderer call, exactly as it did after the raw
 * include before this port.
 */
final class PluginsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly CoreTabs $coreTabs,
        private readonly SessionService $sessionService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'plugins' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=plugins'));

        $tab = Request\ExtensionTabRequest::fromGlobals('/^(installed|update|new)$/')->tab;

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('plugins');
        $tabsheet->select($tab);
        $tabsheet->assign();

        if ($tab === 'update') {
            new UpdatesExtPageRenderer()
                ->render('plugins', $this->urlService, $this->configService);
            $template->assign('ADMIN_PAGE_TITLE', Lang::t('Plugins'));
        } elseif ($tab === 'new') {
            \Piwigo\Bootstrap\AdminAccessor::pluginsNewPageRenderer()
                ->render('plugins', $tab);
        } else {
            new PluginsInstalledPageRenderer()
                ->render('plugins', $this->urlService, $this->currentLogger, $this->sessionService);
        }
    }
}
