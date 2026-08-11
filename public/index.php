<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Page logic lives in Piwigo\Controller\GalleryController
// (RouteDefinitions's `/index.php` route); this file is pure
// bootstrap + dispatch, matching every other controller's root file.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// Filesystem paths go through $paths; URL generation goes through
// UrlService's own request-derived mount prefix -- no single constant or
// string serves both purposes.
//
// This file lives one directory below the true repo root (public/),
// matching admin/popuphelp.php's own depth, so
// Paths::fromRoot(dirname(__DIR__)) is used to resolve the repo root
// correctly. Router::MOUNT_DEPTH_ATTRIBUTE/RequestMountDepth track
// SCRIPT_NAME depth below DocumentRoot, which is public/ for every entry
// file (see Router's own docblock).
$paths = Paths::fromRoot(dirname(__DIR__));
RequestBootstrap::bootEntryPoint($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
