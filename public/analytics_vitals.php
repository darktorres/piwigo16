<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// web-vitals RUM beacon endpoint (docs/PLAN-REPLAY.md P1, item 11b). Bare
// bootstrap + dispatch, matching every other root controller's own shape
// (e.g. about.php) — .htaccess rewrites the clean /analytics/vitals URL
// here; config/routes.php's own route matches that clean path, not this
// filename (Router::pathInfo() falls back to the raw REQUEST_URI once
// SCRIPT_NAME stops being a prefix of it, same mechanism the bare "/" ->
// index_directory_root route already relies on).
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// ----------------------------------------------------------- include
$paths = Paths::fromRoot(dirname(__DIR__));
include_once $paths->root . 'include/common.inc.php';

CommonBootstrap::run($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
