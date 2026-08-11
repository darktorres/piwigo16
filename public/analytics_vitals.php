<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// web-vitals RUM beacon endpoint. Bare bootstrap + dispatch, same shape
// as every other root controller file (e.g. about.php) — .htaccess
// rewrites the clean /analytics/vitals URL
// here; RouteDefinitions's own route matches that clean path, not this
// filename (Router::pathInfo() falls back to the raw REQUEST_URI once
// SCRIPT_NAME stops being a prefix of it, same mechanism the bare "/" ->
// index_directory_root route already relies on).
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
