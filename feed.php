<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Http\RequestFactory;

// Legacy entry-point shim — routes through FeedController.
define('PHPWG_ROOT_PATH', './');
require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
Kernel::boot();
(new \Piwigo\Controller\FeedController)(RequestFactory::fromGlobals());
