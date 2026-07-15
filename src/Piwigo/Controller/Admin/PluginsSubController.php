<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\PluginsInstalledPageRenderer;
use Piwigo\Admin\PluginsNewPageRenderer;
use Piwigo\Admin\tabsheet;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/plugins.php's own tab-dispatch shell (page slug
 * "plugins"), folded directly into this controller (P23 sub-batch 6i-3) --
 * same shape as `LanguagesSubController`/`ThemesSubController`. Its own tab
 * dispatch is already validated (`/^(installed|update|new)$/`).
 * `$my_base_url` (the shell's own local var) was genuinely dead code
 * (assigned, never read anywhere -- confirmed via grep) and is dropped
 * here rather than carried forward.
 *
 * The "installed"/"new" tab bodies were migrated off the plugins.class.php
 * god-class (already replaced by PemCatalog/ExtensionScanner/
 * ExtensionLifecycle/ExtensionRepository in a prior P21-era pass) onto
 * Piwigo\Admin\PluginsInstalledPageRenderer/PluginsNewPageRenderer -- no
 * CSRF gap found in this cluster (real mutations already go through
 * token-protected ws.php?method=pwg.plugins.performAction), see
 * PluginsInstalledPageRenderer's own docblock for a confirmed-dead
 * template link removed instead. The "update" tab keeps its raw
 * `include admin/updates_ext.php` unchanged -- that file is still shared
 * with themes.php/languages.php/updates.php's own "update"/"ext" tabs,
 * porting it is 6i-4's scope.
 */
final class PluginsSubController implements AdminSubControllerInterface
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
        $tabsheet->set_id('plugins');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        if ($page['tab'] === 'update') {
            include PHPWG_ROOT_PATH . 'admin/updates_ext.php';
            $template->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
        } elseif ($page['tab'] === 'new') {
            new PluginsNewPageRenderer()
                ->render();
        } else {
            new PluginsInstalledPageRenderer()
                ->render();
        }
    }
}
