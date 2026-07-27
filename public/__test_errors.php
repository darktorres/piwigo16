<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Test-mode-only error drain (docs/PLAN.md finding #7). Bare
// bootstrap + dispatch, matching every other clean-URL root controller's own
// shape (e.g. analytics_vitals.php) — public/.htaccess rewrites the clean
// /__test/errors URL here; config/routes.php's own route matches that clean
// path, not this filename. Piwigo\Controller\TestErrorsController itself
// 404s outside test mode, so this file is safe to be a real, reachable entry
// point rather than needing to be hidden.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// ----------------------------------------------------------- include
$paths = Paths::fromRoot(dirname(__DIR__));
\Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
