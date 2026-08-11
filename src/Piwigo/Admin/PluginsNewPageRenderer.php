<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use DateInterval;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\PluginsNewPageContext;
use Piwigo\Admin\Request\PluginsNewRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\DateHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;

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
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CurrentLogger $currentLogger,
        private readonly SessionService $sessionService,
        private readonly PageState $pageState,
        private readonly CurrentTemplate $currentTemplate,
        private readonly ActivityService $activityService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly CurrentUser $currentUser,
        private readonly Paths $paths,
        private readonly EventDispatcher $eventDispatcher,
    ) {}

    /**
     * $pageSlug and $tab are explicit params rather than
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (PluginsSubController) already knows both values statically/
     * locally (it's the only class registered for the 'plugins' slug in
     * config/admin_pages.php, and it already computes its own $tab local
     * before dispatching here).
     */
    public function render(string $pageSlug, string $tab): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableExtensionsInstall) {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $template->setFilenames([
            'plugins' => 'plugins_new.tpl',
        ]);

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->currentUser, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();

        $pluginsNewRequest = PluginsNewRequest::fromGlobals();

        // ------------------------------------------------------automatic installation
        if ($pluginsNewRequest->revision !== null and $pluginsNewRequest->extension !== null) {
            if (! $this->accessControl->isWebmaster()) {
                $this->pageState->addError($this->lang->t('Webmaster status is required.'));
            } else {
                new CsrfService($this->currentConfig)
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);

                $extraction = $pem_catalog->extractArchive(ExtensionType::Plugin, 'install', $pluginsNewRequest->revision, $pluginsNewRequest->extension);
                $install_status = $extraction->status;
                $plugin_id = $extraction->id;

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

                    $this->pageState->addInfo($this->lang->t('Plugin has been successfully copied'));
                    $this->pageState->addInfo('<a href="' . $activate_url . '">' . $this->lang->t('Activate it now') . '</a>');

                    $installed_plugin_id = $pluginsNewRequest->pluginId;
                    $installed_fs_plugin = $installed_plugin_id !== null ? ($extension_scanner->scan(ExtensionType::Plugin, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig)[$installed_plugin_id] ?? null) : null;
                    if ($installed_fs_plugin !== null) {
                        $this->activityService->record('system', ActivitySystem::Plugin, 'install', [
                            'plugin_id' => $installed_plugin_id,
                            'version' => $installed_fs_plugin['version'],
                        ]);
                    }
                    break;

                case 'temp_path_error':
                    $this->pageState->addError($this->lang->t('Can\'t create temporary file.'));
                    break;

                case 'dl_archive_error':
                    $this->pageState->addError($this->lang->t('Can\'t download archive.'));
                    break;

                case 'archive_error':
                    $this->pageState->addError($this->lang->t('Can\'t read or extract archive.'));
                    break;

                default:
                    $this->pageState->addError($this->lang->t('An error occured during extraction (%s).', htmlspecialchars($pluginsNewRequest->installStatus)));
                    $this->pageState->addError($this->lang->t('Please check "plugins" folder and sub-folders permissions (CHMOD).'));
            }
        }

        // ---------------------------------------------------------------Order options
        $order_options = [
            'date' => $this->lang->t('Post date'),
            'revision' => $this->lang->t('Last revisions'),
            'name' => $this->lang->t('Name'),
            'author' => $this->lang->t('Author'),
            'downloads' => $this->lang->t('Number of downloads'),
        ];

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+

        // Beta test : show plugins of last version on PEM if the current version isn't present
        // If the current version in known, give the current and last version's compatible plugins
        $beta_test = $pluginsNewRequest->betaTest;

        $pem_base_url = RequestBootstrap::pemUrl();

        $fs_plugin_ids = [];
        foreach ($extension_scanner->scan(ExtensionType::Plugin, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig) as $fs_plugin) {
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

        $order_selected = null;
        $tpl_plugins = [];
        if ($server_plugins !== null) {
            /* order plugins */
            $order_selected = $this->sessionService->getPluginsNewOrder() ?? 'date';

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
                  . '&amp;pwg_token=' . new CsrfService($this->currentConfig)->getToken()
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
                            if (is_string($vers) and VersionHelper::getBranchFromVersion($vers) === VersionHelper::getBranchFromVersion(AppInfo::VERSION)) {
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

                $tpl_plugins[] = [
                    'ID' => $plugin['extension_id'],
                    'EXT_NAME' => $plugin['extension_name'],
                    'EXT_URL' => $pem_base_url . '/extension_view.php?eid=' . $extension_id,
                    'SMALL_DESC' => trim($small_desc, " \r\n"),
                    'BIG_DESC' => $ext_desc,
                    'VERSION' => $plugin['revision_name'],
                    'REVISION_DATE' => preg_replace('/[^0-9]/', '', (string) strtotime($revision_date_str)),
                    'REVISION_FORMATED_DATE' => DateHelper::formatDate($revision_date_str, ['day', 'month', 'year']) . ', ' . DateHelper::timeSince($revision_date_str, 'day'),
                    'AUTHOR' => $plugin['author_name'],
                    'DOWNLOADS' => $plugin['extension_nb_downloads'],
                    'URL_INSTALL' => $url_auto_install,
                    'CERTIFICATION' => $certification,
                    'RATING' => $plugin['rating_score'],
                    'NB_RATINGS' => $plugin['nb_ratings'],
                    'SCREENSHOT' => (key_exists('screenshot_url', $plugin)) ? $plugin['screenshot_url'] : '',
                    'TAGS' => $plugin['tags'],
                ];
            }

        } else {
            $this->pageState->addError($this->lang->t('Can\'t connect to server.'));
        }

        $beta_url = null;
        if (! $beta_test and (bool) preg_match('/(beta|RC)/', AppInfo::VERSION)) {
            $beta_url = $base_url . '&amp;beta-test=true';
        }

        $template->assignContext(new PluginsNewPageContext(
            orderOptions: $order_options,
            orderSelected: $order_selected,
            betaUrl: $beta_url,
            adminPageTitle: $this->lang->t('Plugins'),
            betaTest: $beta_test,
            plugins: $tpl_plugins,
        ));

        $template->assignVarFromHandle('ADMIN_CONTENT', 'plugins');
    }
}
