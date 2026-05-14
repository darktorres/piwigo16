<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Piwigo\Admin\Languages;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Util;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Users\PermissionService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

final readonly class ExtensionsEndpoints
{
    public function __construct(
        private ConfigService $configService,
        private PermissionService $permissionService,
        private UrlGenerator $urlGenerator,
        private Util $util,
    ) {
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function pluginsGetList(array $params, PwgServer $service): array
    {
        $plugins    = Kernel::service(Plugins::class);
        $plugins->sortFsPlugins('name');
        $pluginList = [];
        foreach ($plugins->fs_plugins as $pluginId => $fsPlugin) {
            $state        = isset($plugins->db_plugins_by_id[$pluginId]) ? $plugins->db_plugins_by_id[$pluginId]['state'] : 'uninstalled';
            $pluginList[] = ['id' => $pluginId, 'name' => $fsPlugin['name'], 'version' => $fsPlugin['version'], 'state' => $state, 'description' => $fsPlugin['description']];
        }
        return $pluginList;
    }

    /** @param array<mixed> $params */
    public function pluginsPerformAction(array $params, PwgServer $service): PwgError|true
    {
        $template = TemplateRegistry::current();
        if ($this->util->getPwgToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(403, Lang::t('Webmaster status is required.'));
        }
        if (!Config::enableExtensionsInstall() && $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }
        define('IN_ADMIN', true);
        $plugins      = Kernel::service(Plugins::class);
        $pluginAction = is_string($params['action']) ? $params['action'] : '';
        $pluginId     = is_string($params['plugin']) ? $params['plugin'] : '';
        $errors       = $plugins->performAction($pluginAction, $pluginId);
        if (!empty($errors)) {
            return new PwgError(500, implode(', ', array_map(fn (mixed $e): string => is_scalar($e) ? (string) $e : '', is_array($errors) ? $errors : [])));
        }
        if (in_array($pluginAction, ['activate', 'deactivate'])) {
            $template->deleteCompiledTemplates();
        }
        return true;
    }

    /** @param array<mixed> $params */
    public function themesPerformAction(array $params, PwgServer $service): PwgError|true
    {
        $template = TemplateRegistry::current();
        if ($this->util->getPwgToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!Config::enableExtensionsInstall() && $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }
        define('IN_ADMIN', true);
        $themes      = Kernel::service(Themes::class);
        $themeAction = is_string($params['action']) ? $params['action'] : '';
        $themeId     = is_string($params['theme']) ? $params['theme'] : '';
        $errors      = $themes->performAction($themeAction, $themeId);
        if (!empty($errors)) {
            return new PwgError(500, implode(', ', array_map(fn (mixed $e): string => is_scalar($e) ? (string) $e : '', $errors)));
        }
        if (in_array($themeAction, ['activate', 'deactivate'])) {
            $template->deleteCompiledTemplates();
        }
        return true;
    }

    /** @param array<mixed> $params */
    public function update(array $params, PwgServer $service): mixed
    {
        if (!Config::enableExtensionsInstall()) {
            return new PwgError(401, 'Piwigo extensions install/update system is disabled');
        }
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(401, Lang::t('Webmaster status is required.'));
        }
        if ($this->util->getPwgToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!in_array($params['type'], ['plugins', 'themes', 'languages'])) {
            return new PwgError(403, 'invalid extension type');
        }
        $type          = is_string($params['type']) ? $params['type'] : '';
        $extensionId   = is_string($params['id']) ? $params['id'] : '';
        $revision      = is_string($params['revision']) ? $params['revision'] : '';
        $upgradeStatus = 'ok';
        $extensionName = '';
        if ($type === 'plugins') {
            $extension = Kernel::service(Plugins::class);
            if (isset($extension->db_plugins_by_id[$extensionId]) && $extension->db_plugins_by_id[$extensionId]['state'] === 'active') {
                $extension->performAction('deactivate', $extensionId);
                $this->util->redirect($this->urlGenerator->ws(['method' => 'pwg.extensions.update', 'type' => 'plugins', 'id' => $extensionId, 'revision' => $revision, 'reactivate' => 'true', 'pwg_token' => $this->util->getPwgToken(), 'format' => 'json']));
            }
            $performResult = $extension->performAction('update', $extensionId, ['revision' => $revision]);
            $upgradeStatus = is_array($performResult) ? ($performResult[0] ?? 'ok') : 'ok';
            $upgradeStatus = is_string($upgradeStatus) ? $upgradeStatus : 'ok';
            $extensionName = is_string($extension->fs_plugins[$extensionId]['name'] ?? null) ? $extension->fs_plugins[$extensionId]['name'] : '';
            if (isset($params['reactivate'])) {
                $extension->performAction('activate', $extensionId);
            }
        } elseif ($type === 'themes') {
            $extension      = Kernel::service(Themes::class);
            $upgradeStatus  = $extension->extractThemeFiles('upgrade', $revision, $extensionId);
            $extensionName  = is_string($extension->fs_themes[$extensionId]['name'] ?? null) ? $extension->fs_themes[$extensionId]['name'] : '';
            $fromVersion    = is_string($extension->fs_themes[$extensionId]['version'] ?? null) ? $extension->fs_themes[$extensionId]['version'] : '';
            $activityDetails = ['theme_id' => $extensionId, 'from_version' => $fromVersion];
            if ($upgradeStatus === 'ok') {
                $extension->getFsThemes();
                $activityDetails['to_version'] = is_string($extension->fs_themes[$extensionId]['version'] ?? null) ? $extension->fs_themes[$extensionId]['version'] : '';
            } else {
                $activityDetails['result'] = 'error';
            }
            $this->util->pwgActivity('system', ActivitySystem::Theme, 'update', $activityDetails);
        } elseif ($type === 'languages') {
            $extension     = Kernel::service(Languages::class);
            $upgradeStatus = $extension->extractLanguageFiles('upgrade', $revision, $extensionId);
            $extensionName = is_string($extension->fs_languages[$extensionId]['name'] ?? null) ? $extension->fs_languages[$extensionId]['name'] : '';
        }
        TemplateRegistry::current()->deleteCompiledTemplates();
        return match ($upgradeStatus) {
            'ok'               => Lang::t('%s has been successfully updated.', $extensionName),
            'temp_path_error'  => new PwgError(null, Lang::t('Can\'t create temporary file.')),
            'dl_archive_error' => new PwgError(null, Lang::t('Can\'t download archive.')),
            'archive_error'    => new PwgError(null, Lang::t('Can\'t read or extract archive.')),
            default            => new PwgError(null, Lang::t('An error occured during extraction (%s).', $upgradeStatus)),
        };
    }

    /** @param array<mixed> $params */
    public function ignoreUpdate(array $params, PwgServer $service): PwgError|true
    {
        define('IN_ADMIN', true);
        if (!$this->permissionService->isWebmaster()) {
            return new PwgError(401, 'Access denied');
        }
        if ($this->util->getPwgToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $updatesIgnoredRaw          = Config::raw('updates_ignored');
        $updatesIgnoredUnserialized = is_string($updatesIgnoredRaw) ? unserialize($updatesIgnoredRaw) : false;
        Config::override('updates_ignored', is_array($updatesIgnoredUnserialized) ? $updatesIgnoredUnserialized : []);
        if ($params['reset']) {
            $updatesIgnored = Config::raw('updates_ignored');
            $typeRaw1       = $params['type'] ?? null;
            $ignoreType     = is_string($typeRaw1) ? $typeRaw1 : '';
            if ($ignoreType !== '' && is_array($updatesIgnored) && isset($updatesIgnored[$ignoreType])) {
                $updatesIgnored[$ignoreType] = [];
                Config::override('updates_ignored', $updatesIgnored);
            } else {
                Config::override('updates_ignored', ['plugins' => [], 'themes' => [], 'languages' => []]);
            }
            $this->configService->confUpdateParam('updates_ignored', serialize(Config::raw('updates_ignored')));
            unset($_SESSION['extensions_need_update']);
            return true;
        }
        $idRaw       = $params['id'] ?? null;
        $ignoreId    = is_string($idRaw) ? $idRaw : '';
        $typeRaw2    = $params['type'] ?? null;
        $ignoreType2 = is_string($typeRaw2) ? $typeRaw2 : '';
        if ($ignoreId === '' || $ignoreType2 === '' || !in_array($ignoreType2, ['plugins', 'themes', 'languages'])) {
            return new PwgError(403, 'Invalid parameters');
        }
        $ignoredCfgRaw     = Config::raw('updates_ignored');
        $ignoredCfg        = is_array($ignoredCfgRaw) ? $ignoredCfgRaw : [];
        $ignoredForType    = is_array($ignoredCfg[$ignoreType2] ?? null) ? $ignoredCfg[$ignoreType2] : [];
        if (!in_array($ignoreId, $ignoredForType)) {
            $ignoredForType[]       = $ignoreId;
            $ignoredCfg[$ignoreType2] = $ignoredForType;
            Config::override('updates_ignored', $ignoredCfg);
        }
        $this->configService->confUpdateParam('updates_ignored', serialize(Config::raw('updates_ignored')));
        unset($_SESSION['extensions_need_update']);
        return true;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function checkUpdates(array $params, PwgServer $service): array
    {
        $update  = Kernel::service(Updates::class);
        $result  = [];
        if (!isset($_SESSION['need_update' . AppInfo::VERSION])) {
            $update->checkPiwigoUpgrade();
        }
        $result['piwigo_need_update'] = $_SESSION['need_update' . AppInfo::VERSION] ?? null;
        $cuUpdatesIgnoredRaw = Config::raw('updates_ignored');
        $cuUpdatesIgnored    = is_string($cuUpdatesIgnoredRaw) ? unserialize($cuUpdatesIgnoredRaw) : false;
        Config::override('updates_ignored', is_array($cuUpdatesIgnored) ? $cuUpdatesIgnored : []);
        $update->checkExtensions();
        $result['ext_need_update'] = is_array($_SESSION['extensions_need_update']) ? !empty($_SESSION['extensions_need_update']) : null;
        return $result;
    }
}
