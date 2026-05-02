<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;

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


$my_base_url = get_root_url().'admin.php?page=themes';

if (isset($_GET['tab'])) {
    $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'installed';
} else {
    $page['tab'] = 'installed';
}

$tabsheet = new Tabsheet();
$tabsheet->set_id('themes');
$tabsheet->select($page['tab']);
$tabsheet->assign();

if ($page['tab'] == 'update') {
    include(PHPWG_ROOT_PATH.'admin/updates_ext.php');
    $template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
} else {
    include(PHPWG_ROOT_PATH.'admin/themes_'.$page['tab'].'.php');
}
