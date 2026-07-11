<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\plugins;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $conf, $page, $template;

$template->set_filenames([
    'plugins' => 'plugins_installed.tpl',
]);

// should we display details on plugins?
if (isset($_GET['show_details'])) {
    if ($_GET['show_details'] == 1) {
        $show_details = true;
    } else {
        $show_details = false;
    }

    pwg_set_session_var('plugins_show_details', $show_details);
} elseif (pwg_get_session_var('plugins_show_details') != null) {
    $show_details = pwg_get_session_var('plugins_show_details');
} else {
    $show_details = false;
}

// admin.php always populates $page['page'] with a string (either the
// validated $_GET['page'] or the 'intro' fallback) before including this
// file, but the shared $page array is typed array<string, mixed>.
$page_page = $page['page'] ?? null;
$page_page = is_string($page_page) ? $page_page : '';
$base_url = get_root_url() . 'admin.php?page=' . $page_page;
$pwg_token = get_pwg_token();
$action_url = $base_url . '&amp;plugin=%s&amp;pwg_token=' . $pwg_token;

$plugins = new plugins();

// --------------------------------------------------------Incompatible Plugins
if (isset($_GET['incompatible_plugins'])) {
    $incompatible_plugins_raw = $plugins->get_incompatible_plugins();

    if ($incompatible_plugins_raw === false) {
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

// --------------------------------------------------------Get the menu with the depreciated version

$plugin_menu_links_deprec = trigger_change('get_admin_plugin_menu_links', []);

$settings_url_for_plugin_deprec = [];

// trigger_change() is declared to return mixed (it echoes back whatever
// registered handlers return); narrow it to an iterable of arrays here
// before reading each item's 'URL' entry.
if (is_array($plugin_menu_links_deprec)) {
    foreach ($plugin_menu_links_deprec as $value) {
        if (! is_array($value) || ! isset($value['URL']) || ! is_string($value['URL'])) {
            continue;
        }

        $menu_link_url = $value['URL'];

        if ((bool) preg_match('/^admin\.php\?page=plugin-(.*)$/', $menu_link_url, $matches)) {
            $settings_url_for_plugin_deprec[$matches[1]] = $menu_link_url;
        } elseif ((bool) preg_match('/^.*section=(.*?)[\/&%].*$/', $menu_link_url, $matches)) {
            $settings_url_for_plugin_deprec[$matches[1]] = $menu_link_url;
        }
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+

$plugins->sort_fs_plugins('name');
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
    // $_SESSION is a superglobal with no known value type, so PHPStan
    // sees $_SESSION['incompatible_plugins'] as mixed; narrow it once here
    // (same pattern as plugins.class.php's get_incompatible_plugins()).
    $incompatible_plugins_session = $_SESSION['incompatible_plugins'] ?? null;
    if (is_array($incompatible_plugins_session)
      and isset($incompatible_plugins_session[$plugin_id])
      and $fs_plugin['version'] != $incompatible_plugins_session[$plugin_id]) {
        // Incompatible plugins must be reinitilized
        unset($_SESSION['incompatible_plugins']);
    }

    $setting_url = '';
    if (isset($settings_url_for_plugin_deprec[$plugin_id])) { // old version
        $setting_url = $settings_url_for_plugin_deprec[$plugin_id];
    } elseif ((bool) $fs_plugin['hasSettings']) { // new version
        $setting_url = 'admin.php?page=plugin-' . $plugin_id;

        if ((bool) preg_match('/^piwigo-(videojs|openstreetmap)$/', $plugin_id)) {
            $setting_url = str_replace('piwigo-', 'piwigo_', $setting_url);
        }
    }

    $url_to_replace = [
        'http://piwigo.org/ext',
        'https://piwigo.org/ext',
    ];
    // fs_plugins is declared with a loose array<string, mixed> value type,
    // but get_fs_plugin() (the only writer) always stores a real string
    // under 'uri' (defaults to '', overwritten by a regex-extracted URI).
    $fs_plugin_uri = $fs_plugin['uri'] ?? '';
    $fs_plugin_uri = is_string($fs_plugin_uri) ? $fs_plugin_uri : '';
    // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url'])
    // in common.inc.php on one branch, so PHPStan infers the constant's
    // global type as mixed even though it's always a URL string at runtime.
    $pem_url = PEM_URL;
    $pem_url = is_string($pem_url) ? $pem_url : '';
    $visit_url = str_replace($url_to_replace, $pem_url, $fs_plugin_uri);

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
        $db_plugin_state = $plugins->db_plugins_by_id[$plugin_id]['state'] ?? null;
        $plugin_state = is_string($db_plugin_state) ? $db_plugin_state : 'inactive';
    } else {
        $plugin_state = 'inactive';
    }
    $tpl_plugin['STATE'] = $plugin_state;

    $fs_plugin_extension = $fs_plugin['extension'] ?? null;
    if (is_string($fs_plugin_extension) and isset($merged_extensions[$fs_plugin_extension])) {
        // Deactivate manually plugin from database
        $query = 'UPDATE ' . PLUGINS_TABLE . ' SET state=\'inactive\' WHERE id=\'' . $plugin_id . '\'';
        pwg_query($query);

        $plugin_state = 'merged';
        $tpl_plugin['STATE'] = $plugin_state;
        $tpl_plugin['DESC'] = l10n('THIS PLUGIN IS NOW PART OF PIWIGO CORE! DELETE IT NOW.');
        $merged_plugins = true;
    }

    $count_types_plugins[$plugin_state]++;

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
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function cmp(array $a, array $b): int|bool
{
    $s = [
        'merged' => 0,
        'missing' => 1,
        'active' => 2,
        'inactive' => 3,
    ];

    $a_state = $a['STATE'] ?? null;
    $a_state = is_string($a_state) ? $a_state : '';
    $b_state = $b['STATE'] ?? null;
    $b_state = is_string($b_state) ? $b_state : '';

    if ($a_state === $b_state) {
        $a_name = $a['NAME'] ?? null;
        $a_name = is_scalar($a_name) ? (string) $a_name : '';
        $b_name = $b['NAME'] ?? null;
        $b_name = is_scalar($b_name) ? (string) $b_name : '';

        return strcasecmp($a_name, $b_name);
    } else {
        return ($s[$a_state] ?? 0) >= ($s[$b_state] ?? 0);
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
        'CONF_ENABLE_EXTENSIONS_INSTALL' => $conf['enable_extensions_install'],
    ]
);

$template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
