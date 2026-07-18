<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Db\DbConnection;
use Piwigo\Template\Template;

/**
 * Ported from admin/languages_new.php (the "new" tab of the "languages" page
 * slug, dispatched by LanguagesSubController) -- browse the PEM catalog and
 * install a new language.
 *
 * Already correctly protected before this port (confirmed by direct read):
 * its one real mutation (isset($_GET['revision']), install + auto-activate)
 * already gates on is_webmaster() and check_pwg_token() -- no CSRF fix
 * needed here, unlike LanguagesInstalledPageRenderer.
 */
final class LanguagesNewPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed>
         */
        global $conf;
        /**
         * @var array<string, mixed>
         */
        global $page;
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Config\Config::enableExtensionsInstall()) {
            die('Piwigo extensions install/update system is disabled');
        }

        $template->set_filenames([
            'languages' => 'languages_new.tpl',
        ]);

        // $page['page']/$page['tab'] are set as plain strings by admin.php's
        // routing before this renderer runs; $page itself is only known as
        // array<string, mixed>, so narrow the two offsets used here.
        $page_page = $page['page'] ?? null;
        $page_page = is_scalar($page_page) ? (string) $page_page : '';
        $page_tab = $page['tab'] ?? null;
        $page_tab = is_scalar($page_tab) ? (string) $page_tab : '';
        $base_url = get_root_url() . 'admin.php?page=' . $page_page . '&tab=' . $page_tab;

        $extension_repository = new ExtensionRepository(DbConnection::build());
        $pem_catalog = new PemCatalog(new ZipExtractor());
        $extension_scanner = new ExtensionScanner();
        $extension_lifecycle = new ExtensionLifecycle($extension_repository, $pem_catalog);

        // +-----------------------------------------------------------------------+
        // |                           setup check                                 |
        // +-----------------------------------------------------------------------+

        $languages_dir = PHPWG_ROOT_PATH . 'language';
        if (! is_writable($languages_dir)) {
            \Piwigo\Core\PageState::current()->addError(l10n('Add write access to the "%s" directory', 'language'));
        }

        // +-----------------------------------------------------------------------+
        // |                       perform installation                            |
        // +-----------------------------------------------------------------------+

        if (isset($_GET['revision'])) {
            if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
                \Piwigo\Core\PageState::current()->addError(l10n('Webmaster status is required.'));
            } else {
                new \Piwigo\Csrf\CsrfService()
                    ->checkOrFail(new \Piwigo\Html\HtmlService());

                // $_GET values are always string|array; 'revision' is only ever
                // built from $language['revision_id'] in this file's own template
                // link below, so it's a plain numeric string in the normal case.
                $revision = $_GET['revision'];
                $revision = is_string($revision) ? $revision : '';

                $extraction = $pem_catalog->extractArchive(ExtensionType::Language, 'install', $revision, '');
                $install_status = $extraction['status'];

                // extract_language_files() legacy quirk: a successful install
                // auto-activates the new language immediately (languages have no
                // separate "installed but inactive" state) -- reproduced here since
                // PemCatalog::extractArchive() only extracts, it doesn't know about
                // the lifecycle state machine.
                if ($install_status === 'ok' && $extraction['id'] !== null) {
                    $fs_language_entry = $extension_scanner->scan(ExtensionType::Language)[$extraction['id']] ?? null;
                    $extension_lifecycle->performAction(ExtensionType::Language, 'activate', $extraction['id'], $fs_language_entry);
                }

                redirect($base_url . '&installstatus=' . $install_status);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                        installation result                            |
        // +-----------------------------------------------------------------------+
        if (isset($_GET['installstatus'])) {
            $installstatus = $_GET['installstatus'];
            $installstatus = is_string($installstatus) ? $installstatus : '';

            match ($installstatus) {
                'ok' => \Piwigo\Core\PageState::current()->addInfo(l10n('Language has been successfully installed')),
                'temp_path_error' => \Piwigo\Core\PageState::current()->addError(l10n('Can\'t create temporary file.')),
                'dl_archive_error' => \Piwigo\Core\PageState::current()->addError(l10n('Can\'t download archive.')),
                'archive_error' => \Piwigo\Core\PageState::current()->addError(l10n('Can\'t read or extract archive.')),
                default => \Piwigo\Core\PageState::current()->addError(l10n('An error occured during extraction (%s).', htmlspecialchars($installstatus))),
            };
        }

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+
        $fs_language_ids = [];
        foreach ($extension_scanner->scan(ExtensionType::Language) as $fs_language) {
            $extension = $fs_language['extension'] ?? null;
            if (is_scalar($extension)) {
                $fs_language_ids[] = (string) $extension;
            }
        }
        $server_languages = $pem_catalog->getServerExtensions(ExtensionType::Language, $fs_language_ids, true);

        if ($server_languages !== null) {
            // PEM_URL is defined via define('PEM_URL', \Piwigo\Config\Config::alternativePemUrl())
            // in one branch of include/common.inc.php, so PHPStan can't prove it's
            // a string across that file boundary -- narrow it once here (same
            // pattern as languages.class.php::get_server_languages()).
            $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

            foreach ($server_languages as $language) {
                // $language comes from an untyped unserialize() of a remote PEM
                // payload (see languages::get_server_languages()); only cast
                // scalars actually safe to stringify, treat anything else as empty
                // (same pattern as languages.class.php::extension_name_compare()).
                $revision_date = $language['revision_date'] ?? null;
                $revision_date = is_scalar($revision_date) ? (string) $revision_date : '';

                $revision_id = $language['revision_id'] ?? null;
                $revision_id = is_scalar($revision_id) ? (string) $revision_id : '';

                $extension_id = $language['extension_id'] ?? null;
                $extension_id = is_scalar($extension_id) ? (string) $extension_id : '';

                $download_url = $language['download_url'] ?? null;
                $download_url = is_scalar($download_url) ? (string) $download_url : '';

                [$date] = explode(' ', $revision_date);

                $url_auto_install = htmlentities($base_url)
                  . '&amp;revision=' . $revision_id
                  . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken()
                ;

                $template->append('languages', [
                    'EXT_NAME' => $language['extension_name'],
                    'EXT_DESC' => $language['extension_description'],
                    'EXT_URL' => $pem_base_url . '/extension_view.php?eid=' . $extension_id,
                    'VERSION' => $language['revision_name'],
                    'VER_DESC' => $language['revision_description'],
                    'DATE' => $date,
                    'AUTHOR' => $language['author_name'],
                    'URL_INSTALL' => $url_auto_install,
                    'URL_DOWNLOAD' => $download_url . '&amp;origin=piwigo_download',
                ]);
            }
        } else {
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t connect to server.'));
        }
        $template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
    }
}
