<?php

declare(strict_types=1);

use Piwigo\Controller\WsController;
use Piwigo\Core\Kernel;
use Piwigo\Http\RequestFactory;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Legacy entry-point shim — routes WS requests through WsController.
// We do NOT run ResponseEmitter here: PwgServer::run() sends its own
// headers via header() and echo, and calling http_response_code() after
// that triggers a PHP 8 warning.  WsController is invoked directly;
// its return value (an empty 200 response) is intentionally discarded.

define('PHPWG_ROOT_PATH', './');
define('IN_WS', true);

require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
Kernel::boot();

$wsRest = is_string($_SERVER['PATH_INFO'] ?? null) ? $_SERVER['PATH_INFO'] : '';
(new WsController())(RequestFactory::fromGlobals(), ['rest' => $wsRest]);
