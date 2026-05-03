<?php

declare(strict_types=1);

use Piwigo\Admin\Plugins;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


$template->set_filenames(['plugins' => 'plugins_installed.tpl']);

// should we display details on plugins?
if (isset($_GET['show_details'])) {
    if (1 == $_GET['show_details']) {
        $show_details = true;
    } else {
        $show_details = false;
    }

    pwg_set_session_var('plugins_show_details', $show_details);
} else {
    $show_details = pwg_get_session_var('plugins_show_details', false);
}

$base_url = get_root_url().'admin.php?page='.$page['page'];
$pwg_token = get_pwg_token();
$action_url = $base_url.'&amp;plugin='.'%s'.'&amp;pwg_token='.$pwg_token;

$plugins = new Plugins();

//--------------------------------------------------------Incompatible Plugins
if (isset($_GET['incompatible_plugins'])) {
    $incompatible_plugins_raw = $plugins->get_incompatible_plugins();

    if (false === $incompatible_plugins_raw) {
        echo json_encode([]);
        exit;
    }

    $incompatible_plugins = [];

    foreach ($incompatible_plugins_raw as $plugin => $version) {
        if ($plugin == '~~expire~~') {
            continue;
        }
        $incompatible_plugins[] = $plugin;

    }
    echo json_encode($incompatible_plugins);
    exit;
}

//--------------------------------------------------------Get the menu with the depreciated version

$plugin_menu_links_raw = trigger_change('get_admin_plugin_menu_links', []);
$plugin_menu_links_deprec = $plugin_menu_links_raw;

$settings_url_for_plugin_deprec = [];

