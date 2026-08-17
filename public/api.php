<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

// The /api/v1 REST surface -- public/.htaccess rewrites the clean
// /api/v1/... URL here; RouteDefinitions's own routes match that clean
// path, not this filename (same mechanism as analytics_vitals.php).
// isApi: true marks this request as machine-facing (ApiContext) --
// UrlService::addUrlParams() uses it to emit raw `&` separators instead
// of HTML-entity-escaped `&amp;` in generated URLs.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

$paths = Paths::fromRoot(dirname(__DIR__));
RequestBootstrap::bootEntryPoint($paths, isApi: true);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
