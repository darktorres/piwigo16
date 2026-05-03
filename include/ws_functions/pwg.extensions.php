<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Admin\Updates;
use Piwigo\Ws\PwgError;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * API method
 * Returns the list of all plugins
 * @param mixed[] $params
 * @return array{id: mixed, name: mixed, version: mixed, state: mixed, description: mixed}[]
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>
 */function ws_plugins_getList(array $params, \Piwigo\Ws\PwgServer $service): array
{
    $plugins = new Plugins();
    $plugins->sort_fs_plugins('name');
    $plugin_list = [];

    foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
        if (isset($plugins->db_plugins_by_id[$plugin_id])) {
            $state = $plugins->db_plugins_by_id[$plugin_id]['state'];
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
 * @param mixed[] $params
 *    @option string action
 *    @option string plugin
 *    @option string pwg_token
 */
/** @param array<mixed> $params */
function ws_plugins_performAction(array $params, \Piwigo\Ws\PwgServer $service): PwgError|true
{
    global $template;

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (!is_webmaster()) {
        return new PwgError(403, l10n('Webmaster status is required.'));
    }

    if (!\Piwigo\Config\Config::enableExtensionsInstall() and 'delete' == $params['action']) {
        return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
    }

    define('IN_ADMIN', true);

    $plugins = new Plugins();
    $plugin_action = is_string($params['action']) ? $params['action'] : '';
    $plugin_id = is_string($params['plugin']) ? $params['plugin'] : '';
    $errors = $plugins->perform_action($plugin_action, $plugin_id);

    if (!empty($errors)) {
        return new PwgError(500, implode(', ', array_map(fn ($e) => is_scalar($e) ? (string) $e : '', is_array($errors) ? $errors : [])));
    } else {
        if (in_array($plugin_action, ['activate', 'deactivate'])) {
            $template->delete_compiled_templates();
        }
        return true;
    }
}

/**
 * API method
 * Performs an action on a theme
 * @param mixed[] $params
 *    @option string action
 *    @option string theme
 *    @option string pwg_token
 */
/** @param array<mixed> $params */
function ws_themes_performAction(array $params, \Piwigo\Ws\PwgServer $service): PwgError|true
{
    global $template;

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (!\Piwigo\Config\Config::enableExtensionsInstall() and 'delete' == $params['action']) {
        return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
    }

    define('IN_ADMIN', true);

    $themes = new Themes();
    $theme_action = is_string($params['action']) ? $params['action'] : '';
    $theme_id = is_string($params['theme']) ? $params['theme'] : '';
    $errors = $themes->perform_action($theme_action, $theme_id);

    if (!empty($errors)) {
        return new PwgError(500, implode(', ', array_map(fn ($e) => is_scalar($e) ? (string) $e : '', $errors)));
    } else {
        if (in_array($theme_action, ['activate', 'deactivate'])) {
            $template->delete_compiled_templates();
        }
        return true;
    }
}

/**
 * API method
 * Updates an extension
 * @param mixed[] $params
 *    @option string type
 *    @option string id
 *    @option string revision
 *    @option string pwg_token
 *    @option bool reactivate (optional - undocumented)
 */
/** @param array<mixed> $params */
function ws_extensions_update(array $params, \Piwigo\Ws\PwgServer $service): mixed
{
    if (!\Piwigo\Config\Config::enableExtensionsInstall()) {
        return new PwgError(401, 'Piwigo extensions install/update system is disabled');
    }

    if (!is_webmaster()) {
        return new PwgError(401, l10n('Webmaster status is required.'));
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (!in_array($params['type'], ['plugins', 'themes', 'languages'])) {
        return new PwgError(403, 'invalid extension type');
    }

    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $type = is_string($params['type']) ? $params['type'] : '';
    $extension_id = is_string($params['id']) ? $params['id'] : '';
    $revision = is_string($params['revision']) ? $params['revision'] : '';

    $upgrade_status = 'ok';
    $extension_name = '';

    if ($type == 'plugins') {
        $extension = new \Piwigo\Admin\Plugins();
        if (
            isset($extension->db_plugins_by_id[$extension_id])
            and $extension->db_plugins_by_id[$extension_id]['state'] == 'active'
        ) {
            $extension->perform_action('deactivate', $extension_id);

            redirect(
                PHPWG_ROOT_PATH
        . 'ws.php'
        . '?method=pwg.extensions.update'
        . '&type=plugins'
        . '&id=' . $extension_id
        . '&revision=' . $revision
        . '&reactivate=true'
        . '&pwg_token=' . get_pwg_token()
        . '&format=json'
            );
        }

        $perform_result = $extension->perform_action('update', $extension_id, ['revision' => $revision]);
        $upgrade_status = is_array($perform_result) ? ($perform_result[0] ?? 'ok') : 'ok';
        $upgrade_status = is_string($upgrade_status) ? $upgrade_status : 'ok';
        $extension_name = is_string($extension->fs_plugins[$extension_id]['name'] ?? null) ? $extension->fs_plugins[$extension_id]['name'] : '';

        if (isset($params['reactivate'])) {
            $extension->perform_action('activate', $extension_id);
        }
    } elseif ($type == 'themes') {
        $extension = new \Piwigo\Admin\Themes();
        $upgrade_status = $extension->extract_theme_files('upgrade', $revision, $extension_id);
        $extension_name = is_string($extension->fs_themes[$extension_id]['name'] ?? null) ? $extension->fs_themes[$extension_id]['name'] : '';

        $from_version = is_string($extension->fs_themes[$extension_id]['version'] ?? null) ? $extension->fs_themes[$extension_id]['version'] : '';
        $activity_details = ['theme_id' => $extension_id, 'from_version' => $from_version];

        if ('ok' == $upgrade_status) {
            $extension->get_fs_themes(); // refresh list
            $activity_details['to_version'] = is_string($extension->fs_themes[$extension_id]['version'] ?? null) ? $extension->fs_themes[$extension_id]['version'] : '';
        } else {
            $activity_details['result'] = 'error';
        }

        pwg_activity('system', ACTIVITY_SYSTEM_THEME, 'update', $activity_details);
    } elseif ($type == 'languages') {
        $extension = new \Piwigo\Admin\Languages();
        $upgrade_status = $extension->extract_language_files('upgrade', $revision, $extension_id);
        $extension_name = is_string($extension->fs_languages[$extension_id]['name'] ?? null) ? $extension->fs_languages[$extension_id]['name'] : '';
    }

    global $template;
    $template->delete_compiled_templates();

    return match ($upgrade_status) {
        'ok' => l10n('%s has been successfully updated.', $extension_name),
        'temp_path_error' => new PwgError(null, l10n('Can\'t create temporary file.')),
        'dl_archive_error' => new PwgError(null, l10n('Can\'t download archive.')),
        'archive_error' => new PwgError(null, l10n('Can\'t read or extract archive.')),
        default => new PwgError(null, l10n('An error occured during extraction (%s).', $upgrade_status)),
    };
}

/**
 * API method
 * Ignore an update
 * @param mixed[] $params
 *    @option string type (optional)
 *    @option string id (optional)
 *    @option bool reset
 *    @option string pwg_token
 */
/** @param array<mixed> $params */
function ws_extensions_ignoreupdate(array $params, \Piwigo\Ws\PwgServer $service): PwgError|true
{
    define('IN_ADMIN', true);
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    if (!is_webmaster()) {
        return new PwgError(401, 'Access denied');
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $updates_ignored_raw = \Piwigo\Config\Config::get('updates_ignored');
    $updates_ignored_unserialized = is_string($updates_ignored_raw) ? unserialize($updates_ignored_raw) : false;
    \Piwigo\Config\Config::override('updates_ignored', is_array($updates_ignored_unserialized) ? $updates_ignored_unserialized : []);

    // Reset ignored extension
    if ($params['reset']) {
        $updates_ignored = \Piwigo\Config\Config::get('updates_ignored');
        $ignore_type = is_string($params['type'] ?? null) ? $params['type'] : '';
        if (!empty($ignore_type) and is_array($updates_ignored) and isset($updates_ignored[$ignore_type])) {
            $updates_ignored[$ignore_type] = [];
            \Piwigo\Config\Config::override('updates_ignored', $updates_ignored);
        } else {
            \Piwigo\Config\Config::override('updates_ignored', [
              'plugins' => [],
              'themes' => [],
              'languages' => [],
            ]);
        }

        conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize(\Piwigo\Config\Config::get('updates_ignored'))));
        unset($_SESSION['extensions_need_update']);
        return true;
    }

    $ignore_id = is_string($params['id'] ?? null) ? $params['id'] : '';
    $ignore_type2 = is_string($params['type'] ?? null) ? $params['type'] : '';
    if (empty($ignore_id) or empty($ignore_type2) or !in_array($ignore_type2, ['plugins', 'themes', 'languages'])) {
        return new PwgError(403, 'Invalid parameters');
    }

    // Add or remove extension from ignore list
    $ignored_cfg_raw = \Piwigo\Config\Config::get('updates_ignored');
    $ignored_cfg = is_array($ignored_cfg_raw) ? $ignored_cfg_raw : [];
    $ignored_for_type = is_array($ignored_cfg[$ignore_type2] ?? null) ? $ignored_cfg[$ignore_type2] : [];
    if (!in_array($ignore_id, $ignored_for_type)) {
        $ignored_for_type[] = $ignore_id;
        $ignored_cfg[$ignore_type2] = $ignored_for_type;
        \Piwigo\Config\Config::override('updates_ignored', $ignored_cfg);
    }

    conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize(\Piwigo\Config\Config::get('updates_ignored'))));
    unset($_SESSION['extensions_need_update']);
    return true;
}

/**
 * API method
 * Checks for updates (core and extensions)
 * @param mixed[] $params
 */
/**
 * @param array<mixed> $params
 * @return array<mixed>
 */
function ws_extensions_checkupdates(array $params, \Piwigo\Ws\PwgServer $service): array
{
    include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

    $update = new Updates();
    $result = [];

    if (!isset($_SESSION['need_update'.PHPWG_VERSION])) {
        $update->check_piwigo_upgrade();
    }

    $result['piwigo_need_update'] = $_SESSION['need_update'.PHPWG_VERSION];

    $cu_updates_ignored_raw = \Piwigo\Config\Config::get('updates_ignored');
    $cu_updates_ignored = is_string($cu_updates_ignored_raw) ? unserialize($cu_updates_ignored_raw) : false;
    \Piwigo\Config\Config::override('updates_ignored', is_array($cu_updates_ignored) ? $cu_updates_ignored : []);

    // Always check extensions fresh to match the updates page behavior
    $update->check_extensions();

    if (!is_array($_SESSION['extensions_need_update'])) {
        $result['ext_need_update'] = null;
    } else {
        $result['ext_need_update'] = !empty($_SESSION['extensions_need_update']);
    }

    return $result;
}