foreach ($plugin_menu_links_deprec as $value) {
    if (!is_array($value)) {
        continue;
    }
    $vUrl = is_scalar($value['URL'] ?? null) ? (string) $value['URL'] : '';
    if (preg_match('/^admin\.php\?page=plugin-(.*)$/', $vUrl, $matches)) {
        $settings_url_for_plugin_deprec[$matches[1]] = $vUrl;
    } elseif (preg_match('/^.*section=(.*?)[\/&%].*$/', $vUrl, $matches)) {
        $settings_url_for_plugin_deprec[$matches[1]] = $vUrl;
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+

$plugins->sort_fs_plugins('name');
$merged_extensions = $plugins->get_merged_extensions();
$merged_plugins = false;
$tpl_plugins = [];
$count_types_plugins = ['active' => 0, 'inactive' => 0, 'missing' => 0, 'merged' => 0];

foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
    $incompatPlugins = is_array($_SESSION['incompatible_plugins'] ?? null) ? $_SESSION['incompatible_plugins'] : [];
    if (isset($incompatPlugins[$plugin_id])
      and $fs_plugin['version'] != $incompatPlugins[$plugin_id]) {
        // Incompatible plugins must be reinitilized
        unset($_SESSION['incompatible_plugins']);
    }

    $setting_url = '';
    if ($fs_plugin['hasSettings']) { // new version
        $setting_url = 'admin.php?page=plugin-'.$plugin_id;

        if (preg_match('/^piwigo-(videojs|openstreetmap)$/', (string) $plugin_id)) {
            $setting_url = str_replace('piwigo-', 'piwigo_', $setting_url);
        }
    }

    $url_to_replace = [
      'http://piwigo.org/ext',
      'https://piwigo.org/ext',
    ];
    $visit_url = str_replace($url_to_replace, PEM_URL, is_scalar($fs_plugin['uri'] ?? null) ? (string) $fs_plugin['uri'] : '');

    $tpl_plugin = [
      'ID' => $plugin_id,
      'NAME' => $fs_plugin['name'],
      'VISIT_URL' => $visit_url,
      'VERSION' => $fs_plugin['version'],
      'DESC' => $fs_plugin['description'],
      'AUTHOR' => $fs_plugin['author'],
      'AUTHOR_URL' => @$fs_plugin['author uri'],
      'U_ACTION' => sprintf($action_url, $plugin_id),
      'SETTINGS_URL' => $setting_url,
      ];

    if (isset($plugins->db_plugins_by_id[$plugin_id])) {
        $tpl_plugin['STATE'] = $plugins->db_plugins_by_id[$plugin_id]['state'];
    } else {
        $tpl_plugin['STATE'] = 'inactive';
    }

    $fsExtId = $fs_plugin['extension'] ?? null;
    if (isset($fsExtId) && (is_string($fsExtId) || is_int($fsExtId)) && isset($merged_extensions[$fsExtId])) {
        // Deactivate manually plugin from database
        $query = 'UPDATE '.PLUGINS_TABLE.' SET state=\'inactive\' WHERE id=\''.$plugin_id.'\'';
        pwg_query($query);

        $tpl_plugin['STATE'] = 'merged';
        $tpl_plugin['DESC'] = l10n('THIS PLUGIN IS NOW PART OF PIWIGO CORE! DELETE IT NOW.');
        $merged_plugins = true;
    }

    $pluginState = is_scalar($tpl_plugin['STATE']) ? (string) $tpl_plugin['STATE'] : 'inactive';
    if (isset($count_types_plugins[$pluginState])) {
        $count_types_plugins[$pluginState]++;
    }

    $tpl_plugins[] = $tpl_plugin;
}

$template->append('plugin_states', 'active');
$template->append('plugin_states', 'inactive');

if ($merged_plugins) {
    $template->append('plugin_states', 'merged');
}

$missing_plugin_ids = array_diff(
    array_keys($plugins->db_plugins_by_id),
    array_keys($plugins->fs_plugins)
);

if (count($missing_plugin_ids) > 0) {
    foreach ($missing_plugin_ids as $plugin_id) {
        $tpl_plugins[] = [
          'NAME' => $plugin_id,
          'ID' => $plugin_id,
          'VERSION' => $plugins->db_plugins_by_id[$plugin_id]['version'],
          'DESC' => l10n('ERROR: THIS PLUGIN IS MISSING BUT IT IS INSTALLED! UNINSTALL IT NOW.'),
          'U_ACTION' => sprintf($action_url, $plugin_id),
          'STATE' => 'missing',
          ];
        $count_types_plugins['missing']++;
    }
    $template->append('plugin_states', 'missing');
}

// sort plugins by state then by name
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function cmp(array $a, array $b): int|bool
{
    $s = ['merged' => 0, 'missing' => 1, 'active' => 2, 'inactive' => 3];

    $aState = is_scalar($a['STATE'] ?? null) ? (string) $a['STATE'] : 'inactive';
    $bState = is_scalar($b['STATE'] ?? null) ? (string) $b['STATE'] : 'inactive';
    if ($aState == $bState) {
        $aName = is_scalar($a['NAME'] ?? null) ? (string) $a['NAME'] : '';
        $bName = is_scalar($b['NAME'] ?? null) ? (string) $b['NAME'] : '';
        return strcasecmp($aName, $bName);
    } else {
        return ($s[$aState] ?? 99) >= ($s[$bState] ?? 99);
    }
}

// Stoped plugin sorting for new plugin manager
// usort($tpl_plugins, 'cmp');

$template->assign(
    [
    'plugins' => $tpl_plugins,
    'count_types_plugins' => $count_types_plugins,
    'PWG_TOKEN' => $pwg_token,
    'base_url' => $base_url,
    'show_details' => $show_details,
    'max_inactive_before_hide' => isset($_GET['show_inactive']) ? 999 : 8,
    'isWebmaster' => (is_webmaster()) ? 1 : 0,
    'ADMIN_PAGE_TITLE' => l10n('Plugins'),
    'view_selector' => userprefs_get_param('plugin-manager-view', 'classic'),
    'CONF_ENABLE_EXTENSIONS_INSTALL' => \Piwigo\Config\Config::enableExtensionsInstall(),
    'page_data_json' => json_encode([
        'pwg_token' => $pwg_token,
        'isWebmaster' => (is_webmaster()) ? 1 : 0,
        'show_details' => $show_details,
        'nb_plugin' => [
            'all' => $count_types_plugins['active'] + $count_types_plugins['inactive'] + $count_types_plugins['missing'] + $count_types_plugins['merged'],
            'active' => $count_types_plugins['active'],
            'inactive' => $count_types_plugins['inactive'],
            'other' => $count_types_plugins['missing'] + $count_types_plugins['merged'],
        ],
        'activate_msg' => l10n('Do you want to activate anyway?'),
        'cancel_msg' => l10n('No, I have changed my mind'),
        'confirm_msg' => l10n('Yes, I am sure'),
        'deactivate_all_msg' => l10n('Deactivate all'),
        'delete_plugin_msg' => l10n('Are you sure you want to delete the plugin "%s"?'),
        'deleted_plugin_msg' => l10n('Plugin "%s" deleted!'),
        'incompatible_msg' => l10n('WARNING! This plugin does not seem to be compatible with this version of Piwigo.'),
        'not_webmaster' => l10n('Webmaster status required'),
        'nothing_found' => l10n('No plugins found'),
        'plugin_action_error' => l10n('an error happened'),
        'plugin_added_str' => l10n('Activated'),
        'plugin_deactivated_str' => l10n('Deactivated'),
        'plugin_found' => l10n('%s plugin found'),
        'plugin_restored_str' => l10n('Restored'),
        'restore_plugin_msg' => l10n('Are you sure you want to restore the plugin "%s"?'),
        'str_restore_def' => l10n('While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset'),
        'uninstall_plugin_msg' => l10n('Are you sure you want to uninstall the plugin "%s"?'),
        'x_plugins_found' => l10n('%s plugins found'),
    ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
    ]
);

$template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
