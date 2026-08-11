<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

// Page logic lives in Piwigo\Controller\Admin\AdminPopuphelpController
// (RouteDefinitions's `/admin/popuphelp.php` route); this file is pure
// bootstrap + dispatch, matching every other controller's root-file shape.
// This file sits two directories below the real repo root (public/admin/,
// not just admin/) -- Paths::fromRoot(dirname(__DIR__, 2)) points at the
// real root instead of Paths::fromIndex(__FILE__), which would resolve to
// public/admin/. The *web* mount depth below DocumentRoot
// (bootEntryPoint()'s own mountDepth: 1 param / Router::MOUNT_DEPTH_ATTRIBUTE)
// stays 1 -- that fact is about SCRIPT_NAME depth below DocumentRoot, and
// DocumentRoot is public/ (the same directory this file lives under), so
// this file's depth *relative to DocumentRoot* is 1 (see Router's own
// docblock for the mechanism this fixes and why it isn't derived from real
// filesystem paths). bootEntryPoint()'s mountDepth param, threaded down
// into RequestMountDepth via Container::build(), carries the same fact to
// UrlService::getRootUrl() (outbound link generation) -- available as
// soon as the container is built, since URL generation can be reached
// from deep inside that bootstrap chain, well before the request object
// (and its own MOUNT_DEPTH_ATTRIBUTE) exists.
require __DIR__ . '/../../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Routing\Router;

$paths = Paths::fromRoot(dirname(__DIR__, 2));
RequestBootstrap::bootEntryPoint($paths, mountDepth: 1, isAdmin: true);

$request = RequestFactory::fromGlobals()->withAttribute(Router::MOUNT_DEPTH_ATTRIBUTE, 1);
$response = RequestPipeline::handle($request);
new ResponseEmitter()
    ->emit($response);
