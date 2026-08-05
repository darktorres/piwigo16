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
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\PluginMigrationEntity;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsContext;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Users\UserService;

/**
 * P23 batch 8e-2: relocated from include/ws_functions/pwg.extensions.php.
 * `pwg.plugins.*`/`pwg.themes.performAction`/`pwg.extensions.*` WS methods
 * (6 registrations, all admin_only) -- registered via callable arrays in
 * include/ws_default_methods.inc.php.
 */
final class PwgExtensions
{
    public function __construct(
        private readonly Lang $lang,
        private readonly UrlServiceInterface $urlService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentTemplate $currentTemplate,
        private readonly AccessControl $accessControl,
        private readonly CurrentConfig $currentConfig,
        private readonly ConfigService $configService,
        private readonly ActivityService $activityService,
        private readonly UserService $userService,
        private readonly ExtensionUpdateChecker $extensionUpdateChecker,
        private readonly CoreUpdateService $coreUpdateService,
        private readonly RedirectServiceInterface $redirectService,
        private readonly PemCatalog $pemCatalog,
        private readonly WsContext $wsContext,
    ) {}

    /**
     * API method
     * Returns the list of all plugins
     *
     * @param array<string, mixed> $params this method is registered with a
     *   null signature (zero registered params) -- $params is the raw,
     *   entirely unvalidated request array, but the body doesn't read it.
     * @return list<array{id: string, name: string, version: string, state: string, description: string}>
     */
    public function pluginsGetList(array $params, PwgServer &$service): array
    {
        $urlService = $this->urlService;
        // ExtensionScanner::scan()'s own declared return type is a generic
        // array<string, array<string, mixed>> dispatch shape (by design --
        // see that method's own docblock), but every real entry for
        // ExtensionType::Plugin is actually scanPlugin()'s own precise shape.
        /** @var array<string, array{name: string, version: string, uri: string,
         *   description: string, author: string, hasSettings: bool,
         *   'author uri'?: string, extension?: string}> $fs_plugins
         */
        $fs_plugins = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService, $this->lang);
        uasort($fs_plugins, $this->htmlRenderer->nameCompare(...));
        $db_plugins_by_id = new ExtensionRepository(EntityManagerFactory::build(DbConnection::build()))->findAll(ExtensionType::Plugin);
        $plugin_list = [];

        foreach ($fs_plugins as $plugin_id => $fs_plugin) {
            if (isset($db_plugins_by_id[$plugin_id]) && is_string($db_plugins_by_id[$plugin_id]['state'])) {
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
    public function pluginsPerformAction(array $params, PwgServer &$service): PwgError|true
    {
        $template = $this->currentTemplate->get();

        /** @var Template $template */
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! $this->accessControl->isWebmaster()) {
            return new PwgError(403, $this->lang->t('Webmaster status is required.'));
        }

        if (! $this->currentConfig->enableExtensionsInstall() and $params['action'] === 'delete') {
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

        $urlService = $this->urlService;
        $conn = DbConnection::build();
        $lifecycle = new ExtensionLifecycle(
            $this->lang,
            new ExtensionRepository(EntityManagerFactory::build($conn)),
            $this->pemCatalog,
            $urlService,
            $this->configService,
            EntityManagerFactory::build($conn)->getRepository(PluginMigrationEntity::class),
            $this->activityService,
            $this->userService,
            $this->htmlRenderer,
            $this->currentConfig,
            $this->wsContext,
            $this->accessControl,
        );
        $fsEntry = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, $urlService, $this->lang)[$params['plugin']] ?? null;
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
    public function themesPerformAction(array $params, PwgServer &$service): PwgError|true
    {
        $template = $this->currentTemplate->get();

        /** @var Template $template */
        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! $this->currentConfig->enableExtensionsInstall() and $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }

        // P23 batch 8e-2: see pluginsPerformAction()'s own comment -- the
        // original define('IN_ADMIN', true) here is equally a no-op for
        // this request.

        $urlService = $this->urlService;
        $conn = DbConnection::build();
        $lifecycle = new ExtensionLifecycle(
            $this->lang,
            new ExtensionRepository(EntityManagerFactory::build($conn)),
            $this->pemCatalog,
            $urlService,
            $this->configService,
            EntityManagerFactory::build($conn)->getRepository(PluginMigrationEntity::class),
            $this->activityService,
            $this->userService,
            $this->htmlRenderer,
            $this->currentConfig,
            $this->wsContext,
            $this->accessControl,
        );
        $fsEntry = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $urlService, $this->lang)[$params['theme']] ?? null;
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
    public function update(array $params, PwgServer &$service): PwgError|string
    {
        if (! $this->currentConfig->enableExtensionsInstall()) {
            return new PwgError(401, 'Piwigo extensions install/update system is disabled');
        }

        if (! $this->accessControl->isWebmaster()) {
            return new PwgError(401, $this->lang->t('Webmaster status is required.'));
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

        $urlService = $this->urlService;
        $scanner = new ExtensionScanner();
        $conn = DbConnection::build();
        $repo = new ExtensionRepository(EntityManagerFactory::build($conn));
        $pemCatalog = $this->pemCatalog;
        $pluginMigrationRepo = EntityManagerFactory::build($conn)->getRepository(PluginMigrationEntity::class);
        $lifecycle = new ExtensionLifecycle($this->lang, $repo, $pemCatalog, $urlService, $this->configService, $pluginMigrationRepo, $this->activityService, $this->userService, $this->htmlRenderer, $this->currentConfig, $this->wsContext, $this->accessControl);

        if ($type === ExtensionType::Plugin) {
            $dbPluginsById = $repo->findAll(ExtensionType::Plugin);
            if (
                isset($dbPluginsById[$extension_id])
                and $dbPluginsById[$extension_id]['state'] === 'active'
            ) {
                $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang)[$extension_id] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'deactivate', $extension_id, $fsEntry);

                $this->redirectService
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

            $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang)[$extension_id] ?? null;
            $upgrade_status = $lifecycle->performAction(ExtensionType::Plugin, 'update', $extension_id, $fsEntry, [
                'revision' => $revision,
            ])[0] ?? null;
            $extension_name = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang)[$extension_id]['name'] ?? $extension_id;

            if (isset($params['reactivate'])) {
                $fsEntry = $scanner->scan(ExtensionType::Plugin, $urlService, $this->lang)[$extension_id] ?? null;
                $lifecycle->performAction(ExtensionType::Plugin, 'activate', $extension_id, $fsEntry);
            }
        } elseif ($type === ExtensionType::Theme) {
            $fsThemesBefore = $scanner->scan(ExtensionType::Theme, $urlService, $this->lang);
            $extension_name = $fsThemesBefore[$extension_id]['name'] ?? $extension_id;

            $extraction = $pemCatalog->extractArchive(ExtensionType::Theme, 'upgrade', $revision, $extension_id);
            $upgrade_status = $extraction['status'];

            $activity_details = [
                'theme_id' => $extension_id,
                'from_version' => $fsThemesBefore[$extension_id]['version'] ?? null,
            ];

            if ($upgrade_status === 'ok') {
                $fsThemesAfter = $scanner->scan(ExtensionType::Theme, $urlService, $this->lang); // refresh list
                $activity_details['to_version'] = $fsThemesAfter[$extension_id]['version'] ?? null;
            } else {
                $activity_details['result'] = 'error';
            }

            $this->activityService->record('system', ActivitySystem::Theme, 'update', $activity_details);
        } elseif ($type === ExtensionType::Language) {
            $extraction = $pemCatalog->extractArchive(ExtensionType::Language, 'upgrade', $revision, $extension_id);
            $upgrade_status = $extraction['status'];
            $extension_name = $scanner->scan(ExtensionType::Language, $urlService, $this->lang)[$extension_id]['name'] ?? $extension_id;
        } else {
            // Unreachable: $type is derived from $params['type'], already
            // restricted to plugins/themes/languages by the in_array()
            // guard above, and the 3 branches above exhaust every
            // ExtensionType case.
            throw new LogicException('Invalid extension type');
        }

