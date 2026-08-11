<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Page logic lives in Piwigo\Controller\ActionController (RouteDefinitions's
// `/action.php` route); this file is pure bootstrap + dispatch.
// session_cache_limiter('public') stays here (not in the controller) --
// it must run before session_start(), which RequestBootstrap::
// bootEntryPoint() triggers directly below, well before
// RequestPipeline::handle() dispatches to the controller.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

$paths = Paths::fromRoot(dirname(__DIR__));
session_cache_limiter('public');
RequestBootstrap::bootEntryPoint($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
