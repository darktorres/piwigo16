<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use DateInterval;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;

/**
 * Ported from admin/plugins_new.php (the "new" tab of the "plugins" page
 * slug, dispatched by PluginsSubController) -- browse the PEM catalog and
 * install a new plugin.
 *
 * Already correctly protected before this port (confirmed by direct read):
 * its one real mutation (isset($_GET['revision']) and
 * isset($_GET['extension']), install) already gates on is_webmaster() and
 * check_pwg_token() -- no CSRF fix needed here, unlike
 * PluginsInstalledPageRenderer's own dead-code cleanup.
 */
final class PluginsNewPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $conf, $page, $template;

        if (! (bool) $conf['enable_extensions_install']) {
            die('Piwigo extensions install/update system is disabled');
        }

        $template->set_filenames([
            'plugins' => 'plugins_new.tpl',
        ]);

        // $page['page']/$page['tab'] are set as plain strings by admin.php's
        // routing before this renderer runs; $page itself is only known as
        // array<string, mixed>, so narrow the two offsets used here.
        $page_page = $page['page'] ?? null;
        $page_page = is_scalar($page_page) ? (string) $page_page : '';
        $page_tab = $page['tab'] ?? null;
        $page_tab = is_scalar($page_tab) ? (string) $page_tab : '';
        $base_url = get_root_url() . 'admin.php?page=' . $page_page . '&tab=' . $page_tab;

        $pem_catalog = new PemCatalog(new ZipExtractor());
        $extension_scanner = new ExtensionScanner();

        // ------------------------------------------------------automatic installation
        if (isset($_GET['revision']) and isset($_GET['extension'])
            and is_string($_GET['revision']) and is_string($_GET['extension'])
        ) {
            if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
                $this->pushPageMessage('errors', l10n('Webmaster status is required.'), $page);
            } else {
                new \Piwigo\Csrf\CsrfService()->checkOrFail(new \Piwigo\Html\HtmlService());

                $extraction = $pem_catalog->extractArchive(ExtensionType::Plugin, 'install', $_GET['revision'], $_GET['extension']);
                $install_status = $extraction['status'];
                $plugin_id = $extraction['id'];

                redirect($base_url . '&installstatus=' . $install_status . '&plugin_id=' . $plugin_id);
            }
        }

        // --------------------------------------------------------------install result
        if (isset($_GET['installstatus'])) {
            switch ($_GET['installstatus']) {
                case 'ok':
                    // since Piwigo 12, you need to be on the page of installed plugins to active a plugin with
                    // a JS action, no need to provide plugin_id in URL, just link to the page of installed
                    // plugins, filtered on deactivated plugins. The webmaster will have to find its newly
                    // installed plugin and click on the activation switch.
                    $activate_url = get_root_url() . 'admin.php?page=plugins&amp;filter=deactivated';

                    $this->pushPageMessage('infos', l10n('Plugin has been successfully copied'), $page);
                    $this->pushPageMessage('infos', '<a href="' . $activate_url . '">' . l10n('Activate it now') . '</a>', $page);

                    $installed_plugin_id = $_GET['plugin_id'] ?? null;
                    $installed_fs_plugin = is_string($installed_plugin_id) ? ($extension_scanner->scan(ExtensionType::Plugin)[$installed_plugin_id] ?? null) : null;
                    if ($installed_fs_plugin !== null) {
                        (new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))->record('system', ActivitySystem::Plugin, 'install', [
                            'plugin_id' => $installed_plugin_id,
                            'version' => $installed_fs_plugin['version'],
                        ]);
                    }
                    break;

                case 'temp_path_error':
                    $this->pushPageMessage('errors', l10n('Can\'t create temporary file.'), $page);
                    break;

                case 'dl_archive_error':
                    $this->pushPageMessage('errors', l10n('Can\'t download archive.'), $page);
                    break;

                case 'archive_error':
                    $this->pushPageMessage('errors', l10n('Can\'t read or extract archive.'), $page);
                    break;

                default:
                    $installstatus_raw = $_GET['installstatus'];
                    $installstatus_str = is_scalar($installstatus_raw) ? (string) $installstatus_raw : '';
                    $this->pushPageMessage('errors', l10n('An error occured during extraction (%s).', htmlspecialchars($installstatus_str)), $page);
                    $this->pushPageMessage('errors', l10n('Please check "plugins" folder and sub-folders permissions (CHMOD).'), $page);
            }
        }

        // ---------------------------------------------------------------Order options
        $template->assign(
            'order_options',
            [
                'date' => l10n('Post date'),
                'revision' => l10n('Last revisions'),
                'name' => l10n('Name'),
                'author' => l10n('Author'),
                'downloads' => l10n('Number of downloads'),
            ]
        );

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+

        // Beta test : show plugins of last version on PEM if the current version isn't present
        // If the current version in known, give the current and last version's compatible plugins
        $beta_test = false;

        if (isset($_GET['beta-test']) && is_string($_GET['beta-test']) && $_GET['beta-test'] === 'true') {
            $beta_test = true;
        }

        // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url']) in
        // one branch of include/common.inc.php, so PHPStan can't prove it's a
        // string across that file boundary -- narrow it once here (same pattern as
        // plugins.class.php::extract_plugin_files()).
        $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

        $fs_plugin_ids = [];
        foreach ($extension_scanner->scan(ExtensionType::Plugin) as $fs_plugin) {
            $extension = $fs_plugin['extension'] ?? null;
            if (is_scalar($extension)) {
                $fs_plugin_ids[] = (string) $extension;
            }
        }

        // get_server_plugins() legacy quirk: an empty versions-to-check list (no
        // PEM version matches the current branch) is treated as "nothing to show",
        // NOT a connection error -- only a real fetchRemote()/unserialize() failure
        // triggers the "Can't connect to server" message below.
        $versions_to_check = $pem_catalog->getVersionsToCheck(ExtensionType::Plugin, $beta_test);
        $server_plugins = $versions_to_check === [] ? [] : $pem_catalog->getServerExtensions(ExtensionType::Plugin, $fs_plugin_ids, true, $beta_test);

        if ($server_plugins !== null) {
            /* order plugins */
            $session_order = SessionService::get()->getSessionVar('plugins_new_order');
            $order_selected = is_string($session_order) && $session_order !== '' ? $session_order : 'date';
            $template->assign('order_selected', $order_selected);

            match ($order_selected) {
                'revision' => usort($server_plugins, PemCatalog::compareByRevisionDate(...)),
                'name' => uasort($server_plugins, PemCatalog::compareByName(...)),
                'author' => uasort($server_plugins, PemCatalog::compareByAuthor(...)),
                'downloads' => usort($server_plugins, PemCatalog::compareByDownloads(...)),
                default => krsort($server_plugins),
            };

            foreach ($server_plugins as $plugin) {
                // server_plugins entries come from an untyped unserialize() of a
                // remote PEM payload (plugins::get_server_plugins()) — every field
                // is mixed; narrow the ones used in a typed context below rather
                // than trusting the external payload's shape.
                $ext_desc_raw = $plugin['extension_description'] ?? null;
                $ext_desc = is_scalar($ext_desc_raw) ? trim((string) $ext_desc_raw, " \n\r") : '';
                [$small_desc] = explode("\n", wordwrap($ext_desc, 200));

                $revision_id_raw = $plugin['revision_id'] ?? null;
                $revision_id = is_scalar($revision_id_raw) ? (string) $revision_id_raw : '';
                $extension_id_raw = $plugin['extension_id'] ?? null;
                $extension_id = is_scalar($extension_id_raw) ? (string) $extension_id_raw : '';

                $url_auto_install = htmlentities($base_url)
                  . '&amp;revision=' . $revision_id
                  . '&amp;extension=' . $extension_id
                  . '&amp;pwg_token=' . (new \Piwigo\Csrf\CsrfService())->getToken()
                ;

                // get the age of the last revision in days
                $revision_date_raw = $plugin['revision_date'] ?? null;
                $revision_date_str = is_scalar($revision_date_raw) ? (string) $revision_date_raw : '';
                $revision_date = date_create($revision_date_str);
                $now_date = date_create();
                if ($revision_date === false || $now_date === false) {
                    // revision_date comes from the (external, less-trusted) PEM
                    // API response — fall back to "just now" rather than crash
                    // on a malformed value
                    $last_revision_diff = new DateInterval('P0D');
                } else {
                    $last_revision_diff = date_diff($revision_date, $now_date);
                }

                $certification = 1;
                $has_compatible_version = false;

                // Check if the current version is in the compatible version (not necessary if we are in beta test)
                if ($beta_test) {
                    $compatible_with_versions = $plugin['compatible_with_versions'] ?? null;
                    if (is_array($compatible_with_versions)) {
                        foreach ($compatible_with_versions as $vers) {
                            if (is_string($vers) and \Piwigo\Core\VersionHelper::getBranchFromVersion($vers) === \Piwigo\Core\VersionHelper::getBranchFromVersion(AppInfo::VERSION)) {
                                $has_compatible_version = true;
                            }
                        }
                    }
                } else {
                    $has_compatible_version = true;
                }

                if (! $has_compatible_version) {
                    $certification = -1;
                } elseif ($last_revision_diff->days < 90) { // if the last revision is new of 3 month or less
                    $certification = 3;
                } elseif ($last_revision_diff->days < 180) { // 6 month or less
                    $certification = 2;
                } elseif ($last_revision_diff->y > 3) { // 3 years or less
                    $certification = 0;
                }
                // Between 6 month and 3 years : certification = 1

                $template->append('plugins', [
                    'ID' => $plugin['extension_id'],
                    'EXT_NAME' => $plugin['extension_name'],
                    'EXT_URL' => $pem_base_url . '/extension_view.php?eid=' . $extension_id,
                    'SMALL_DESC' => trim($small_desc, " \r\n"),
                    'BIG_DESC' => $ext_desc,
                    'VERSION' => $plugin['revision_name'],
                    'REVISION_DATE' => preg_replace('/[^0-9]/', '', (string) strtotime($revision_date_str)),
                    'REVISION_FORMATED_DATE' => \Piwigo\Core\DateHelper::formatDate($revision_date_str, ['day', 'month', 'year']) . ', ' . \Piwigo\Core\DateHelper::timeSince($revision_date_str, 'day'),
                    'AUTHOR' => $plugin['author_name'],
                    'DOWNLOADS' => $plugin['extension_nb_downloads'],
                    'URL_INSTALL' => $url_auto_install,
                    'CERTIFICATION' => $certification,
                    'RATING' => $plugin['rating_score'],
                    'NB_RATINGS' => $plugin['nb_ratings'],
                    'SCREENSHOT' => (key_exists('screenshot_url', $plugin)) ? $plugin['screenshot_url'] : '',
                    'TAGS' => $plugin['tags'],
                ]);
            }

        } else {
            $this->pushPageMessage('errors', l10n('Can\'t connect to server.'), $page);
        }

        if (! $beta_test and (bool) preg_match('/(beta|RC)/', AppInfo::VERSION)) {
            $template->assign('BETA_URL', $base_url . '&amp;beta-test=true');
        }
        $template->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
        $template->assign('BETA_TEST', $beta_test);
        $template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
    }

    /**
     * @param array<string, mixed> $page
     */
    private function pushPageMessage(string $key, string $message, array &$page): void
    {
        $list = $page[$key] ?? [];
        $list = is_array($list) ? $list : [];
        $list[] = $message;
        $page[$key] = $list;
    }
}
