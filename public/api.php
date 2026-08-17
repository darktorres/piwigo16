<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

// The /api/v1 REST surface (P27) -- public/.htaccess rewrites the clean
// /api/v1/... URL here; RouteDefinitions's own routes match that clean
// path, not this filename (same mechanism as analytics_vitals.php).
// isWs: true so UserResolutionMiddleware resolves api_key credentials the
// same way it already does for ws.php -- this surface is WS's real
// replacement, the same "machine API" audience.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

$paths = Paths::fromRoot(dirname(__DIR__));
RequestBootstrap::bootEntryPoint($paths, isWs: true);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
