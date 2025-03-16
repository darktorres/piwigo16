<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions_url;

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('users');
$tabsheet->select($page['tab']);
$tabsheet->assign();
