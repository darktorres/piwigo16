<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Http\RequestFactory;

// Legacy entry-point shim — routes through ProfileController.
// Admin code that previously included profile.php for its helper functions
// (save_profile_from_post, load_profile_in_template) should require
// include/profile_functions.php directly instead.
define('PHPWG_ROOT_PATH', './');
require_once PHPWG_ROOT_PATH . 'include/common.inc.php';
Kernel::boot();
(new \Piwigo\Controller\ProfileController)(RequestFactory::fromGlobals());
