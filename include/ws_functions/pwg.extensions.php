<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Admin\plugins;
use Piwigo\Admin\themes;
use Piwigo\Admin\updates;
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
    $plugins = new plugins();
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

    if (!\Piwigo\Core\Config::enableExtensionsInstall() and 'delete' == $params['action']) {
        return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
    }

    define('IN_ADMIN', true);

    $plugins = new plugins();
    $errors = $plugins->perform_action($params['action'], $params['plugin']);

    if (!empty($errors)) {
        return new PwgError(500, implode(", ", $errors));
    } else {
        if (in_array($params['action'], ['activate', 'deactivate'])) {
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

    if (!\Piwigo\Core\Config::enableExtensionsInstall() and 'delete' == $params['action']) {
        return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
    }

    define('IN_ADMIN', true);

    $themes = new themes();
    $errors = $themes->perform_action($params['action'], $params['theme']);

    if (!empty($errors)) {
        return new PwgError(500, implode(", ", $errors));
    } else {
        if (in_array($params['action'], ['activate', 'deactivate'])) {
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
    if (!\Piwigo\Core\Config::enableExtensionsInstall()) {
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

    $type = $params['type'];
    $extension_id = $params['id'];
    $revision = $params['revision'];

    $upgrade_status = 'ok';
    $extension_name = '';

    if ($type == 'plugins') {
        $extension = new \Piwigo\Admin\plugins();
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

        [$upgrade_status] = $extension->perform_action('update', $extension_id, ['revision' => $revision]);
        $extension_name = $extension->fs_plugins[$extension_id]['name'];

        if (isset($params['reactivate'])) {
            $extension->perform_action('activate', $extension_id);
        }
    } elseif ($type == 'themes') {
        $extension = new \Piwigo\Admin\themes();
        $upgrade_status = $extension->extract_theme_files('upgrade', $revision, $extension_id);
        $extension_name = $extension->fs_themes[$extension_id]['name'];

        $activity_details = ['theme_id' => $extension_id, 'from_version' => $extension->fs_themes[$extension_id]['version']];

        if ('ok' == $upgrade_status) {
            $extension->get_fs_themes(); // refresh list
            $activity_details['to_version'] = $extension->fs_themes[$extension_id]['version'];
        } else {
            $activity_details['result'] = 'error';
        }

        pwg_activity('system', ACTIVITY_SYSTEM_THEME, 'update', $activity_details);
    } elseif ($type == 'languages') {
        $extension = new \Piwigo\Admin\languages();
        $upgrade_status = $extension->extract_language_files('upgrade', $revision, $extension_id);
        $extension_name = $extension->fs_languages[$extension_id]['name'];
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

    \Piwigo\Core\Config::override('updates_ignored', unserialize(\Piwigo\Core\Config::get('updates_ignored') ?? ''));

    // Reset ignored extension
    if ($params['reset']) {
        $updates_ignored = \Piwigo\Core\Config::get('updates_ignored');
        if (!empty($params['type']) and isset($updates_ignored[ $params['type'] ])) {
            $updates_ignored[$params['type']] = [];
            \Piwigo\Core\Config::override('updates_ignored', $updates_ignored);
        } else {
            \Piwigo\Core\Config::override('updates_ignored', [
              'plugins' => [],
              'themes' => [],
              'languages' => [],
            ]);
        }

        conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize(\Piwigo\Core\Config::get('updates_ignored'))));
        unset($_SESSION['extensions_need_update']);
        return true;
    }

    if (empty($params['id']) or empty($params['type']) or !in_array($params['type'], ['plugins', 'themes', 'languages'])) {
        return new PwgError(403, 'Invalid parameters');
    }

    // Add or remove extension from ignore list
    if (!in_array($params['id'], \Piwigo\Core\Config::get('updates_ignored')[ $params['type'] ])) {
        \Piwigo\Core\Config::get('updates_ignored')[ $params['type'] ][] = $params['id'];
    }

    conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize(\Piwigo\Core\Config::get('updates_ignored'))));
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

    $update = new updates();
    $result = [];

    if (!isset($_SESSION['need_update'.PHPWG_VERSION])) {
        $update->check_piwigo_upgrade();
    }

    $result['piwigo_need_update'] = $_SESSION['need_update'.PHPWG_VERSION];

    \Piwigo\Core\Config::override('updates_ignored', unserialize(\Piwigo\Core\Config::get('updates_ignored') ?? ''));

    if (!isset($_SESSION['extensions_need_update'])) {
        $update->check_extensions();
    } else {
        $update->check_updated_extensions();
    }

    if (!is_array($_SESSION['extensions_need_update'])) {
        $result['ext_need_update'] = null;
    } else {
        $result['ext_need_update'] = !empty($_SESSION['extensions_need_update']);
    }

    return $result;
}
