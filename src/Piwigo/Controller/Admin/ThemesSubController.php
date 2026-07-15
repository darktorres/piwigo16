<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\tabsheet;
use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Admin\ThemesNewPageRenderer;
use Piwigo\Admin\ThemesStandardPagesPageRenderer;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/themes.php's own tab-dispatch shell (page slug "themes"),
 * folded directly into this controller (P23 sub-batch 6i-2) -- same shape
 * as `LanguagesSubController`. Its own tab dispatch is already validated
 * (`/^(installed|update|new|standard_pages)$/`). `$my_base_url` (the
 * shell's own local var) was genuinely dead code (assigned, never read
 * anywhere -- confirmed via grep) and is dropped here rather than carried
 * forward.
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
 * `include`-ing the other. The "update" tab keeps its raw
 * `include admin/updates_ext.php` unchanged -- that file is still shared
 * with plugins.php/languages.php/updates.php's own "update"/"ext" tabs,
 * porting it is 6i-4's scope.
 */
final class ThemesSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $page, $template;

        if (isset($_GET['tab'])) {
            check_input_parameter('tab', $_GET, false, '/^(installed|update|new|standard_pages)$/');
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
            include PHPWG_ROOT_PATH . 'admin/updates_ext.php';
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
