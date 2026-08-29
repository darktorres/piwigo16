<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use DateInterval;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\CatalogPluginRow;
use Piwigo\Admin\Projection\PluginsNewView;
use Piwigo\Admin\Request\PluginsNewRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\VersionHelper;
use Piwigo\Csrf\CsrfService;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;

/**
 * Ported from admin/plugins_new.php (the "new" tab of the "plugins" page
 * slug, dispatched by PluginsSubController) -- browse the PEM catalog and
 * install a new plugin.
 *
 * Already correctly protected before this port -- its one real mutation
 * (revision and extension both present, install)
 * already gates on is_webmaster() and check_pwg_token() -- no CSRF fix
 * needed here, unlike PluginsInstalledPageRenderer's own dead-code
 * cleanup.
 */
final readonly class PluginsNewPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private SessionService $sessionService,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private ActivityService $activityService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private CurrentUser $currentUser,
        private Paths $paths,
        private Renderer $renderer,
    ) {}

    /**
     * $pageSlug and $tab are explicit params rather than
     * `global $page['page']`/`$page['tab']` -- the one real caller
     * (PluginsSubController) already knows both values statically/
     * locally (it's the only class registered for the 'plugins' slug in
     * config/admin_pages.php, and it already computes its own $tab local
     * before dispatching here).
     */
    public function render(string $pageSlug, string $tab): AdminPageResult
    {
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableExtensionsInstall) {
            $this->htmlRenderer
                ->fatalError('Piwigo extensions install/update system is disabled');
        }

        $base_url = $this->urlService->getRootUrl() . 'admin.php?page=' . $pageSlug . '&tab=' . $tab;

        $pem_catalog = new PemCatalog(new ZipExtractor(), $this->currentLogger, $this->paths, $this->currentConfig);
        $extension_scanner = new ExtensionScanner();

        $pluginsNewRequest = PluginsNewRequest::fromGlobals();

        if ($pluginsNewRequest->revision !== null and $pluginsNewRequest->extension !== null) {
            if (! $this->accessControl->isWebmaster()) {
                $this->pageState->addError($this->lang->t('Webmaster status is required.'));
            } else {
                $this->csrfService
                    ->checkOrFail($this->htmlRenderer, $this->redirectService);

                $extraction = $pem_catalog->extractArchive(ExtensionType::Plugin, 'install', $pluginsNewRequest->revision, $pluginsNewRequest->extension);
                $install_status = $extraction->status;
                $plugin_id = $extraction->id;

                $this->redirectService->redirect($base_url . '&installstatus=' . $install_status . '&plugin_id=' . ($plugin_id ?? ''));
            }
        }

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
                    $installed_fs_plugin = $installed_plugin_id !== null ? ($extension_scanner->scanPlugins($this->paths, $this->currentUser, $this->currentConfig)[$installed_plugin_id] ?? null) : null;
                    if ($installed_fs_plugin !== null) {
                        $this->activityService->record('system', ActivitySystem::Plugin, 'install', [
                            'plugin_id' => $installed_plugin_id,
                            'version' => $installed_fs_plugin->version,
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

        $order_options = [
            'date' => $this->lang->t('Post date'),
            'revision' => $this->lang->t('Last revisions'),
            'name' => $this->lang->t('Name'),
            'author' => $this->lang->t('Author'),
            'downloads' => $this->lang->t('Number of downloads'),
        ];

        // Beta test : show plugins of last version on PEM if the current version isn't present
        // If the current version in known, give the current and last version's compatible plugins
        $beta_test = $pluginsNewRequest->betaTest;

        // Must match the type-specific mirror getServerExtensions() below
        // actually fetches from (PemCatalog's own RequestBootstrap::
        // pemUrl($type) call) -- the bare, untyped pemUrl() resolves a
        // different base (the generic alternativePemUrl override, or the
        // real, deliberately-unreachable AppInfo::URL default), which
        // would 404 every EXT_URL "Website" link below even when the
        // plugin catalog itself loaded fine from a real per-type mirror.
        $pem_base_url = RequestBootstrap::pemUrl(ExtensionType::Plugin);

        $fs_plugin_ids = [];
        foreach ($extension_scanner->scanPlugins($this->paths, $this->currentUser, $this->currentConfig) as $fs_plugin) {
            if ($fs_plugin->extension !== null) {
                $fs_plugin_ids[] = $fs_plugin->extension;
            }
        }

        $server_plugins = $pem_catalog->getServerExtensions(ExtensionType::Plugin, $fs_plugin_ids, true, $beta_test);

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

                $revision_id_raw = $plugin['revision_id'] ?? null;
                $revision_id = is_scalar($revision_id_raw) ? (string) $revision_id_raw : '';
                $extension_id_raw = $plugin['extension_id'] ?? null;
                $extension_id = is_scalar($extension_id_raw) ? (string) $extension_id_raw : '';

                $url_auto_install = htmlentities($base_url)
                  . '&amp;revision=' . $revision_id
                  . '&amp;extension=' . $extension_id
                  . '&amp;pwg_token=' . $this->csrfService->getToken()
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

                // 'screenshot_url' matches the real upstream PEM wire
                // format this fork no longer talks to (PemCatalog's own
                // docblock) -- the actual local-mirror manifest.json
                // field is 'thumbnail' (a bare filename inside the
                // sibling repo's own docroot, same convention
                // piwigo16-ext/api/get_revision_list.php's own
                // screenshot_url computation already uses:
                // $base_url . '/' . $ext['thumbnail']).
                $thumbnail_raw = $plugin['thumbnail'] ?? null;
                $screenshot = is_string($thumbnail_raw) && $thumbnail_raw !== ''
                    ? $pem_base_url . '/' . $thumbnail_raw
                    : '';

                // Every one of these was read straight off the manifest
                // with no `??`, so a catalog entry omitting any of
                // extension_nb_downloads/rating_score/nb_ratings/tags --
                // which the mirror's own pre-17.x entries all do -- raised
                // an "Undefined array key" warning per field, per row. The
                // fixture never showed it because only the one 17.0-compatible
                // mock renders here.
                $rating_raw = $plugin['rating_score'] ?? null;
                $tags_raw = $plugin['tags'] ?? null;

                $tpl_plugins[] = new CatalogPluginRow(
                    id: (int) $extension_id,
                    name: self::text($plugin['extension_name'] ?? null),
                    url: $pem_base_url . '/extension_view.php?eid=' . $extension_id,
                    description: $ext_desc,
                    version: self::text($plugin['revision_name'] ?? null),
                    revisionSort: preg_replace('/[^0-9]/', '', (string) strtotime($revision_date_str)) ?? '',
                    revisionDate: $revision_date_str,
                    author: self::text($plugin['author_name'] ?? null),
                    downloads: (int) self::text($plugin['extension_nb_downloads'] ?? null),
                    installUrl: $url_auto_install,
                    certification: $certification,
                    rating: is_numeric($rating_raw) ? (float) $rating_raw : null,
                    nbRatings: (int) self::text($plugin['nb_ratings'] ?? null),
                    screenshot: $screenshot,
                    tags: is_array($tags_raw) ? array_map(self::text(...), $tags_raw) : [],
                );
            }

        } else {
            $this->pageState->addError($this->lang->t('Can\'t connect to server.'));
        }

        $beta_url = null;
        if (! $beta_test and (bool) preg_match('/(beta|RC)/', AppInfo::VERSION)) {
            $beta_url = $base_url . '&amp;beta-test=true';
        }

        $adminContent = $this->renderer->render(new PluginsNewView(
            orderOptions: $order_options,
            orderSelected: $order_selected,
            betaUrl: $beta_url,
            betaTest: $beta_test,
            plugins: $tpl_plugins,
            colorscheme: $template->themeConf('colorscheme'),
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Plugins'),
        );
    }

    /**
     * A manifest value is whatever the mirrored JSON happened to hold, so a
     * field this page renders as text is a string only by convention.
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
