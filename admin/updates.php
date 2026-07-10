<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
global $conf;

if (! (bool) $conf['enable_extensions_install'] and ! (bool) $conf['enable_core_update']) {
    die('update system is disabled');
}

include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';

$my_base_url = get_root_url() . 'admin.php?page=updates';

/** @var array<string, mixed> $page */
if (isset($_GET['tab']) && is_string($_GET['tab'])) {
    $page['tab'] = $_GET['tab'];
} else {
    $page['tab'] = 'pwg';
}

$tabsheet = new tabsheet();
$tabsheet->set_id('updates');
$tabsheet->select($page['tab']);
$tabsheet->assign();

include PHPWG_ROOT_PATH . 'admin/updates_' . $page['tab'] . '.php';
