<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\plugins;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_session;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

$template->set_filenames([
    'plugins' => 'plugins_installed.tpl',
]);

// should we display details on plugins?
if (isset($_GET['show_details'])) {
    $show_details = $_GET['show_details'] == 1;
    functions_session::pwg_set_session_var('plugins_show_details', $show_details);
} elseif (functions_session::pwg_get_session_var('plugins_show_details') != null) {
    $show_details = functions_session::pwg_get_session_var('plugins_show_details');
} else {
    $show_details = false;
}

$base_url = functions_url::get_root_url() . 'admin.php?page=' . $page['page'];
$pwg_token = functions::get_pwg_token();
$action_url = $base_url . '&amp;plugin=%s&amp;pwg_token=' . $pwg_token;

$plugins = new plugins();

//--------------------------------------------------------Incompatible Plugins
if (isset($_GET['incompatible_plugins'])) {
    $incompatible_plugins_raw = $plugins->get_incompatible_plugins();

    if ($incompatible_plugins_raw === false) {
        echo json_encode([]);
        exit;
    }

    $incompatible_plugins = [];

    foreach (array_keys($incompatible_plugins_raw) as $plugin) {
        if ($plugin == '~~expire~~') {
            continue;
        }

        $incompatible_plugins[] = $plugin;

    }

    echo json_encode($incompatible_plugins);
    exit;
}

//--------------------------------------------------------Get the menu with the deprecated version

$plugin_menu_links_deprec = functions_plugins::trigger_change('get_admin_plugin_menu_links', []);

$settings_url_for_plugin_deprec = [];

