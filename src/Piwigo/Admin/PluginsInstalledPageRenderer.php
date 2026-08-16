<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Event\GetAdminPluginMenuLinks;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\PluginsInstalledPageContext;
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
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\Session\SessionService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;

/**
 * Ported from admin/plugins_installed.php (the "installed" tab of the
 * "plugins" page slug, dispatched by PluginsSubController) -- lists
 * installed plugins.
 *
 * No CSRF gap here -- plugins_installated.js's real
 * activate/deactivate/delete/restore flow traces to
 * the already token-protected ws.php?method=pwg.plugins.performAction --
 * Piwigo\Ws\Extensions::pluginsPerformAction() checks get_pwg_token()
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
    public function render(Lang $lang, AccessControl $accessControl, string $pageSlug, UrlServiceInterface $urlService, CurrentLogger $currentLogger, SessionService $sessionService, EventDispatcher $eventDispatcher, CurrentTemplate $currentTemplate, PreferencesService $preferencesService, HtmlRenderingInterface $htmlRenderer, CurrentConfig $currentConfig, CsrfService $csrfService, CurrentUser $currentUser, Paths $paths, PluginRegistry $pluginRegistry, EntityManagerInterface $entityManager): void
    {
        $template = $currentTemplate->get();

        $pluginsDisplay = PluginsInstalledDisplayRequest::fromGlobals();

        // should we display details on plugins?
        if ($pluginsDisplay->showDetails !== null) {
            $show_details = $pluginsDisplay->showDetails;

            $sessionService->setSessionVar('plugins_show_details', $show_details);
        } else {
            $show_details = $sessionService->getPluginsShowDetails() ?? false;
        }

        $base_url = $urlService->getRootUrl() . 'admin.php?page=' . $pageSlug;
        $pwg_token = $csrfService
            ->getToken();

        $extension_repository = new ExtensionRepository($entityManager);
        $pem_catalog = new PemCatalog(new ZipExtractor(), $currentLogger, $paths, $currentConfig);
        // ExtensionScanner::scan()'s own declared return type is a generic
        // array<string, array<string, mixed>> dispatch shape by design (see
        // that method's own docblock) -- every $fs_plugin read below
        // follows its documented convention and reads specific keys
        // defensively instead.
        $fs_plugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService, $lang, $paths, $currentUser, $eventDispatcher, $currentConfig, $entityManager);

        // P27.5's own original gap here: ExtensionScanner used to recognize
        // only the legacy main.inc.php header-comment format, so a
        // new-contract plugin (plugin.json only) was invisible to it. Since
        // P27.10 retired that legacy scan entirely, ExtensionScanner::
        // scanPlugin() reads plugin.json directly -- the same marker file
        // $pluginRegistry->getAllManifests() itself scans, just without its
        // stricter opis/json-schema validation, so every id the registry
        // can find, the fs scan above already finds first (a schema-valid
        // plugin.json is by construction also a valid one for the fs
        // scan's own looser read). This merge is very likely fully
        // redundant now -- kept as-is rather than removed in the same pass
        // that made it redundant, pending its own dedicated verification.
        foreach ($pluginRegistry->getAllManifests() as $manifestId => $manifest) {
            if (isset($fs_plugins[$manifestId])) {
                continue;
            }
            $fs_plugins[$manifestId] = [
                'name' => $manifest->name,
                'version' => $manifest->version,
                'description' => $manifest->description,
                'author' => $manifest->author ?? '',
                'author uri' => $manifest->authorUri,
                'uri' => $manifest->homepage ?? '',
                'hasSettings' => $manifest->hasSettings,
                'extension' => null,
            ];
        }

        uasort($fs_plugins, $htmlRenderer->nameCompare(...));
        $db_plugins_by_id = $extension_repository->findAll(ExtensionType::Plugin);

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

        $plugin_menu_links_deprec_event = $eventDispatcher->dispatch(new GetAdminPluginMenuLinks([]));

        $settings_url_for_plugin_deprec = [];

        // GetAdminPluginMenuLinks::$value is typed array, but a
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

        $plugin_states = ['active', 'inactive'];

        if ($merged_plugins) {
            $plugin_states[] = 'merged';
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
            $plugin_states[] = 'missing';
        }

        $template->assignContext(new PluginsInstalledPageContext(
            plugins: $tpl_plugins,
            countTypesPlugins: $count_types_plugins,
            pwgToken: $pwg_token,
            baseUrl: $base_url,
            showDetails: $show_details,
            maxInactiveBeforeHide: $pluginsDisplay->showInactive ? 999 : 8,
            isWebmaster: $accessControl->isWebmaster(),
            adminPageTitle: $lang->t('Plugins'),
            viewSelector: $preferencesService->getPluginManagerView() ?? 'classic',
            enableExtensionsInstall: $currentConfig->enableExtensionsInstall,
            pluginStates: $plugin_states,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'plugins_installed.latte');
    }
}
