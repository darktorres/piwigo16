<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
Kernel::boot();

new ResponseEmitter()->emit(
    Kernel::handle(RequestFactory::fromGlobals())
);