foreach ($plugin_menu_links_deprec as $value) {
    if (preg_match('/^admin\.php\?page=plugin-(.*)$/', $value['URL'], $matches)) {
        $settings_url_for_plugin_deprec[$matches[1]] = $value['URL'];
    } elseif (preg_match('/^.*section=(.*?)[\/&%].*$/', $value['URL'], $matches)) {
        $settings_url_for_plugin_deprec[$matches[1]] = $value['URL'];
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+

$plugins->sort_fs_plugins();
$merged_extensions = $plugins->get_merged_extensions();
$merged_plugins = false;
$tpl_plugins = [];
$count_types_plugins = [
    'active' => 0,
    'inactive' => 0,
    'missing' => 0,
    'merged' => 0,
];

foreach ($plugins->fs_plugins as $plugin_id => $fs_plugin) {
    if (isset($_SESSION['incompatible_plugins'][$plugin_id]) &&
        $fs_plugin['version'] != $_SESSION['incompatible_plugins'][$plugin_id]
    ) {
        // Incompatible plugins must be reinitialized
        unset($_SESSION['incompatible_plugins']);
    }

    $setting_url = '';

    if (isset($settings_url_for_plugin_deprec[$plugin_id])) { //old version
        $setting_url = $settings_url_for_plugin_deprec[$plugin_id];
    } elseif ($fs_plugin['hasSettings']) { // new version
        $setting_url = 'admin.php?page=plugin-' . $plugin_id;

        if (preg_match('/^piwigo-(videojs|openstreetmap)$/', $plugin_id)) {
            $setting_url = str_replace('piwigo-', 'piwigo_', $setting_url);
        }
    }

    $tpl_plugin = [
        'ID' => $plugin_id,
        'NAME' => $fs_plugin['name'],
        'VISIT_URL' => $fs_plugin['uri'],
        'VERSION' => $fs_plugin['version'],
        'DESC' => $fs_plugin['description'],
        'AUTHOR' => $fs_plugin['author'],
        'AUTHOR_URL' => ($fs_plugin['author uri'] ?? null),
        'U_ACTION' => sprintf($action_url, $plugin_id),
        'SETTINGS_URL' => $setting_url,
    ];

    if (isset($plugins->db_plugins_by_id[$plugin_id])) {
        $tpl_plugin['STATE'] = $plugins->db_plugins_by_id[$plugin_id]['state'];
    } else {
        $tpl_plugin['STATE'] = 'inactive';
    }

    if (isset($fs_plugin['extension']) &&
        isset($merged_extensions[$fs_plugin['extension']])
    ) {
        // Deactivate manually plugin from database
        $query = <<<SQL
            UPDATE plugins
            SET state = 'inactive'
            WHERE id = '{$plugin_id}';
            SQL;
        $conf->sql_backend::pwg_query($query);

        $tpl_plugin['STATE'] = 'merged';
        $tpl_plugin['DESC'] = functions::l10n('THIS PLUGIN IS NOW PART OF PIWIGO CORE! DELETE IT NOW.');
        $merged_plugins = true;
    }

    $count_types_plugins[$tpl_plugin['STATE']]++;

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

if ($missing_plugin_ids !== []) {
    foreach ($missing_plugin_ids as $plugin_id) {
        $tpl_plugins[] = [
            'NAME' => $plugin_id,
            'ID' => $plugin_id,
            'VERSION' => $plugins->db_plugins_by_id[$plugin_id]['version'],
            'DESC' => functions::l10n('ERROR: THIS PLUGIN IS MISSING BUT IT IS INSTALLED! UNINSTALL IT NOW.'),
            'U_ACTION' => sprintf($action_url, $plugin_id),
            'STATE' => 'missing',
        ];
        $count_types_plugins['missing']++;
    }

    $template->append('plugin_states', 'missing');
}

// Stopped plugin sorting for new plugin manager
// usort($tpl_plugins, function (
//     array $a,
//     array $b
// ): int {
//   // sort plugins by state then by name
//   $s = array('merged' => 0, 'missing' => 1, 'active' => 2, 'inactive' => 3);

//   if($a['STATE'] == $b['STATE'])
//     return strcasecmp($a['NAME'], $b['NAME']);
//   else
//     return $s[$a['STATE']] >= $s[$b['STATE']];
// });

$template->assign(
    [
        'plugins' => $tpl_plugins,
        'count_types_plugins' => $count_types_plugins,
        'PWG_TOKEN' => $pwg_token,
        'base_url' => $base_url,
        'show_details' => $show_details,
        'max_inactive_before_hide' => isset($_GET['show_inactive']) ? 999 : 8,
        'isWebmaster' => (functions_user::is_webmaster()) ? 1 : 0,
        'ADMIN_PAGE_TITLE' => functions::l10n('Plugins'),
        'view_selector' => functions_user::userprefs_get_param('plugin-manager-view', 'classic'),
        'CONF_ENABLE_EXTENSIONS_INSTALL' => $conf->enable_extensions_install,
    ]
);

$page_data = [
    'pwgToken' => $pwg_token,
    'incompatibleMsg' => functions::l10n('WARNING! This plugin does not seem to be compatible with this version of Piwigo.'),
    'activateMsg' => "\n" . functions::l10n('Do you want to activate anyway?'),
    'deactivateAllMsg' => functions::l10n('Deactivate all'),
    'nbPlugin' => [
        'all' => $count_types_plugins['active'] + $count_types_plugins['inactive'] + $count_types_plugins['missing'] + $count_types_plugins['merged'],
        'active' => $count_types_plugins['active'],
        'inactive' => $count_types_plugins['inactive'],
        'other' => $count_types_plugins['missing'] + $count_types_plugins['merged'],
    ],
    'areYouSureMsg' => functions::l10n('Are you sure?'),
    'confirmMsg' => functions::l10n('Yes, I am sure'),
    'cancelMsg' => functions::l10n('No, I have changed my mind'),
    'deletePluginMsg' => functions::l10n('Are you sure you want to delete the plugin "%s"?'),
    'deletedPluginMsg' => functions::l10n('Plugin "%s" deleted!'),
    'restorePluginMsg' => functions::l10n('Are you sure you want to restore the plugin "%s"?'),
    'uninstallPluginMsg' => functions::l10n('Are you sure you want to uninstall the plugin "%s"?'),
    'restoreTipMsg' => functions::l10n('Restore default configuration. You will lose your plugin settings!'),
    'pluginAddedStr' => functions::l10n('Activated'),
    'pluginDeactivatedStr' => functions::l10n('Deactivated'),
    'pluginRestoredStr' => functions::l10n('Restored'),
    'pluginActionError' => functions::l10n('an error happened'),
    'notWebmaster' => functions::l10n('Webmaster status required'),
    'nothingFound' => functions::l10n('No plugins found'),
    'xPluginsFound' => functions::l10n('%s plugins found'),
    'pluginFound' => functions::l10n('%s plugin found'),
    'isWebmaster' => (functions_user::is_webmaster()) ? 1 : 0,
    'viewSelector' => $show_details ? 'detailed' : functions_user::userprefs_get_param('plugin-manager-view', 'classic'),
    'strRestoreDef' => functions::l10n('While restoring this plugin, it will be reset to its original parameters and associated data is going to be reset'),
    'showDetails' => (bool) $show_details,
];
$template->assign('page_data_json', json_encode($page_data));

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['plugins_installated', 'pwgConfirm']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
