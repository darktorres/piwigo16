<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\Url\UrlGenerator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


$my_base_url = ServiceLocator::get(UrlGenerator::class)->admin('plugins');

if (isset($_GET['tab'])) {
    $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'installed';
} else {
    $page['tab'] = 'installed';
}

$tabsheet = new Tabsheet();
$tabsheet->set_id('plugins');
$tabsheet->select($page['tab']);
$tabsheet->assign();

if ($page['tab'] == 'update') {
    require(PHPWG_ROOT_PATH.'admin/updates_ext.php');
    $template->assign('ADMIN_PAGE_TITLE', l10n('Plugins'));
} else {
    require(PHPWG_ROOT_PATH.'admin/plugins_'.$page['tab'].'.php');
}
