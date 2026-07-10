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
/** @var array<string, mixed> $page */
global $page;
/** @var \Template $template */
global $template;

include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';

$my_base_url = get_root_url() . 'admin.php?page=languages';

if (isset($_GET['tab'])) {
    check_input_parameter('tab', $_GET, false, '/^(installed|update|new)$/');
    // check_input_parameter() validates the raw value against the pattern
    // above (fatal_error()-ing on anything else) but does not narrow its
    // type for static analysis -- $_GET values are string|array<mixed> at
    // best, so re-check it is a string before trusting it as the tab name.
    $tab = $_GET['tab'];
    $page['tab'] = is_string($tab) ? $tab : 'installed';
} else {
    $page['tab'] = 'installed';
}

$tabsheet = new tabsheet();
$tabsheet->set_id('languages');
$tabsheet->select($page['tab']);
$tabsheet->assign();

if ($page['tab'] == 'update') {
    include PHPWG_ROOT_PATH . 'admin/updates_ext.php';
    $template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
} else {
    include PHPWG_ROOT_PATH . 'admin/languages_' . $page['tab'] . '.php';
}
