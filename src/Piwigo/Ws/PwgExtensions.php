<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use LogicException;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Auth\AccessControl;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Lang;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Html\HtmlService;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;

/**
 * P23 batch 8e-2: relocated from include/ws_functions/pwg.extensions.php.
 * `pwg.plugins.*`/`pwg.themes.performAction`/`pwg.extensions.*` WS methods
 * (6 registrations, all admin_only) -- registered via callable arrays in
 * include/ws_default_methods.inc.php.
 */
final class PwgExtensions
{
    /**
     * API method
     * Returns the list of all plugins
     *
     * @param array<string, mixed> $params this method is registered with a
     *   null signature (zero registered params) -- $params is the raw,
     *   entirely unvalidated request array, but the body doesn't read it.
     * @return array{id: mixed, name: mixed, version: mixed, state: mixed, description: mixed}[]
     */
    public static function pluginsGetList(array $params, PwgServer &$service): array
    {
        $urlService = new UrlService(new HtmlService());
        $fs_plugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService);
        uasort($fs_plugins, new HtmlService()->nameCompare(...));
        $db_plugins_by_id = new ExtensionRepository(DbConnection::build())->findAll(ExtensionType::Plugin);
        $plugin_list = [];

        foreach ($fs_plugins as $plugin_id => $fs_plugin) {
            if (isset($db_plugins_by_id[$plugin_id])) {
                $state = $db_plugins_by_id[$plugin_id]['state'];
            } else {
                $state = 'uninstalled';
            }

            $plugin_list[] = [
                'id' => $plugin_id,
                'name' => $fs_plugin['name'],
                'version' => $fs_plugin['version'],
                'state' => $state,
                'description' => $fs_plugin['description'],
            ];
        }

