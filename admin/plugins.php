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
/**
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $page, $template;

$my_base_url = get_root_url() . 'admin.php?page=plugins';

if (isset($_GET['tab'])) {
    check_input_parameter('tab', $_GET, false, '/^(installed|update|new)$/');
    // check_input_parameter() validates the raw value against the pattern
    // above but only ever returns true|never -- it doesn't narrow $_GET's
    // type for static analysis -- $_GET values are string|array<mixed> at
    // best, so re-check it is a string before trusting it as the tab name.
    $tab = $_GET['tab'];
    $page['tab'] = is_string($tab) ? $tab : 'installed';
} else {
    $page['tab'] = 'installed';
}

$tabsheet = new tabsheet();
$tabsheet->set_id('plugins');
$tabsheet->select($page['tab']);
$tabsheet->assign();

if ($page['tab'] == 'update') {
    include PHPWG_ROOT_PATH . 'admin/updates_ext.php';
    $template->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
} else {
    include PHPWG_ROOT_PATH . 'admin/plugins_' . $page['tab'] . '.php';
}
