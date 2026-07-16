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
use Piwigo\Admin\languages;
use Piwigo\Admin\plugins;
use Piwigo\Admin\themes;
use Piwigo\Admin\updates;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\ActivitySystem;
use Piwigo\Core\AppInfo;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Template\Template;

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
     *
     * @param array{action: string, plugin: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, no 'type'
     *   flag.
     */
    public static function pluginsPerformAction(array $params, PwgServer &$service): PwgError|true
    {
        global $template, $conf;

        /** @var Template $template */
        /** @var array<string, mixed> $conf */
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! AccessControl::isWebmaster()) {
            return new PwgError(403, l10n('Webmaster status is required.'));
        }

        if (! (bool) $conf['enable_extensions_install'] and $params['action'] == 'delete') {
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

        $plugins = new plugins();
        $errors = $plugins->perform_action($params['action'], $params['plugin']);

        if (! empty($errors)) {
            return new PwgError(500, implode(', ', array_filter($errors, is_string(...))));
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
     *
     * @param array{action: string, theme: string, pwg_token: string, ...} $params
     *   none has a 'default' key -- all mandatory, always present, no 'type'
     *   flag.
     */
    public static function themesPerformAction(array $params, PwgServer &$service): PwgError|true
    {
        global $template, $conf;

        /** @var Template $template */
        /** @var array<string, mixed> $conf */
        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! (bool) $conf['enable_extensions_install'] and $params['action'] == 'delete') {
            return new PwgError(401, 'Piwigo extensions install/update/delete system is disabled');
        }

        // P23 batch 8e-2: see pluginsPerformAction()'s own comment -- the
        // original define('IN_ADMIN', true) here is equally a no-op for
        // this request.

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
        global $conf;

        /** @var array<string, mixed> $conf */
        if (! (bool) $conf['enable_extensions_install']) {
            return new PwgError(401, 'Piwigo extensions install/update system is disabled');
        }

        if (! AccessControl::isWebmaster()) {
            return new PwgError(401, l10n('Webmaster status is required.'));
        }

        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        if (! in_array($params['type'], ['plugins', 'themes', 'languages'])) {
            return new PwgError(403, 'invalid extension type');
        }

        $type = $params['type'];
        $extension_id = $params['id'];
        $revision = $params['revision'];

        $extension = match ($type) {
            'plugins' => new plugins(),
            'themes' => new themes(),
            // 'languages' is the only value that can still reach here: $type
            // is already restricted to plugins/themes/languages by the
            // in_array() guard above.
            default => new languages(),
        };

        if ($extension instanceof plugins) {
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
            . '&pwg_token=' . new CsrfService()->getToken()
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
        } elseif ($extension instanceof themes) {
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

            new ActivityService(new ActivityRepository(DbConnection::build()))->record('system', ActivitySystem::Theme, 'update', $activity_details);
        } elseif ($extension instanceof languages) {
            $upgrade_status = $extension->extract_language_files('upgrade', $revision, $extension_id);
            $extension_name = $extension->fs_languages[$extension_id]['name'];
        } else {
            // Unreachable: $type is $params['type'], already restricted to
            // plugins/themes/languages by the in_array() guard above.
            throw new LogicException('Invalid extension type: ' . $type);
        }

        global $template;

        /** @var Template $template */
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
     *
     * @param array{type: string|null, id: string|null, reset: bool, pwg_token: string, ...} $params
     *   type/id: null default, no 'type' flag -- always present, string|null.
     *   reset: non-null bool default, WsParamType::BOOL -- always present.
     *   pwg_token: no 'default' key -- mandatory, always present.
     */
    public static function ignoreUpdate(array $params, PwgServer &$service): PwgError|true
    {
        global $conf;

        /** @var array<string, mixed> $conf */
        // P23 batch 8e-2: the original define('IN_ADMIN', true)+
        // include_once admin/include/functions.php here are both dropped --
        // IN_ADMIN has no reader left in this request's lifecycle (see
        // pluginsPerformAction()'s own comment), and
        // admin/include/functions.php has been emptied of all functions
        // since P23 batch 8d (conf_update_param() below is a permanent
        // facade in include/functions.inc.php, already autoloaded).

        if (! AccessControl::isWebmaster()) {
            return new PwgError(401, 'Access denied');
        }

        if (new CsrfService()->getToken() != $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }

        // unserialize() is typed mixed by PHP's own stub -- narrow to the
        // known {plugins,themes,languages} shape (each a plain list of
        // extension id strings, per the install-time default row in
        // install/db/103-database.php) before any offset access below.
        // $conf['updates_ignored'] is a serialized string written by
        // conf_update_param() a few lines below and in
        // checkUpdates(); guard with is_string() rather than
        // assuming, since $conf is only known as array<string, mixed>.
        $updates_ignored_conf = $conf['updates_ignored'];
        $updates_ignored_raw = is_string($updates_ignored_conf) ? unserialize($updates_ignored_conf) : false;
        $updates_ignored_raw = is_array($updates_ignored_raw) ? $updates_ignored_raw : [];

        $ignored_plugins = $updates_ignored_raw['plugins'] ?? null;
        $ignored_themes = $updates_ignored_raw['themes'] ?? null;
        $ignored_languages = $updates_ignored_raw['languages'] ?? null;

        $conf['updates_ignored'] = [
            'plugins' => is_array($ignored_plugins) ? array_values(array_filter($ignored_plugins, is_string(...))) : [],
            'themes' => is_array($ignored_themes) ? array_values(array_filter($ignored_themes, is_string(...))) : [],
            'languages' => is_array($ignored_languages) ? array_values(array_filter($ignored_languages, is_string(...))) : [],
        ];

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
     *
     * @param array<string, mixed> $params this method is registered with a
     *   null signature (zero registered params) -- $params is the raw,
     *   entirely unvalidated request array, but the body doesn't read it.
     * @return array{piwigo_need_update: mixed, ext_need_update: bool|null}
     */
    public static function checkUpdates(array $params, PwgServer &$service): array
    {
        global $conf;

        /** @var array<string, mixed> $conf */
        $update = new updates();
        $result = [];

        if (! isset($_SESSION['need_update' . AppInfo::VERSION])) {
            updates::check_piwigo_upgrade();
        }

        $result['piwigo_need_update'] = $_SESSION['need_update' . AppInfo::VERSION];

        // $conf['updates_ignored'] is a serialized string written by
        // conf_update_param() (see ignoreUpdate() above); guard
        // with is_string() rather than assuming, since $conf is only known
        // as array<string, mixed>.
        $updates_ignored_conf = $conf['updates_ignored'];
        $conf['updates_ignored'] = is_string($updates_ignored_conf) ? unserialize($updates_ignored_conf) : false;

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
}