        return $plugin_list;
    }

    /**
     * API method
     * Performs an action on a plugin
     *
     * @param array{action: string, plugin: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, no 'type'
     *   flag.
     */
    public static function pluginsPerformAction(array $params, PwgServer &$service): PwgError|true
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        /** @var Template $template */
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! AccessControl::isWebmaster()) {
            return new PwgError(403, Lang::t('Webmaster status is required.'));
        }

        if (! \Piwigo\Config\CurrentConfig::enableExtensionsInstall() and $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }

        // P23 batch 8e-2: the original define('IN_ADMIN', true) here has no
        // effect on this request -- Template::__construct() (the only
        // reader that matters mid-request) already ran during
        // common.inc.php's bootstrap, long before this WS callback
        // executes; delete_compiled_templates() below and plugins::
        // perform_action() never read IN_ADMIN themselves (verified via
        // grep). Dropping it also avoids a real SEC-60 (no define() under
        // src/Piwigo/) arch-test violation.

        $urlService = new UrlService(new HtmlService());
        $lifecycle = new ExtensionLifecycle(
            new ExtensionRepository(DbConnection::build()),
            new PemCatalog(new ZipExtractor()),
            $urlService,
            CurrentConfigService::get(),
        );
        $fsEntry = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService)[$params['plugin']] ?? null;
        $errors = $lifecycle->performAction(ExtensionType::Plugin, $params['action'], $params['plugin'], $fsEntry);

        if ($errors !== []) {
            return new PwgError(500, implode(', ', array_filter($errors, is_string(...))));
        } else {
            if (in_array($params['action'], ['activate', 'deactivate'], true)) {
                $template->delete_compiled_templates();
            }
            return true;
        }
    }

    /**
     * API method
     * Performs an action on a theme
     *
     * @param array{action: string, theme: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, no 'type'
     *   flag.
     */
    public static function themesPerformAction(array $params, PwgServer &$service): PwgError|true
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        /** @var Template $template */
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! \Piwigo\Config\CurrentConfig::enableExtensionsInstall() and $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }

        // P23 batch 8e-2: see pluginsPerformAction()'s own comment -- the
        // original define('IN_ADMIN', true) here is equally a no-op for
        // this request.

        $urlService = new UrlService(new HtmlService());
        $lifecycle = new ExtensionLifecycle(
            new ExtensionRepository(DbConnection::build()),
            new PemCatalog(new ZipExtractor()),
            $urlService,
            CurrentConfigService::get(),
        );
        $fsEntry = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $urlService)[$params['theme']] ?? null;
        $errors = $lifecycle->performAction(ExtensionType::Theme, $params['action'], $params['theme'], $fsEntry);

        if ($errors !== []) {
            return new PwgError(500, implode(', ', $errors));
        } else {
            if (in_array($params['action'], ['activate', 'deactivate'], true)) {
                $template->delete_compiled_templates();
            }
            return true;
        }
    }

    /**
     * API method
     * Updates an extension
     *
     * @param array{type: string, id: string, revision: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, no 'type'
     *   flag. reactivate: not a registered param at all (checked in the body
     *   via isset($params['reactivate']), reachable only through the
     *   self-redirect a few lines below that appends it as a raw extra query
     *   param) -- covered by the shape's open tail, never explicitly typed.
     */
    public static function update(array $params, PwgServer &$service): PwgError|string
    {
        if (! \Piwigo\Config\CurrentConfig::enableExtensionsInstall()) {
            return new PwgError(401, 'Piwigo extensions install/update system is disabled');
        }

        if (! AccessControl::isWebmaster()) {
            return new PwgError(401, Lang::t('Webmaster status is required.'));
        }

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! in_array($params['type'], ['plugins', 'themes', 'languages'], true)) {
            return new PwgError(403, 'invalid extension type');
        }

        $extension_id = $params['id'];
        $revision = $params['revision'];

        $type = match ($params['type']) {
            'plugins' => ExtensionType::Plugin,
            'themes' => ExtensionType::Theme,
            // 'languages' is the only value that can still reach here: $type
            // is already restricted to plugins/themes/languages by the
            // in_array() guard above.
            default => ExtensionType::Language,
        };

        $urlService = new UrlService(new HtmlService());
        $scanner = new ExtensionScanner();
        $repo = new ExtensionRepository(DbConnection::build());
        $pemCatalog = new PemCatalog(new ZipExtractor());
        $lifecycle = new ExtensionLifecycle($repo, $pemCatalog, $urlService, CurrentConfigService::get());

        if ($type === ExtensionType::Plugin) {
            $dbPluginsById = $repo->findAll(ExtensionType::Plugin);
            if (
                isset($dbPluginsById[$extension_id])
                and $dbPluginsById[$extension_id]['state'] === 'active'
            ) {
                $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService)[$extension_id] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $extension_id, $fsEntry);

                new RedirectService()
                    ->redirect(
                        $urlService->getRootUrl()
                                . 'ws.php'
                                . '?method=pwg.extensions.update'
                                . '&type=plugins'
                                . '&id=' . $extension_id
                                . '&revision=' . $revision
                                . '&reactivate=true'
                                . '&pwg_token=' . new CsrfService()->getToken()
                                . '&format=json'
                    );
            }

            $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService)[$extension_id] ?? null;
            $upgrade_status = $lifecycle->performAction(ExtensionType::Plugin, 'update', $extension_id, $fsEntry, [
                'revision' => $revision,
            ])[0] ?? null;
            $extension_name = $scanner->scan(ExtensionType::Plugin, $urlService)[$extension_id]['name'] ?? $extension_id;

            if (isset($params['reactivate'])) {
                $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService)[$extension_id] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'activate', $extension_id, $fsEntry);
            }
        } elseif ($type === ExtensionType::Theme) {
            $fsThemesBefore = $scanner->scan(ExtensionType::Theme, $urlService);
            $extension_name = $fsThemesBefore[$extension_id]['name'] ?? $extension_id;

            $extraction = $pemCatalog->extractArchive(ExtensionType::Theme, 'upgrade', $revision, $extension_id);
            $upgrade_status = $extraction['status'];

            $activity_details = [
                'theme_id' => $extension_id,
                'from_version' => $fsThemesBefore[$extension_id]['version'] ?? null,
            ];

            if ($upgrade_status === 'ok') {
                $fsThemesAfter = $scanner->scan(ExtensionType::Theme, $urlService); // refresh list
                $activity_details['to_version'] = $fsThemesAfter[$extension_id]['version'] ?? null;
            } else {
                $activity_details['result'] = 'error';
            }

            new ActivityService(new ActivityRepository(DbConnection::build()))->record('system', ActivitySystem::Theme, 'update', $activity_details);
        } elseif ($type === ExtensionType::Language) {
            $extraction = $pemCatalog->extractArchive(ExtensionType::Language, 'upgrade', $revision, $extension_id);
            $upgrade_status = $extraction['status'];
            $extension_name = $scanner->scan(ExtensionType::Language, $urlService)[$extension_id]['name'] ?? $extension_id;
        } else {
            // Unreachable: $type is derived from $params['type'], already
            // restricted to plugins/themes/languages by the in_array()
            // guard above, and the 3 branches above exhaust every
            // ExtensionType case.
            throw new LogicException('Invalid extension type');
        }

        $template = \Piwigo\Template\CurrentTemplate::get();

        /** @var Template $template */
        $template->delete_compiled_templates();

        return match ($upgrade_status) {
            'ok' => Lang::t('%s has been successfully updated.', $extension_name),
            'temp_path_error' => new PwgError(500, Lang::t('Can\'t create temporary file.')),
            'dl_archive_error' => new PwgError(500, Lang::t('Can\'t download archive.')),
            'archive_error' => new PwgError(500, Lang::t('Can\'t read or extract archive.')),
            default => new PwgError(500, Lang::t('An error occured during extraction (%s).', $upgrade_status)),
        };
    }

    /**
     * API method
     *
     * @param array{type: string|null, id: string|null, reset: bool, pwg_token: string, ...} $params
     *   type/id: null default, no 'type' flag -- always present, string|null.
     *   reset: non-null bool default, WsParamType::BOOL -- always present.
     *   pwg_token: no 'default' key -- mandatory, always present.
     */
    public static function ignoreUpdate(array $params, PwgServer &$service): PwgError|true
    {
        // P23 batch 8e-2: the original define('IN_ADMIN', true)+
        // include_once admin/include/functions.php here are both dropped --
        // IN_ADMIN has no reader left in this request's lifecycle (see
        // pluginsPerformAction()'s own comment), and
        // admin/include/functions.php has been emptied of all functions
        // since P23 batch 8d (the config write below went through
        // \Piwigo\Config\ConfigDb::confUpdateParam() since P23 batch 8f-4,
        // which deleted include/functions.inc.php entirely; Phase 5
        // Legacy Coupling Retirement retargeted it onto CurrentConfigService).

        if (! AccessControl::isWebmaster()) {
            return new PwgError(401, 'Access denied');
        }

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $updates_ignored = \Piwigo\Config\CurrentConfig::updatesIgnored();

        // Reset ignored extension
        if ($params['reset']) {
            if (! in_array($params['type'], [null, ''], true) and isset($updates_ignored[$params['type']])) {
                $updates_ignored[$params['type']] = [];
            } else {
                $updates_ignored = [
                    'plugins' => [],
                    'themes' => [],
                    'languages' => [],
                ];
            }
            \Piwigo\Config\CurrentConfig::setUpdatesIgnored($updates_ignored);

            \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('updates_ignored', serialize($updates_ignored));
            unset($_SESSION['extensions_need_update']);
            return true;
        }

        if (in_array($params['id'], [null, ''], true) or in_array($params['type'], [null, ''], true) or ! in_array($params['type'], ['plugins', 'themes', 'languages'], true)) {
            return new PwgError(403, 'Invalid parameters');
        }

        // Add or remove extension from ignore list
        if (! in_array($params['id'], $updates_ignored[$params['type']], true)) {
            $updates_ignored[$params['type']][] = $params['id'];
        }

        \Piwigo\Config\CurrentConfig::setUpdatesIgnored($updates_ignored);
        \Piwigo\Config\CurrentConfigService::get()->confUpdateParam('updates_ignored', serialize($updates_ignored));
        unset($_SESSION['extensions_need_update']);
        return true;
    }

    /**
     * API method
     * Checks for updates (core and extensions)
     *
     * @param array<string, mixed> $params this method is registered with a
     *   null signature (zero registered params) -- $params is the raw,
     *   entirely unvalidated request array, but the body doesn't read it.
     * @return array{piwigo_need_update: mixed, ext_need_update: bool|null}
     */
    public static function checkUpdates(array $params, PwgServer &$service): array
    {
        $urlService = new UrlService(new HtmlService());
        $coreUpdateService = new CoreUpdateService(new ZipExtractor(), new RedirectService(), $urlService, CurrentConfigService::get(), \Piwigo\Core\CurrentPaths::get());
        $updateChecker = new ExtensionUpdateChecker(new ExtensionScanner(), new PemCatalog(new ZipExtractor()), $urlService, CurrentConfigService::get());
        $result = [];

        if (! isset($_SESSION['need_update' . AppInfo::VERSION])) {
            $coreUpdateService->checkPiwigoUpgrade();
        }

        $result['piwigo_need_update'] = $_SESSION['need_update' . AppInfo::VERSION] ?? null;

        if (! isset($_SESSION['extensions_need_update'])) {
            $updateChecker->checkExtensions();
        } else {
            $updateChecker->checkUpdatedExtensions();
        }

        if (! is_array($_SESSION['extensions_need_update'] ?? null)) {
            $result['ext_need_update'] = null;
        } else {
            $result['ext_need_update'] = $_SESSION['extensions_need_update'] !== [];
        }

        return $result;
    }
}
