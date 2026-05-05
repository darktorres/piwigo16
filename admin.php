<?php

declare(strict_types=1);

use Piwigo\Http\RequestFactory;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Admin entry-point shim — define IN_ADMIN before common.inc.php so that
// any bootstrap code that checks it sees the correct value.

define('PHPWG_ROOT_PATH', './');
define('IN_ADMIN', true);
require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
\Piwigo\Core\Kernel::boot();
(new \Piwigo\Controller\Admin\AdminController)(RequestFactory::fromGlobals());
