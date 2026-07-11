<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;

// Bootstrap global, set by include/common.inc.php.
/** @var array<string, mixed> $page */
global $page;

$my_base_url = get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('users');
$page_tab = $page['tab'] ?? null;
$tabsheet->select(is_string($page_tab) ? $page_tab : '');
$tabsheet->assign();
