<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P22: page logic moved to Piwigo\Controller\ProfileController
// (config/routes.php's `/profile.php` route); this file is now pure
// bootstrap + dispatch, matching every other P22 controller's root file.
require __DIR__ . '/vendor/autoload.php';

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// ----------------------------------------------------------- include
$paths = Paths::fromIndex(__FILE__);
include_once $paths->root . 'include/common.inc.php';

CommonBootstrap::run($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
