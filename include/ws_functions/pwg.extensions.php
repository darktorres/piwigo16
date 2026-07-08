<?php

declare(strict_types=1);

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
function ws_plugins_getList($params, PwgServer &$service): array
{
    include_once PHPWG_ROOT_PATH . 'admin/include/plugins.class.php';

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
function ws_plugins_performAction(array $params, PwgServer &$service): \PwgError|true
{
    global $template, $conf;

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (! is_webmaster()) {
        return new PwgError(403, l10n('Webmaster status is required.'));
    }

    if (! $conf['enable_extensions_install'] and $params['action'] == 'delete') {
        return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
    }

    define('IN_ADMIN', true);
    include_once PHPWG_ROOT_PATH . 'admin/include/plugins.class.php';

    $plugins = new plugins();
    $errors = $plugins->perform_action($params['action'], $params['plugin']);

    if (! empty($errors)) {
        return new PwgError(500, implode(', ', $errors));
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
function ws_themes_performAction(array $params, PwgServer &$service): \PwgError|true
{
    global $template, $conf;

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (! $conf['enable_extensions_install'] and $params['action'] == 'delete') {
        return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
    }

    define('IN_ADMIN', true);
    include_once PHPWG_ROOT_PATH . 'admin/include/themes.class.php';

    $themes = new themes();
    $errors = $themes->perform_action($params['action'], $params['theme']);

    if (! empty($errors)) {
        return new PwgError(500, implode(', ', $errors));
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
function ws_extensions_update(array $params, PwgServer &$service): \PwgError|string
{
    global $conf;

    if (! $conf['enable_extensions_install']) {
        return new PwgError(401, 'Piwigo extensions install/update system is disabled');
    }

    if (! is_webmaster()) {
        return new PwgError(401, l10n('Webmaster status is required.'));
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    if (! in_array($params['type'], ['plugins', 'themes', 'languages'])) {
        return new PwgError(403, 'invalid extension type');
    }

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    include_once PHPWG_ROOT_PATH . 'admin/include/' . $params['type'] . '.class.php';

    $type = $params['type'];
    $extension_id = $params['id'];
    $revision = $params['revision'];

    $extension = new $type();

    if ($type == 'plugins') {
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

        [$upgrade_status] = $extension->perform_action('update', $extension_id, [
            'revision' => $revision,
        ]);
        $extension_name = $extension->fs_plugins[$extension_id]['name'];

        if (isset($params['reactivate'])) {
            $extension->perform_action('activate', $extension_id);
        }
    } elseif ($type == 'themes') {
        $upgrade_status = $extension->extract_theme_files('upgrade', $revision, $extension_id);
        $extension_name = $extension->fs_themes[$extension_id]['name'];

        $activity_details = [
            'theme_id' => $extension_id,
            'from_version' => $extension->fs_themes[$extension_id]['version'],
        ];

        if ($upgrade_status == 'ok') {
            $extension->get_fs_themes(); // refresh list
            $activity_details['to_version'] = $extension->fs_themes[$extension_id]['version'];
        } else {
            $activity_details['result'] = 'error';
        }

        pwg_activity('system', ACTIVITY_SYSTEM_THEME, 'update', $activity_details);
    } elseif ($type == 'languages') {
        $upgrade_status = $extension->extract_language_files('upgrade', $revision, $extension_id);
        $extension_name = $extension->fs_languages[$extension_id]['name'];
    } else {
        // Unreachable: $type is $params['type'], already restricted to
        // plugins/themes/languages by the in_array() guard above.
        throw new LogicException('Invalid extension type: ' . $type);
    }

    global $template;
    $template->delete_compiled_templates();

    return match ($upgrade_status) {
        'ok' => l10n('%s has been successfully updated.', $extension_name),
        'temp_path_error' => new PwgError(500, l10n('Can\'t create temporary file.')),
        'dl_archive_error' => new PwgError(500, l10n('Can\'t download archive.')),
        'archive_error' => new PwgError(500, l10n('Can\'t read or extract archive.')),
        default => new PwgError(500, l10n('An error occured during extraction (%s).', $upgrade_status)),
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
function ws_extensions_ignoreupdate(array $params, PwgServer &$service): \PwgError|true
{
    global $conf;

    define('IN_ADMIN', true);
    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

    if (! is_webmaster()) {
        return new PwgError(401, 'Access denied');
    }

    if (get_pwg_token() != $params['pwg_token']) {
        return new PwgError(403, 'Invalid security token');
    }

    $conf['updates_ignored'] = unserialize($conf['updates_ignored']);

    // Reset ignored extension
    if ($params['reset']) {
        if (! empty($params['type']) and isset($conf['updates_ignored'][$params['type']])) {
            $conf['updates_ignored'][$params['type']] = [];
        } else {
            $conf['updates_ignored'] = [
                'plugins' => [],
                'themes' => [],
                'languages' => [],
            ];
        }

        conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize($conf['updates_ignored'])));
        unset($_SESSION['extensions_need_update']);
        return true;
    }

    if (empty($params['id']) or empty($params['type']) or ! in_array($params['type'], ['plugins', 'themes', 'languages'])) {
        return new PwgError(403, 'Invalid parameters');
    }

    // Add or remove extension from ignore list
    if (! in_array($params['id'], $conf['updates_ignored'][$params['type']])) {
        $conf['updates_ignored'][$params['type']][] = $params['id'];
    }

    conf_update_param('updates_ignored', pwg_db_real_escape_string(serialize($conf['updates_ignored'])));
    unset($_SESSION['extensions_need_update']);
    return true;
}

/**
 * API method
 * Checks for updates (core and extensions)
 * @param mixed[] $params
 * @return array{piwigo_need_update: mixed, ext_need_update: bool|null}
 */
function ws_extensions_checkupdates($params, PwgServer &$service): array
{
    global $conf;

    include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
    include_once PHPWG_ROOT_PATH . 'admin/include/updates.class.php';

    $update = new updates();
    $result = [];

    if (! isset($_SESSION['need_update' . PHPWG_VERSION])) {
        $update->check_piwigo_upgrade();
    }

    $result['piwigo_need_update'] = $_SESSION['need_update' . PHPWG_VERSION];

    $conf['updates_ignored'] = unserialize($conf['updates_ignored']);

    if (! isset($_SESSION['extensions_need_update'])) {
        $update->check_extensions();
    } else {
        $update->check_updated_extensions();
    }

    if (! is_array($_SESSION['extensions_need_update'])) {
        $result['ext_need_update'] = null;
    } else {
        $result['ext_need_update'] = ! empty($_SESSION['extensions_need_update']);
    }

    return $result;
}
