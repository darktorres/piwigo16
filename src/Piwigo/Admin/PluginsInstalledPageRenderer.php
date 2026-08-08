<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Request\PluginsInstalledDisplayRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Event\Admin\GetAdminPluginMenuLinks;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;

/**
 * Ported from admin/plugins_installed.php (the "installed" tab of the
 * "plugins" page slug, dispatched by PluginsSubController) -- lists
 * installed plugins.
 *
 * No CSRF gap here (confirmed by direct read and by tracing
 * plugins_installated.js's real activate/deactivate/delete/restore flow to
 * the already token-protected ws.php?method=pwg.plugins.performAction --
 * Piwigo\Ws\PwgExtensions::pluginsPerformAction() checks get_pwg_token()
 * against $params['pwg_token'] itself).
 */
final class PluginsInstalledPageRenderer
{
    /**
     * $pageSlug is an explicit param instead of `global $page['page'];` --
     * the one real caller (PluginsSubController) already knows its own
     * fixed page slug statically (it's the only class registered for the
     * 'plugins' slug in config/admin_pages.php).
     */
    public function render(Lang $lang, AccessControl $accessControl, string $pageSlug, UrlServiceInterface $urlService, CurrentLogger $currentLogger, SessionService $sessionService, EventDispatcher $eventDispatcher, CurrentTemplate $currentTemplate, PreferencesService $preferencesService, HtmlRenderingInterface $htmlRenderer, CurrentConfig $currentConfig, CurrentUser $currentUser, Paths $paths): void
    {
        $template = $currentTemplate->get();

        $template->set_filenames([
            'plugins' => 'plugins_installed.tpl',
        ]);

        $pluginsDisplay = PluginsInstalledDisplayRequest::fromGlobals();

        // should we display details on plugins?
        if ($pluginsDisplay->showDetails !== null) {
            $show_details = $pluginsDisplay->showDetails;

            $sessionService->setSessionVar('plugins_show_details', $show_details);
        } elseif ($sessionService->getSessionVar('plugins_show_details') !== null) {
            $show_details = $sessionService->getSessionVar('plugins_show_details');
        } else {
            $show_details = false;
        }

        $base_url = $urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;
        $pwg_token = new CsrfService($currentConfig)
            ->getToken();

        $conn = DbConnection::build();
        $extension_repository = new ExtensionRepository(EntityManagerFactory::build($conn));
        $pem_catalog = new PemCatalog(new ZipExtractor(), $currentLogger, $currentUser, $paths, $currentConfig);
        // ExtensionScanner::scan()'s own declared return type is a generic
        // array<string, array<string, mixed>> dispatch shape by design (see
        // that method's own docblock) -- every $fs_plugin read below
        // follows its documented convention and reads specific keys
        // defensively instead.
        $fs_plugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService, $lang, $paths, $currentUser, $eventDispatcher, $currentConfig);
        uasort($fs_plugins, $htmlRenderer->nameCompare(...));
        $db_plugins_by_id = $extension_repository->findAll(ExtensionType::Plugin);

        // --------------------------------------------------------Incompatible Plugins
        if ($pluginsDisplay->isIncompatiblePluginsRequest) {
            $incompatible_plugins_raw = $pem_catalog->getIncompatibleExtensions(ExtensionType::Plugin, $fs_plugins, ExtensionType::Plugin->defaultIds());

            if ($incompatible_plugins_raw === false) {
                echo json_encode([]);
                exit;
            }

            $incompatible_plugins = [];

            foreach ($incompatible_plugins_raw as $plugin => $version) {
                if ($plugin === '~~expire~~') {
                    continue;
                }
                $incompatible_plugins[] = $plugin;

            }
            echo json_encode($incompatible_plugins);
            exit;
        }

        // --------------------------------------------------------Get the menu with the depreciated version

        $plugin_menu_links_deprec_event = $eventDispatcher->dispatchChange(new GetAdminPluginMenuLinks([]));

        $settings_url_for_plugin_deprec = [];

        // dispatchChange() enforces the top-level array type, but a
        // misbehaving third-party handler could still populate it with
        // malformed element values PHP's own type system can't catch at
        // runtime -- narrow each item defensively before reading its own
        // 'URL' entry.
        foreach ($plugin_menu_links_deprec_event->value as $value) {
            if (! is_array($value) || ! isset($value['URL']) || ! is_string($value['URL'])) {
                continue;
            }

            $menu_link_url = $value['URL'];

            if ((bool) preg_match('/^admin\.php\?page=plugin-(.*)$/', $menu_link_url, $matches)) {
                $settings_url_for_plugin_deprec[$matches[1]] = $menu_link_url;
            } elseif ((bool) preg_match('/^.*section=(.*?)[\/&%].*$/', $menu_link_url, $matches)) {
                $settings_url_for_plugin_deprec[$matches[1]] = $menu_link_url;
            }
        }

        // +-----------------------------------------------------------------------+
        // |                     start template output                             |
        // +-----------------------------------------------------------------------+

