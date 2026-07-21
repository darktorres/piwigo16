<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\PluginsInstalledPageRenderer;
use Piwigo\Admin\PluginsNewPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
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
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'plugins' case via
        // `global $my_base_url;`, triggered synchronously inside
        // Tabsheet::select() below -- must be set before that call, not
        // dead code (see this class's own docblock).
        global $my_base_url;
        $my_base_url = $this->urlService->getRootUrl() . 'admin.php?page=plugins';

        if (isset($_GET['tab'])) {
            new \Piwigo\Validation\InputValidator()
                ->validate('tab', $_GET, false, '/^(installed|update|new)$/');
            // check_input_parameter() validates the raw value against the pattern
            // above (fatal_error()-ing on anything else) but does not narrow its
            // type for static analysis -- $_GET values are string|array<mixed> at
            // best, so re-check it is a string before trusting it as the tab name.
            $tab_raw = $_GET['tab'];
            $tab = is_string($tab_raw) ? $tab_raw : 'installed';
        } else {
            $tab = 'installed';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('plugins');
        $tabsheet->select($tab);
        $tabsheet->assign();

        if ($tab === 'update') {
            new UpdatesExtPageRenderer()
                ->render('plugins', $this->urlService, $this->configService);
            $template->assign('ADMIN_PAGE_TITLE', Lang::t('Plugins'));
        } elseif ($tab === 'new') {
            new PluginsNewPageRenderer($this->redirectService, $this->urlService)
                ->render('plugins', $tab);
        } else {
            new PluginsInstalledPageRenderer()
                ->render('plugins', $this->urlService);
        }
    }
}
