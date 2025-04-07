<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

const PHPWG_ROOT_PATH = './';
require_once __DIR__ . '/inc/common.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_GUEST);

// if (empty($_GET['q']))
// {
//   \Piwigo\inc\functions::redirect( \Piwigo\inc\functions_url::make_index_url() );
// }

functions::redirect(functions_url::get_root_url() . 'search.php?q=' . $_GET['q']);
