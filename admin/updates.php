<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;
use Piwigo\Config\Config;
use Piwigo\Exception\AuthException;
use Piwigo\Exception\ConfigException;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

if (!Config::enableExtensionsInstall() and !Config::enableCoreUpdate()) {
    throw new ConfigException('update system is disabled');
}

$my_base_url = get_root_url().'admin.php?page=updates';

if (isset($_GET['tab'])) {
    $page['tab'] = is_string($_GET['tab']) ? $_GET['tab'] : 'pwg';
} else {
    $page['tab'] = 'pwg';
}

$tabsheet = new Tabsheet();
$tabsheet->set_id('updates');
$tabsheet->select($page['tab']);
$tabsheet->assign();

require(PHPWG_ROOT_PATH.'admin/updates_'.$page['tab'].'.php');
