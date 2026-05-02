<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new Tabsheet();
$tabsheet->set_id('users');
$tabsheet->select($page['tab']);
$tabsheet->assign();
