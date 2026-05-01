<?php

declare(strict_types=1);

use Piwigo\Admin\plugins;

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


if (!\Piwigo\Core\Config::enableExtensionsInstall()) {
    die('Piwigo extensions install/update system is disabled');
}

$template->set_filenames(['plugins' => 'plugins_new.tpl']);

$base_url = get_root_url().'admin.php?page='.$page['page'].'&tab='.($page['tab'] ?? '');

$plugins = new plugins();

//------------------------------------------------------automatic installation
if (isset($_GET['revision']) and isset($_GET['extension'])) {
    if (!is_webmaster()) {
        \Piwigo\Core\PageState::current()->addError(l10n('Webmaster status is required.'));
    } else {
        check_pwg_token();

        $install_status = $plugins->extract_plugin_files('install', is_string($_GET['revision']) ? $_GET['revision'] : '', is_string($_GET['extension']) ? $_GET['extension'] : '', $plugin_id);

        redirect($base_url.'&installstatus='.$install_status.'&plugin_id='.$plugin_id);
    }
}

//--------------------------------------------------------------install result
if (isset($_GET['installstatus'])) {
    switch ($_GET['installstatus']) {
        case 'ok':
            // since Piwigo 12, you need to be on the page of installed plugins to active a plugin with
            // a JS action, no need to provide plugin_id in URL, just link to the page of installed
            // plugins, filtered on deactivated plugins. The webmaster will have to find its newly
            // installed plugin and click on the activation switch.
            $activate_url = get_root_url().'admin.php?page=plugins&amp;filter=deactivated';

            \Piwigo\Core\PageState::current()->addInfo(l10n('Plugin has been successfully copied'));
            \Piwigo\Core\PageState::current()->addInfo('<a href="'. $activate_url . '">' . l10n('Activate it now') . '</a>');

            $getPluginId = is_string($_GET['plugin_id'] ?? null) ? $_GET['plugin_id'] : '';
            if ($getPluginId !== '' && isset($plugins->fs_plugins[$getPluginId])) {
                pwg_activity(
                    'system',
                    ACTIVITY_SYSTEM_PLUGIN,
                    'install',
                    [
                    'plugin_id' => $getPluginId,
                    'version' => $plugins->fs_plugins[$getPluginId]['version'],
          ]
                );
            }
            break;

        case 'temp_path_error':
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t create temporary file.'));
            break;

        case 'dl_archive_error':
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t download archive.'));
            break;

        case 'archive_error':
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t read or extract archive.'));
            break;

        default:
            \Piwigo\Core\PageState::current()->addError(l10n('An error occured during extraction (%s).', htmlspecialchars(is_scalar($_GET['installstatus']) ? (string) $_GET['installstatus'] : '')));
            \Piwigo\Core\PageState::current()->addError(l10n('Please check "plugins" folder and sub-folders permissions (CHMOD).'));
    }
}

//---------------------------------------------------------------Order options
$template->assign(
    'order_options',
    [
    'date' => l10n('Post date'),
    'revision' => l10n('Last revisions'),
    'name' => l10n('Name'),
    'author' => l10n('Author'),
    'downloads' => l10n('Number of downloads')]
);

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+

// Beta test : show plugins of last version on PEM if the current version isn't present
// If the current version in known, give the current and last version's compatible plugins
$beta_test = false;

if (isset($_GET['beta-test']) && $_GET['beta-test'] == 'true') {
    $beta_test = true;
}

