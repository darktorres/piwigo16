<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Event\GetAdminPluginMenuLinks;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\Projection\PluginScanRow;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\PluginListRow;
use Piwigo\Admin\Projection\PluginsInstalledView;
use Piwigo\Admin\Request\PluginsInstalledDisplayRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
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
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserStatus;

/**
 * Ported from admin/plugins_installed.php (the "installed" tab of the
 * "plugins" page slug, dispatched by PluginsSubController) -- lists
 * installed plugins.
 *
 * No CSRF gap here -- plugins_installated.js's real
 * activate/deactivate/delete/restore flow calls the already
 * `X-CSRF-Token`-protected `POST /api/v1/plugins/{id}/actions/perform`
 * (`Controller\Api\Extensions\PluginPerformActionController`).
 */
final class PluginsInstalledPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, CurrentLogger $currentLogger, SessionService $sessionService, EventDispatcher $eventDispatcher, CurrentTemplate $currentTemplate, PreferencesService $preferencesService, HtmlRenderingInterface $htmlRenderer, CurrentConfig $currentConfig, CsrfService $csrfService, CurrentUser $currentUser, Paths $paths, PluginRegistry $pluginRegistry, EntityManagerInterface $entityManager, Renderer $renderer): AdminPageResult
    {
        $pluginsDisplay = PluginsInstalledDisplayRequest::fromGlobals();

        // should we display details on plugins?
        if ($pluginsDisplay->showDetails !== null) {
            $show_details = $pluginsDisplay->showDetails;

            $sessionService->setSessionVar('plugins_show_details', $show_details);
        } else {
            $show_details = $sessionService->getPluginsShowDetails() ?? false;
        }

        $pwg_token = $csrfService
            ->getToken();

        $extension_repository = new ExtensionRepository($entityManager);
        $pem_catalog = new PemCatalog(new ZipExtractor(), $currentLogger, $paths, $currentConfig);
        $fs_plugins = new ExtensionScanner()
            ->scanPlugins($paths, $currentUser, $currentConfig);

        // ExtensionScanner::scanPlugin() reads plugin.json directly -- the
        // same marker file $pluginRegistry->getAllManifests() itself
        // scans, just without its stricter opis/json-schema validation,
        // so every id the registry can find, the fs scan above already
        // finds first (a schema-valid plugin.json is by construction also
        // a valid one for the fs scan's own looser read). This merge is
        // very likely fully redundant -- kept as-is pending its own
        // dedicated verification.
        foreach ($pluginRegistry->getAllManifests() as $manifestId => $manifest) {
            if (isset($fs_plugins[$manifestId])) {
                continue;
            }
            // Same webmaster-gated resolution as ExtensionScanner::
            // scanPlugin()'s own $data['hasSettings'] handling --
            // PluginManifest::$hasSettings carries the same bool|'webmaster'
            // union un-resolved.
            $hasSettings = $manifest->hasSettings === true
                || ($manifest->hasSettings === 'webmaster' && $currentUser->get()->status === UserStatus::Webmaster);
            $fs_plugins[$manifestId] = new PluginScanRow(
                name: $manifest->name,
                version: $manifest->version,
                uri: $manifest->homepage ?? '',
                description: $manifest->description,
                author: $manifest->author ?? '',
                hasSettings: $hasSettings,
                authorUri: $manifest->authorUri,
            );
        }

        // Piwigo\Html\HtmlService::nameCompare() (HtmlRenderingInterface's
        // own real, still-generic array<string, mixed> $a/$b contract)
        // doesn't fit a real PluginScanRow object directly -- inlined here
        // rather than wrapping each row back into an array just to satisfy
        // that signature, same strcmp()-on-strtolower() logic.
        uasort($fs_plugins, static fn (PluginScanRow $a, PluginScanRow $b): int => strcmp(strtolower($a->name), strtolower($b->name)));
        $db_plugins_by_id = $extension_repository->findAllPlugins();

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
                $fs_version = $fs_plugin->version;
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
            } elseif ($fs_plugin->hasSettings) { // new version
                $setting_url = 'admin.php?page=plugin-' . $plugin_id;

                if ((bool) preg_match('/^piwigo-(videojs|openstreetmap)$/', $plugin_id)) {
                    $setting_url = str_replace('piwigo-', 'piwigo_', $setting_url);
                }
            }

            $url_to_replace = [
                'http://piwigo.org/ext',
                'https://piwigo.org/ext',
            ];
            $pem_url = RequestBootstrap::pemUrl();
            $visit_url = str_replace($url_to_replace, $pem_url, $fs_plugin->uri);

            $plugin_state = $db_plugins_by_id[$plugin_id]->state ?? 'inactive';
            $desc = $fs_plugin->description;

            $fs_plugin_extension = $fs_plugin->extension;
            if ($fs_plugin_extension !== null and isset($merged_extensions[$fs_plugin_extension])) {
                // Deactivate manually plugin from database
                $extension_repository->updatePluginState($plugin_id, 'inactive');

                $plugin_state = 'merged';
                $desc = $lang->t('THIS PLUGIN IS NOW PART OF PIWIGO CORE! DELETE IT NOW.');
            }

            $count_types_plugins[$plugin_state]++;

            $tpl_plugins[] = new PluginListRow(
                id: $plugin_id,
                name: $fs_plugin->name,
                visitUrl: $visit_url,
                version: $fs_plugin->version,
                desc: $desc,
                author: $fs_plugin->author,
                authorUrl: $fs_plugin->authorUri,
                settingsUrl: $setting_url,
                state: $plugin_state,
            );
        }

        $missing_plugin_ids = array_diff(
            array_keys($db_plugins_by_id),
            array_keys($fs_plugins)
        );

        if (count($missing_plugin_ids) > 0) {
            foreach ($missing_plugin_ids as $plugin_id) {
                $tpl_plugins[] = new PluginListRow(
                    id: $plugin_id,
                    name: $plugin_id,
                    visitUrl: '',
                    version: $db_plugins_by_id[$plugin_id]->version ?? '',
                    desc: $lang->t('ERROR: THIS PLUGIN IS MISSING BUT IT IS INSTALLED! UNINSTALL IT NOW.'),
                    author: '',
                    authorUrl: null,
                    settingsUrl: '',
                    state: 'missing',
                );
                $count_types_plugins['missing']++;
            }
        }

        $adminContent = $renderer->render(new PluginsInstalledView(
            plugins: array_map(static fn (PluginListRow $plugin): array => $plugin->toArray(), $tpl_plugins),
            countTypesPlugins: $count_types_plugins,
            csrfToken: $pwg_token,
            showDetails: $show_details,
            isWebmaster: $accessControl->isWebmaster() ? 1 : 0,
            viewSelector: $preferencesService->getPluginManagerView() ?? 'classic',
            enableExtensionsInstall: $currentConfig->enableExtensionsInstall,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $lang->t('Plugins'),
        );
    }
}
