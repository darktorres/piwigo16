<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Core\ActivitySystem;
use Piwigo\Template\Template;

/**
 * Ported from admin/themes_new.php (the "new" tab of the "themes" page slug,
 * dispatched by ThemesSubController) -- browse the PEM catalog and install a
 * new theme.
 *
 * Already correctly protected before this port (confirmed by direct read):
 * its one real mutation (isset($_GET['revision']) and isset($_GET['extension']),
 * install) already gates on is_webmaster() and check_pwg_token() -- no CSRF
 * fix needed here, unlike ThemesInstalledPageRenderer.
 */
final class ThemesNewPageRenderer
{
    /**
     * Legacy Coupling Retirement Track A: $pageSlug (batch A5.2f) and
     * $tab (batch A5.2h) are explicit params instead of
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (ThemesSubController) already knows both values statically/
     * locally (it's the only class registered for the 'themes' slug in
     * config/admin_pages.php, and it already computes its own $tab local
     * before dispatching here).
     */
    public function render(string $pageSlug, string $tab): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Config\Config::enableExtensionsInstall()) {
            new \Piwigo\Html\HtmlService()
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $base_url = get_root_url() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $pem_catalog = new PemCatalog(new ZipExtractor());
        $extension_scanner = new ExtensionScanner();

        // +-----------------------------------------------------------------------+
        // |                           setup check                                 |
        // +-----------------------------------------------------------------------+

        $themes_dir = PHPWG_ROOT_PATH . 'themes';
        if (! is_writable($themes_dir)) {
            \Piwigo\Core\PageState::current()->addError(l10n('Add write access to the "%s" directory', 'themes'));
        }

        // +-----------------------------------------------------------------------+
        // |                       perform installation                            |
        // +-----------------------------------------------------------------------+

        if (isset($_GET['revision']) and isset($_GET['extension'])
            and is_string($_GET['revision']) and is_string($_GET['extension'])
        ) {
            if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
                \Piwigo\Core\PageState::current()->addError(l10n('Webmaster status is required.'));
            } else {
                new \Piwigo\Csrf\CsrfService()
                    ->checkOrFail(new \Piwigo\Html\HtmlService());

                $extraction = $pem_catalog->extractArchive(ExtensionType::Theme, 'install', $_GET['revision'], $_GET['extension']);
                $install_status = $extraction['status'];
                $theme_id = $extraction['id'];

                redirect($base_url . '&installstatus=' . $install_status . '&theme_id=' . $theme_id);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                        installation result                            |
        // +-----------------------------------------------------------------------+

        if (isset($_GET['installstatus'])) {
            switch ($_GET['installstatus']) {
                case 'ok':
                    \Piwigo\Core\PageState::current()->addInfo(l10n('Theme has been successfully installed'));

                    $installed_theme_id = $_GET['theme_id'] ?? null;
                    $installed_fs_theme = is_string($installed_theme_id) ? ($extension_scanner->scan(ExtensionType::Theme)[$installed_theme_id] ?? null) : null;
                    if ($installed_fs_theme !== null) {
                        new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build()))->record('system', ActivitySystem::Theme, 'install', [
                            'theme_id' => $installed_theme_id,
                            'version' => $installed_fs_theme['version'],
                        ]);
                    }
                    break;

                case 'temp_path_error':
                    \Piwigo\Core\PageState::current()->addError(l10n('Can\'t create temporary file.'));
                    break;

                case 'dl_archive_error':
                    \Piwigo\Core\PageState::current()->addError(l10n('Can\'t download archive.'));
                    break;

                case 'archive_error':
                    \Piwigo\Core\PageState::current()->addError(l10n('Can\'t read or extract archive.'));
                    break;

                default:
                    $installstatus_raw = $_GET['installstatus'];
                    $installstatus_str = is_scalar($installstatus_raw) ? (string) $installstatus_raw : '';
                    \Piwigo\Core\PageState::current()->addError(
                        l10n(
                            'An error occured during extraction (%s).',
                            htmlspecialchars($installstatus_str)
                        )
                    );
            }
        }

        // +-----------------------------------------------------------------------+
        // |                          template output                              |
        // +-----------------------------------------------------------------------+

        $template->set_filenames([
            'themes' => 'themes_new.tpl',
        ]);

        $fs_theme_ids = [];
        foreach ($extension_scanner->scan(ExtensionType::Theme) as $fs_theme) {
            $extension = $fs_theme['extension'] ?? null;
            if (is_scalar($extension)) {
                $fs_theme_ids[] = (string) $extension;
            }
        }
        $server_themes = $pem_catalog->getServerExtensions(ExtensionType::Theme, $fs_theme_ids, true);

        if ($server_themes !== null) { // only new themes
            foreach ($server_themes as $theme) {
                // server_themes entries come from an untyped unserialize() of a
                // remote PEM payload (themes::get_server_themes()); narrow the
                // fields used in a typed context below rather than trusting the
                // external payload's shape.
                $revision_id_raw = $theme['revision_id'] ?? null;
                $revision_id = is_scalar($revision_id_raw) ? (string) $revision_id_raw : '';
                $extension_id_raw = $theme['extension_id'] ?? null;
                $extension_id = is_scalar($extension_id_raw) ? (string) $extension_id_raw : '';

                $url_auto_install = htmlentities($base_url)
                  . '&amp;revision=' . $revision_id
                  . '&amp;extension=' . $extension_id
                  . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken()
                ;

                $template->append(
                    'new_themes',
                    [
                        'name' => $theme['extension_name'],
                        'thumbnail' => (key_exists('thumbnail_src', $theme)) ? $theme['thumbnail_src'] : '',
                        'screenshot' => (key_exists('screenshot_url', $theme)) ? $theme['screenshot_url'] : '',
                        'install_url' => $url_auto_install,
                    ]
                );
            }
        } else {
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t connect to server.'));
        }

        $admin_theme_pref = new \Piwigo\Users\PreferencesService(new \Piwigo\Users\UserRepository(\Piwigo\Db\DbConnection::build()))->getParam('admin_theme', 'clear');
        $template->assign(
            'default_screenshot',
            get_root_url() . 'admin/themes/' . (is_string($admin_theme_pref) ? $admin_theme_pref : 'clear') . '/images/missing_screenshot.png'
        );
        $template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }
}
