<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Piwigo\Admin\Languages;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Admin\Updates;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;

final class ExtensionsEndpoints
{
    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function pluginsGetList(array $params, PwgServer $service): array
    {
        $plugins    = new Plugins();
        $plugins->sort_fs_plugins('name');
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
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!is_webmaster()) {
            return new PwgError(403, l10n('Webmaster status is required.'));
        }
        if (!Config::enableExtensionsInstall() && $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }
        define('IN_ADMIN', true);
        $plugins      = new Plugins();
        $pluginAction = is_string($params['action']) ? $params['action'] : '';
        $pluginId     = is_string($params['plugin']) ? $params['plugin'] : '';
        $errors       = $plugins->perform_action($pluginAction, $pluginId);
        if (!empty($errors)) {
            return new PwgError(500, implode(', ', array_map(fn (mixed $e): string => is_scalar($e) ? (string) $e : '', is_array($errors) ? $errors : [])));
        }
        if (in_array($pluginAction, ['activate', 'deactivate'])) {
            $template->delete_compiled_templates();
        }
        return true;
    }

    /** @param array<mixed> $params */
    public function themesPerformAction(array $params, PwgServer $service): PwgError|true
    {
        $template = TemplateRegistry::current();
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!Config::enableExtensionsInstall() && $params['action'] === 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }
        define('IN_ADMIN', true);
        $themes      = new Themes();
        $themeAction = is_string($params['action']) ? $params['action'] : '';
        $themeId     = is_string($params['theme']) ? $params['theme'] : '';
        $errors      = $themes->perform_action($themeAction, $themeId);
        if (!empty($errors)) {
            return new PwgError(500, implode(', ', array_map(fn (mixed $e): string => is_scalar($e) ? (string) $e : '', $errors)));
        }
        if (in_array($themeAction, ['activate', 'deactivate'])) {
            $template->delete_compiled_templates();
        }
        return true;
    }

    /** @param array<mixed> $params */
    public function update(array $params, PwgServer $service): mixed
    {
        if (!Config::enableExtensionsInstall()) {
            return new PwgError(401, 'Piwigo extensions install/update system is disabled');
        }
        if (!is_webmaster()) {
            return new PwgError(401, l10n('Webmaster status is required.'));
        }
        if (get_pwg_token() !== $params['pwg_token']) {
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
            $extension = new Plugins();
            if (isset($extension->db_plugins_by_id[$extensionId]) && $extension->db_plugins_by_id[$extensionId]['state'] === 'active') {
                $extension->perform_action('deactivate', $extensionId);
                redirect(ServiceLocator::get(UrlGenerator::class)->ws(['method' => 'pwg.extensions.update', 'type' => 'plugins', 'id' => $extensionId, 'revision' => $revision, 'reactivate' => 'true', 'pwg_token' => get_pwg_token(), 'format' => 'json']));
            }
            $performResult = $extension->perform_action('update', $extensionId, ['revision' => $revision]);
            $upgradeStatus = is_array($performResult) ? ($performResult[0] ?? 'ok') : 'ok';
            $upgradeStatus = is_string($upgradeStatus) ? $upgradeStatus : 'ok';
            $extensionName = is_string($extension->fs_plugins[$extensionId]['name'] ?? null) ? $extension->fs_plugins[$extensionId]['name'] : '';
            if (isset($params['reactivate'])) {
                $extension->perform_action('activate', $extensionId);
            }
        } elseif ($type === 'themes') {
            $extension      = new Themes();
            $upgradeStatus  = $extension->extract_theme_files('upgrade', $revision, $extensionId);
            $extensionName  = is_string($extension->fs_themes[$extensionId]['name'] ?? null) ? $extension->fs_themes[$extensionId]['name'] : '';
            $fromVersion    = is_string($extension->fs_themes[$extensionId]['version'] ?? null) ? $extension->fs_themes[$extensionId]['version'] : '';
            $activityDetails = ['theme_id' => $extensionId, 'from_version' => $fromVersion];
            if ($upgradeStatus === 'ok') {
                $extension->get_fs_themes();
                $activityDetails['to_version'] = is_string($extension->fs_themes[$extensionId]['version'] ?? null) ? $extension->fs_themes[$extensionId]['version'] : '';
            } else {
                $activityDetails['result'] = 'error';
            }
            pwg_activity('system', ACTIVITY_SYSTEM_THEME, 'update', $activityDetails);
        } elseif ($type === 'languages') {
            $extension     = new Languages();
            $upgradeStatus = $extension->extract_language_files('upgrade', $revision, $extensionId);
            $extensionName = is_string($extension->fs_languages[$extensionId]['name'] ?? null) ? $extension->fs_languages[$extensionId]['name'] : '';
        }
        TemplateRegistry::current()->delete_compiled_templates();
        return match ($upgradeStatus) {
            'ok'               => l10n('%s has been successfully updated.', $extensionName),
            'temp_path_error'  => new PwgError(null, l10n('Can\'t create temporary file.')),
            'dl_archive_error' => new PwgError(null, l10n('Can\'t download archive.')),
            'archive_error'    => new PwgError(null, l10n('Can\'t read or extract archive.')),
            default            => new PwgError(null, l10n('An error occured during extraction (%s).', $upgradeStatus)),
        };
    }

    /** @param array<mixed> $params */
    public function ignoreUpdate(array $params, PwgServer $service): PwgError|true
    {
        define('IN_ADMIN', true);
        if (!is_webmaster()) {
            return new PwgError(401, 'Access denied');
        }
        if (get_pwg_token() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        $updatesIgnoredRaw          = Config::raw('updates_ignored');
        $updatesIgnoredUnserialized = is_string($updatesIgnoredRaw) ? unserialize($updatesIgnoredRaw) : false;
        Config::override('updates_ignored', is_array($updatesIgnoredUnserialized) ? $updatesIgnoredUnserialized : []);
        if ($params['reset']) {
            $updatesIgnored = Config::raw('updates_ignored');
            $ignoreType     = is_string($params['type'] ?? null) ? $params['type'] : '';
            if (!empty($ignoreType) && is_array($updatesIgnored) && isset($updatesIgnored[$ignoreType])) {
                $updatesIgnored[$ignoreType] = [];
                Config::override('updates_ignored', $updatesIgnored);
            } else {
                Config::override('updates_ignored', ['plugins' => [], 'themes' => [], 'languages' => []]);
            }
            conf_update_param('updates_ignored', serialize(Config::raw('updates_ignored')));
            unset($_SESSION['extensions_need_update']);
            return true;
        }
        $ignoreId    = is_string($params['id'] ?? null) ? $params['id'] : '';
        $ignoreType2 = is_string($params['type'] ?? null) ? $params['type'] : '';
        if (empty($ignoreId) || empty($ignoreType2) || !in_array($ignoreType2, ['plugins', 'themes', 'languages'])) {
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
        conf_update_param('updates_ignored', serialize(Config::raw('updates_ignored')));
        unset($_SESSION['extensions_need_update']);
        return true;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function checkUpdates(array $params, PwgServer $service): array
    {
        $update  = new Updates();
        $result  = [];
        if (!isset($_SESSION['need_update' . PHPWG_VERSION])) {
            $update->check_piwigo_upgrade();
        }
        $result['piwigo_need_update'] = $_SESSION['need_update' . PHPWG_VERSION];
        $cuUpdatesIgnoredRaw = Config::raw('updates_ignored');
        $cuUpdatesIgnored    = is_string($cuUpdatesIgnoredRaw) ? unserialize($cuUpdatesIgnoredRaw) : false;
        Config::override('updates_ignored', is_array($cuUpdatesIgnored) ? $cuUpdatesIgnored : []);
        $update->check_extensions();
        $result['ext_need_update'] = is_array($_SESSION['extensions_need_update']) ? !empty($_SESSION['extensions_need_update']) : null;
        return $result;
    }
}
