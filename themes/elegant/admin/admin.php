<?php

// Need upgrade?
global $conf;

use Piwigo\inc\functions;

include(PHPWG_THEMES_PATH . 'elegant/admin/upgrade.php');

functions::load_language('theme.lang', PHPWG_THEMES_PATH . 'elegant/');

$config_send = [];

if (isset($_POST['submit_elegant'])) {
    $config_send['p_main_menu'] = (isset($_POST['p_main_menu']) and ! empty($_POST['p_main_menu'])) ? $_POST['p_main_menu'] : 'on';
    $config_send['p_pict_descr'] = (isset($_POST['p_pict_descr']) and ! empty($_POST['p_pict_descr'])) ? $_POST['p_pict_descr'] : 'on';
    $config_send['p_pict_comment'] = (isset($_POST['p_pict_comment']) and ! empty($_POST['p_pict_comment'])) ? $_POST['p_pict_comment'] : 'off';

    functions::conf_update_param('elegant', $config_send, true);

    array_push($page['infos'], functions::l10n('Information data registered in database'));
}

$template->set_filenames([
    'theme_admin_content' => dirname(__FILE__) . '/admin.tpl',
]);

$template->assign('options', functions::safe_unserialize($conf['elegant']));

$template->assign_var_from_handle('ADMIN_CONTENT', 'theme_admin_content');
