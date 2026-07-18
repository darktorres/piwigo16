<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\tabsheet;
use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Admin\ThemesNewPageRenderer;
use Piwigo\Admin\ThemesStandardPagesPageRenderer;
use Piwigo\Admin\UpdatesExtPageRenderer;
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
 * folded in P23 batch 8b-6), read via `global $my_base_url;` when
 * `tabsheet::select()` fires its `tabsheet_before_select` event a few
 * lines below -- dropping it silently degraded every tab href (missing
 * the `admin.php?page=themes` prefix entirely). Restored here.
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
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        // Consumed by CoreTabs::addCoreTabs()'s own 'themes' case via
        // `global $my_base_url;`, triggered synchronously inside
        // tabsheet::select() below -- must be set before that call, not
        // dead code (see this class's own docblock).
        global $my_base_url;
        $my_base_url = get_root_url() . 'admin.php?page=themes';

        if (isset($_GET['tab'])) {
            new \Piwigo\Validation\InputValidator()
                ->validate('tab', $_GET, false, '/^(installed|update|new|standard_pages)$/');
            // check_input_parameter() validates the raw value against the pattern
            // above (fatal_error()-ing on anything else) but does not narrow its
            // type for static analysis -- $_GET values are string|array<mixed> at
            // best, so re-check it is a string before trusting it as the tab name.
            $tab = $_GET['tab'];
            $page['tab'] = is_string($tab) ? $tab : 'installed';
        } else {
            $page['tab'] = 'installed';
        }

        $tabsheet = new tabsheet();
        $tabsheet->set_id('themes');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        if ($page['tab'] === 'update') {
            new UpdatesExtPageRenderer()
                ->render();
            $template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
        } elseif ($page['tab'] === 'new') {
            new ThemesNewPageRenderer()
                ->render();
        } elseif ($page['tab'] === 'standard_pages') {
            new ThemesStandardPagesPageRenderer()
                ->render();
        } else {
            new ThemesInstalledPageRenderer()
                ->render();
        }
    }
}
