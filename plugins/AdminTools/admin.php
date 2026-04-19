<?php

declare(strict_types=1);

use Piwigo\inc\functions;

if (! defined('ADMINTOOLS_PATH')) {
    exit('Hacking attempt!');
}

if (isset($_POST['save_config'])) {
    $conf->AdminTools = [
        'default_open' => isset($_POST['default_open']),
        'closed_position' => $_POST['closed_position'],
        'public_quick_edit' => isset($_POST['public_quick_edit']),
    ];

    functions::conf_update_param('AdminTools', $conf->AdminTools);
    $page['infos'][] = functions::l10n('Information data registered in database');
}

$adminToolsDefaults = [
    'default_open' => false,
    'closed_position' => 'right',
    'public_quick_edit' => false,
];
$adminToolsConf = is_array($conf->AdminTools) ? array_merge($adminToolsDefaults, $conf->AdminTools) : $adminToolsDefaults;

$template->assign([
    'AdminTools' => $adminToolsConf,
    'ADMINTOOLS_PATH' => substr(ADMINTOOLS_PATH, 2),
]);

require_once PHPWG_ROOT_PATH . 'inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, [
    'at_admin' => 'plugins/AdminTools/js/admin',
]);

$template->set_filename('admintools_content', realpath(ADMINTOOLS_PATH . 'template/admin.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'admintools_content');