        $template = $this->currentTemplate->get();

        /** @var Template $template */
        $template->delete_compiled_templates();

        return match ($upgrade_status) {
            'ok' => $this->lang->t('%s has been successfully updated.', $extension_name),
            'temp_path_error' => new PwgError(500, $this->lang->t('Can\'t create temporary file.')),
            'dl_archive_error' => new PwgError(500, $this->lang->t('Can\'t download archive.')),
            'archive_error' => new PwgError(500, $this->lang->t('Can\'t read or extract archive.')),
            default => new PwgError(500, $this->lang->t('An error occured during extraction (%s).', $upgrade_status)),
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
    public function ignoreUpdate(array $params, PwgServer &$service): PwgError|true
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

        if (! $this->accessControl->isWebmaster()) {
            return new PwgError(401, 'Access denied');
        }

        if (new CsrfService()->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        $updateChecker = $this->extensionUpdateChecker;
        $type = is_string($params['type']) ? ExtensionType::fromPluralWsParam($params['type']) : null;

        // Reset ignored extension
        if ($params['reset']) {
            if ($type !== null) {
                $updateChecker->resetIgnoredForType($type);
            } else {
                $updateChecker->resetAllIgnored();
            }

            unset($_SESSION['extensions_need_update']);
            return true;
        }

        if (in_array($params['id'], [null, ''], true) or $type === null) {
            return new PwgError(403, 'Invalid parameters');
        }

        // Add extension to ignore list -- ignoreUpdate() is itself an
        // idempotent upsert (see ExtensionIgnoredUpdateRepository::ignore()),
        // so no existence check is needed before calling it here, unlike
        // the former blob's own manual in_array() guard.
        $updateChecker->ignoreUpdate($type, $params['id']);

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
     * @return array{piwigo_need_update: bool|null, ext_need_update: bool|null}
     */
    public function checkUpdates(array $params, PwgServer &$service): array
    {
        $urlService = $this->urlService;
        $coreUpdateService = $this->coreUpdateService;
        $updateChecker = $this->extensionUpdateChecker;
        $result = [];

        if (! isset($_SESSION['need_update' . AppInfo::VERSION])) {
            $coreUpdateService->checkPiwigoUpgrade();
        }

        // CoreUpdateService::checkPiwigoUpgrade() only ever writes this
        // session key as null or a real bool (version_compare() result);
        // narrowed defensively since it's still a round-trip through
        // session state.
        $piwigo_need_update = $_SESSION['need_update' . AppInfo::VERSION] ?? null;
        $result['piwigo_need_update'] = is_bool($piwigo_need_update) ? $piwigo_need_update : null;

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
