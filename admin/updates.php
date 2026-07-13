<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var array<string, mixed> $conf */
global $conf;

if (! (bool) $conf['enable_extensions_install'] and ! (bool) $conf['enable_core_update']) {
    die('update system is disabled');
}

$my_base_url = get_root_url() . 'admin.php?page=updates';

// SECURITY: unlike plugins.php/themes.php/languages.php's own tab dispatch,
// this file never validated $_GET['tab'] before splicing it into the
// include path below -- an authenticated admin could reach
// `include admin/updates_<tab>.php` with an arbitrary $tab (e.g.
// `../../include/functions`), a real local file inclusion, not just a
// hypothetical one. Fixed to match the sibling files' own allowlist.
/** @var array<string, mixed> $page */
check_input_parameter('tab', $_GET, false, '/^(pwg|ext)$/');
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
