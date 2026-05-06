<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_GUEST);

// if (empty($_GET['q']))
// {
//   redirect( make_index_url() );
// }

$q = isset($_GET['q']) ? (string)$_GET['q'] : '';
redirect(add_url_params(\Piwigo\Core\ServiceLocator::get(\Piwigo\Url\UrlGenerator::class)->searchPage(), ['q' => $q]));