        $merged_extensions = $pem_catalog->getLocallyMergedExtensions();
        $merged_plugins = false;
        $tpl_plugins = [];
        $count_types_plugins = [
            'active' => 0,
            'inactive' => 0,
            'missing' => 0,
            'merged' => 0,
        ];

        foreach ($fs_plugins as $plugin_id => $fs_plugin) {
            // $_SESSION is a superglobal with no known value type, so PHPStan
            // sees $_SESSION['incompatible_plugins'] as mixed; narrow it once here
            // (same pattern as plugins.class.php's get_incompatible_plugins()).
            $incompatible_plugins_session = $_SESSION['incompatible_plugins'] ?? null;
            if (is_array($incompatible_plugins_session)
              and isset($incompatible_plugins_session[$plugin_id])) {
                $fs_version = $fs_plugin['version'];
                $session_version = $incompatible_plugins_session[$plugin_id];
                $session_version = is_scalar($session_version) ? (string) $session_version : '';

                if ($fs_version !== $session_version) {
                    // Incompatible plugins must be reinitilized
                    unset($_SESSION['incompatible_plugins']);
                }
            }

            $setting_url = '';
            if (isset($settings_url_for_plugin_deprec[$plugin_id])) { // old version
                $setting_url = $settings_url_for_plugin_deprec[$plugin_id];
            } elseif ((bool) $fs_plugin['hasSettings']) { // new version
                $setting_url = 'admin.php?page=plugin-' . $plugin_id;

                if ((bool) preg_match('/^piwigo-(videojs|openstreetmap)$/', $plugin_id)) {
                    $setting_url = str_replace('piwigo-', 'piwigo_', $setting_url);
                }
            }

            $url_to_replace = [
                'http://piwigo.org/ext',
                'https://piwigo.org/ext',
            ];
            $fs_plugin_uri = is_string($fs_plugin['uri'] ?? null) ? $fs_plugin['uri'] : '';
            $pem_url = RequestBootstrap::pemUrl();
            $visit_url = str_replace($url_to_replace, $pem_url, $fs_plugin_uri);

            $tpl_plugin = [
                'ID' => $plugin_id,
                'NAME' => $fs_plugin['name'],
                'VISIT_URL' => $visit_url,
                'VERSION' => $fs_plugin['version'],
                'DESC' => $fs_plugin['description'],
                'AUTHOR' => $fs_plugin['author'],
                'AUTHOR_URL' => $fs_plugin['author uri'] ?? null,
                'SETTINGS_URL' => $setting_url,
            ];

            if (isset($db_plugins_by_id[$plugin_id])) {
                $db_plugin_state = $db_plugins_by_id[$plugin_id]['state'] ?? null;
                $plugin_state = is_string($db_plugin_state) ? $db_plugin_state : 'inactive';
            } else {
                $plugin_state = 'inactive';
            }
            $tpl_plugin['STATE'] = $plugin_state;

            $fs_plugin_extension = $fs_plugin['extension'] ?? null;
            if (is_string($fs_plugin_extension) and isset($merged_extensions[$fs_plugin_extension])) {
                // Deactivate manually plugin from database
                $extension_repository->updatePluginState($plugin_id, 'inactive');

                $plugin_state = 'merged';
                $tpl_plugin['STATE'] = $plugin_state;
                $tpl_plugin['DESC'] = $lang->t('THIS PLUGIN IS NOW PART OF PIWIGO CORE! DELETE IT NOW.');
                $merged_plugins = true;
            }

            $count_types_plugins[$plugin_state]++;

            $tpl_plugins[] = $tpl_plugin;
        }

        $template->append('plugin_states', 'active');
        $template->append('plugin_states', 'inactive');

        if ($merged_plugins) {
            $template->append('plugin_states', 'merged');
        }

        $missing_plugin_ids = array_diff(
            array_keys($db_plugins_by_id),
            array_keys($fs_plugins)
        );

        if (count($missing_plugin_ids) > 0) {
            foreach ($missing_plugin_ids as $plugin_id) {
                $tpl_plugins[] = [
                    'NAME' => $plugin_id,
                    'ID' => $plugin_id,
                    'VERSION' => $db_plugins_by_id[$plugin_id]['version'],
                    'DESC' => $lang->t('ERROR: THIS PLUGIN IS MISSING BUT IT IS INSTALLED! UNINSTALL IT NOW.'),
                    'STATE' => 'missing',
                ];
                $count_types_plugins['missing']++;
            }
            $template->append('plugin_states', 'missing');
        }

        $template->assign(
            [
                'plugins' => $tpl_plugins,
                'count_types_plugins' => $count_types_plugins,
                'PWG_TOKEN' => $pwg_token,
                'base_url' => $base_url,
                'show_details' => $show_details,
                'max_inactive_before_hide' => $pluginsDisplay->showInactive ? 999 : 8,
                'isWebmaster' => ($accessControl->isWebmaster()) ? 1 : 0,
                'ADMIN_PAGE_TITLE' => $lang->t('Plugins'),
                'view_selector' => $preferencesService->getPluginManagerView() ?? 'classic',
                'CONF_ENABLE_EXTENSIONS_INSTALL' => $currentConfig->enableExtensionsInstall(),
            ]
        );

        $template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
    }
}
