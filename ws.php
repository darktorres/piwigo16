<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\functions_html;
use Piwigo\inc\functions_user;

define('PHPWG_ROOT_PATH', './');
define('IN_WS', true);

include_once(PHPWG_ROOT_PATH . 'inc/common.php');
functions_user::check_status(ACCESS_FREE);

if (! $conf['allow_web_services']) {
    functions_html::page_forbidden('Web services are disabled');
}

include_once(PHPWG_ROOT_PATH . 'inc/ws_init.php');

$service->run();
