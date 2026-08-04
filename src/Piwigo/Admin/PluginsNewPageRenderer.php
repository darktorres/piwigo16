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
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;

/**
 * Ported from admin/plugins_new.php (the "new" tab of the "plugins" page
 * slug, dispatched by PluginsSubController) -- browse the PEM catalog and
 * install a new plugin.
 *
 * Already correctly protected before this port (confirmed by direct read):
 * its one real mutation (revision and extension both present, install)
 * already gates on is_webmaster() and check_pwg_token() -- no CSRF fix
 * needed here, unlike PluginsInstalledPageRenderer's own dead-code
 * cleanup.
 */
final class PluginsNewPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Core\CurrentLogger $currentLogger,
        private readonly SessionService $sessionService,
        private readonly \Piwigo\Core\PageState $pageState,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Activity\ActivityService $activityService,
    ) {}

    /**
     * Legacy Coupling Retirement Track A: $pageSlug (batch A5.2f) and
     * $tab (batch A5.2h) are explicit params instead of
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (PluginsSubController) already knows both values statically/
     * locally (it's the only class registered for the 'plugins' slug in
     * config/admin_pages.php, and it already computes its own $tab local
     * before dispatching here). Also drops a confirmed-dead
     * `global $conf;` found incidentally (never referenced anywhere in
     * this method).
     */
    public function render(string $pageSlug, string $tab): void
    {
        $template = $this->currentTemplate->get();

        if (! \Piwigo\Config\CurrentConfig::enableExtensionsInstall()) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $template->set_filenames([
            'plugins' => 'plugins_new.tpl',
        ]);

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger);
        $extension_scanner = new ExtensionScanner();

        $pluginsNewRequest = Request\PluginsNewRequest::fromGlobals();

        // ------------------------------------------------------automatic installation
        if ($pluginsNewRequest->revision !== null and $pluginsNewRequest->extension !== null) {
            if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
                $this->pageState->addError(Lang::t('Webmaster status is required.'));
            } else {
                new \Piwigo\Csrf\CsrfService()
                    ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

                $extraction = $pem_catalog->extractArchive(ExtensionType::Plugin, 'install', $pluginsNewRequest->revision, $pluginsNewRequest->extension);
                $install_status = $extraction['status'];
                $plugin_id = $extraction['id'];

                $this->redirectService->redirect($base_url . '&installstatus=' . $install_status . '&plugin_id=' . $plugin_id);
            }
        }

        // --------------------------------------------------------------install result
        if ($pluginsNewRequest->installStatusPresent) {
            switch ($pluginsNewRequest->installStatus) {
                case 'ok':
                    // since Piwigo 12, you need to be on the page of installed plugins to active a plugin with
                    // a JS action, no need to provide plugin_id in URL, just link to the page of installed
                    // plugins, filtered on deactivated plugins. The webmaster will have to find its newly
                    // installed plugin and click on the activation switch.
                    $activate_url = $this->urlService->getRootUrl() . 'admin.php?page=plugins&amp;filter=deactivated';

                    $this->pageState->addInfo(Lang::t('Plugin has been successfully copied'));
                    $this->pageState->addInfo('<a href="' . $activate_url . '">' . Lang::t('Activate it now') . '</a>');

                    $installed_plugin_id = $pluginsNewRequest->pluginId;
                    $installed_fs_plugin = $installed_plugin_id !== null ? ($extension_scanner->scan(ExtensionType::Plugin, $this->urlService)[$installed_plugin_id] ?? null) : null;
                    if ($installed_fs_plugin !== null) {
                        $this->activityService->record('system', ActivitySystem::Plugin, 'install', [
                            'plugin_id' => $installed_plugin_id,
                            'version' => $installed_fs_plugin['version'],
                        ]);
                    }
                    break;

                case 'temp_path_error':
                    $this->pageState->addError(Lang::t('Can\'t create temporary file.'));
                    break;

                case 'dl_archive_error':
                    $this->pageState->addError(Lang::t('Can\'t download archive.'));
                    break;

                case 'archive_error':
                    $this->pageState->addError(Lang::t('Can\'t read or extract archive.'));
                    break;

                default:
                    $this->pageState->addError(Lang::t('An error occured during extraction (%s).', htmlspecialchars($pluginsNewRequest->installStatus)));
                    $this->pageState->addError(Lang::t('Please check "plugins" folder and sub-folders permissions (CHMOD).'));
            }
        }

        // ---------------------------------------------------------------Order options
        $template->assign(
            'order_options',
            [
                'date' => Lang::t('Post date'),
                'revision' => Lang::t('Last revisions'),
                'name' => Lang::t('Name'),
                'author' => Lang::t('Author'),
                'downloads' => Lang::t('Number of downloads'),
            ]
        );

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+

        // Beta test : show plugins of last version on PEM if the current version isn't present
        // If the current version in known, give the current and last version's compatible plugins
        $beta_test = $pluginsNewRequest->betaTest;

        $pem_base_url = \Piwigo\Bootstrap\RequestBootstrap::pemUrl();

        $fs_plugin_ids = [];
        foreach ($extension_scanner->scan(ExtensionType::Plugin, $this->urlService) as $fs_plugin) {
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
            $session_order = $this->sessionService->getSessionVar('plugins_new_order');
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
                  . '&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken()
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
            $this->pageState->addError(Lang::t('Can\'t connect to server.'));
        }

        if (! $beta_test and (bool) preg_match('/(beta|RC)/', AppInfo::VERSION)) {
            $template->assign('BETA_URL', $base_url . '&amp;beta-test=true');
        }
        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Plugins'));
        $template->assign('BETA_TEST', $beta_test);
        $template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
    }
}
