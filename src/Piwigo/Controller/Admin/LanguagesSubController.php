<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\LanguagesInstalledPageRenderer;
use Piwigo\Admin\LanguagesNewPageRenderer;
use Piwigo\Admin\tabsheet;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/languages.php's own tab-dispatch shell (page slug
 * "languages"), folded directly into this controller -- same shape as
 * every prior P23 batch 6 sub-batch's shell folding. Its own tab dispatch
 * is already validated (`/^(installed|update|new)$/`). `$my_base_url` (the
 * shell's own local var) was genuinely dead code (assigned, never read
 * anywhere -- confirmed via grep) and is dropped here rather than carried
 * forward, matching the `$my_base_url` precedent from MaintenanceSubController.
 *
 * The "installed"/"new" tab bodies were migrated off the languages.class.php
 * god-class (already replaced by PemCatalog/ExtensionScanner/
 * ExtensionLifecycle/ExtensionRepository in a prior P21-era pass) onto
 * Piwigo\Admin\LanguagesInstalledPageRenderer/LanguagesNewPageRenderer (P23
 * sub-batch 6i-1, this batch's real scope) -- see
 * LanguagesInstalledPageRenderer's own docblock for a real CSRF gap found
 * and fixed there. The "update" tab keeps its raw
 * `include admin/updates_ext.php` unchanged -- that file is still shared
 * with plugins.php/themes.php/updates.php's own "update"/"ext" tabs,
 * porting it is 6i-4's scope.
 */
final class LanguagesSubController implements AdminSubControllerInterface
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
            check_input_parameter('tab', $_GET, false, '/^(installed|update|new)$/');
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
        $tabsheet->set_id('languages');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        if ($page['tab'] === 'update') {
            include PHPWG_ROOT_PATH . 'admin/updates_ext.php';
            $template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
        } elseif ($page['tab'] === 'new') {
            new LanguagesNewPageRenderer()
                ->render();
        } else {
            new LanguagesInstalledPageRenderer()
                ->render();
        }
    }
}