if ($plugins->get_server_plugins(true, $beta_test)) {
    /* order plugins */
    if (pwg_get_session_var('plugins_new_order') != null) {
        $order_selected = pwg_get_session_var('plugins_new_order');
        $plugins->sort_server_plugins($order_selected);
        $template->assign('order_selected', $order_selected);
    } else {
        $plugins->sort_server_plugins('date');
        $template->assign('order_selected', 'date');
    }

    foreach ($plugins->server_plugins as $plugin) {
        $ext_desc = trim(is_scalar($plugin['extension_description'] ?? null) ? (string) $plugin['extension_description'] : '', " \n\r");
        [$small_desc] = explode("\n", wordwrap($ext_desc, 200));

        $revisionId = is_scalar($plugin['revision_id'] ?? null) ? (string) $plugin['revision_id'] : '';
        $extensionId = is_scalar($plugin['extension_id'] ?? null) ? (string) $plugin['extension_id'] : '';
        $url_auto_install = htmlentities($base_url)
          . '&amp;revision=' . $revisionId
          . '&amp;extension=' . $extensionId
          . '&amp;pwg_token='.get_pwg_token()
        ;

        // get the age of the last revision in days
        $revisionDateRaw = $plugin['revision_date'] ?? null;
        $rev_date = date_create(is_string($revisionDateRaw) ? $revisionDateRaw : '');
        $now_date = date_create();
        $last_revision_diff = ($rev_date !== false && $now_date !== false) ? date_diff($rev_date, $now_date) : new \DateInterval('P0D');

        $certification = 1;
        $has_compatible_version = false;

        // Check if the current version is in the compatible version (not necessary if we are in beta test)
        if ($beta_test) {
            $compatVersions = is_array($plugin['compatible_with_versions'] ?? null) ? $plugin['compatible_with_versions'] : [];
            foreach ($compatVersions as $vers) {
                if (get_branch_from_version(is_string($vers) ? $vers : '') == get_branch_from_version(PHPWG_VERSION)) {
                    $has_compatible_version = true;
                }
            }
        } else {
            $has_compatible_version = true;
        }

        if (!$has_compatible_version) {
            $certification = -1;
        } elseif ($last_revision_diff->days < 90) { // if the last revision is new of 3 month or less
            $certification = 3;
        } elseif ($last_revision_diff->days < 180) { // 6 month or less
            $certification = 2;
        } elseif ($last_revision_diff->y > 3) { // 3 years or less
            $certification = 0;
        }
        // Between 6 month and 3 years : certification = 1

        $revDateStr = is_string($revisionDateRaw) ? $revisionDateRaw : null;
        $template->append('plugins', [
          'ID' => $extensionId,
          'EXT_NAME' => is_scalar($plugin['extension_name'] ?? null) ? $plugin['extension_name'] : '',
          'EXT_URL' => PEM_URL.'/extension_view.php?eid='.$extensionId,
          'SMALL_DESC' => trim($small_desc, " \r\n"),
          'BIG_DESC' => $ext_desc,
          'VERSION' => is_scalar($plugin['revision_name'] ?? null) ? $plugin['revision_name'] : '',
          'REVISION_DATE' => preg_replace('/[^0-9]/', '', (string) strtotime($revDateStr ?? '')),
          'REVISION_FORMATED_DATE' => format_date($revDateStr, ['day','month','year']).', '.time_since($revDateStr, 'day'),
          'AUTHOR' => is_scalar($plugin['author_name'] ?? null) ? $plugin['author_name'] : '',
          'DOWNLOADS' => $plugin['extension_nb_downloads'] ?? null,
          'URL_INSTALL' => $url_auto_install,
          'CERTIFICATION' => $certification,
          'RATING' => $plugin['rating_score'] ?? null,
          'NB_RATINGS' => $plugin['nb_ratings'] ?? null,
          'SCREENSHOT' => is_scalar($plugin['screenshot_url'] ?? null) ? $plugin['screenshot_url'] : '',
          'TAGS' => is_array($plugin['tags'] ?? null) ? $plugin['tags'] : [],
        ]);
    }


} else {
    \Piwigo\Core\PageState::current()->addError(l10n('Can\'t connect to server.'));
}

if (!$beta_test and preg_match('/(beta|RC)/', PHPWG_VERSION)) {
    $template->assign('BETA_URL', $base_url.'&amp;beta-test=true');
}
$template->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
$template->assign('BETA_TEST', $beta_test);
$template->assign('page_data_json', json_encode([
    'str_confirm_msg'    => l10n('Yes, I am sure'),
    'str_cancel_msg'     => l10n('No, I have changed my mind'),
    'str_install_title'  => l10n('Are you sure you want to install the plugin "%s"?'),
    'str_x_month'        => l10n('%d month'),
    'str_x_months'       => l10n('%d months'),
    'str_x_year'         => l10n('%d year'),
    'str_x_years'        => l10n('%d years'),
    'str_from_begining'  => l10n('since the beginning'),
    'strs_certification' => [
        '-1' => l10n('This plugin is incompatible with your version'),
        '0'  => l10n('This plugin have no update since 3 years ! It may be outdated'),
        '1'  => l10n('This plugin has no recent update'),
        '2'  => l10n('This plugin was updated less than 6 months ago'),
        '3'  => l10n('This plugin have been updated recently'),
    ],
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugins');
