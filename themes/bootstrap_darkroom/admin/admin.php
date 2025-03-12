<?php

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\themes\bootstrap_darkroom\inc\Config;

// Check whether we are indeed included by Piwigo.
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

functions::load_language('theme.lang', PHPWG_THEMES_PATH.'bootstrap_darkroom/');

// Constants
define('THEME_ID', basename(dirname(dirname(__FILE__))));
define('ADMIN_PATH',   functions_url::get_root_url() . 'admin.php?page=theme&theme=' . THEME_ID);
define('TAB_SETTINGS', 'settings');
define('TAB_ABOUT', 'about');

// Get current tab
$page['tab'] = isset($_GET['tab']) ? $_GET['tab'] : $page['tab'] = TAB_SETTINGS;
if (!in_array($page['tab'], array(TAB_SETTINGS, TAB_ABOUT))) {
    $page['tab'] = TAB_SETTINGS;
}

$themeconfig = new Config();

// Save settings
if ($page['tab'] == TAB_SETTINGS) {
    if (isset($_POST['boostrap_darkroom_settings'])) {
        $themeconfig->fromPost($_POST);
        $themeconfig->save();
    }
}
// TabSheet
$tabsheet = new tabsheet();
$tabsheet->set_id('bsdark');
$tabsheet->add(TAB_SETTINGS, functions::l10n('Settings'), ADMIN_PATH . '&tab=' . TAB_SETTINGS);
$tabsheet->add(TAB_ABOUT, functions::l10n('About'), ADMIN_PATH . '&tab=' . TAB_ABOUT);
$tabsheet->select($page['tab']);
$tabsheet->assign();

// Fetch the template.
global $template;

// Add our template to the global template
$template->set_filenames(
    array(
        'theme_admin_content' => dirname(__FILE__) . '/template/' . $page['tab'] . '.tpl'
    )
);

// Assign the template contents to ADMIN_CONTENT
$template->assign('theme_config', $themeconfig);
$template->assign_var_from_handle('ADMIN_CONTENT', 'theme_admin_content');
