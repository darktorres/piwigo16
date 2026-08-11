<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// The PSR-15 middleware pipeline (RequestPipeline::handle()) is live
// traffic's actual dispatcher. Page logic lives in
// Piwigo\Controller\AboutController (RouteDefinitions's `/about.php`
// route); this file is pure bootstrap + dispatch.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

$paths = Paths::fromRoot(dirname(__DIR__));
RequestBootstrap::bootEntryPoint($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
