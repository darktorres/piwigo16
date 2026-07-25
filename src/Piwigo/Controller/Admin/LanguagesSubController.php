<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\LanguagesInstalledPageRenderer;
use Piwigo\Admin\LanguagesNewPageRenderer;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\UpdatesExtPageRenderer;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/languages.php's own tab-dispatch shell (page slug
 * "languages"), folded directly into this controller -- same shape as
 * every prior P23 batch 6 sub-batch's shell folding. Its own tab dispatch
 * is already validated (`/^(installed|update|new)$/`).
 *
 * Correction (found during 6i-4): `$my_base_url` is NOT dead code, despite
 * this docblock originally claiming so. It's consumed indirectly by
 * `Piwigo\Admin\CoreTabs::addCoreTabs()`'s own `case 'languages':` branch
 * (formerly `admin/include/add_core_tabs.inc.php`'s `add_core_tabs()`,
 * folded in P23 batch 8b-6), read via `global $my_base_url;` when
 * `Tabsheet::select()` fires its `tabsheet_before_select` event a few
 * lines below -- dropping it silently degraded every tab href (missing
 * the `admin.php?page=languages` prefix entirely). Restored here.
 *
 * The "installed"/"new" tab bodies were migrated off the languages.class.php
 * god-class (already replaced by PemCatalog/ExtensionScanner/
 * ExtensionLifecycle/ExtensionRepository in a prior P21-era pass) onto
 * Piwigo\Admin\LanguagesInstalledPageRenderer/LanguagesNewPageRenderer (P23
 * sub-batch 6i-1, this batch's real scope) -- see
 * LanguagesInstalledPageRenderer's own docblock for a real CSRF gap found
 * and fixed there. The "update" tab now calls the shared
 * Piwigo\Admin\UpdatesExtPageRenderer (P23 sub-batch 6i-4) instead of its
 * own raw `include admin/updates_ext.php` -- the same class
 * `ThemesSubController`/`PluginsSubController`/`UpdatesSubController`'s own
 * "ext" tab call, matching the `ThemesStandardPagesPageRenderer` "one
 * class, multiple call sites" precedent from 6i-2. This controller's own
 * `ADMIN_PAGE_TITLE` override still applies after the renderer call,
 * exactly as it did after the raw include before this port.
 */
final class LanguagesSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'languages' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page=languages'));

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
        $tabsheet->set_id('languages');
        $tabsheet->select($tab);
        $tabsheet->assign();

        if ($tab === 'update') {
            new UpdatesExtPageRenderer()
                ->render('languages', $this->urlService, $this->configService);
            $template->assign('ADMIN_PAGE_TITLE', Lang::t('Languages'));
        } elseif ($tab === 'new') {
            \Piwigo\Bootstrap\AdminAccessor::languagesNewPageRenderer()
                ->render('languages', $tab);
        } else {
            \Piwigo\Bootstrap\AdminAccessor::languagesInstalledPageRenderer()
                ->render('languages');
        }
    }
}
