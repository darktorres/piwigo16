<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

// P23 batch 6a: page logic moved to
// Piwigo\Controller\Admin\AdminPopuphelpController (config/routes.php's
// `/admin/popuphelp.php` route); this file is now pure bootstrap +
// dispatch, matching every other P22/P23 controller's own root-file shape.
// Part II (web-root isolation): now two directories deeper than the true
// repo root (public/admin/, not just admin/) -- Paths::
// fromRoot(dirname(__DIR__, 2)) points at the real root instead of
// Paths::fromIndex(__FILE__), which would resolve to public/admin/. The
// *web* mount depth below DocumentRoot (RequestMountDepth::set(1) /
// Router::MOUNT_DEPTH_ATTRIBUTE) stays 1, unchanged from before Part II --
// that fact is about SCRIPT_NAME depth below DocumentRoot, and
// DocumentRoot moves to public/ together with this file, so this file's
// depth *relative to DocumentRoot* is exactly what it always was (see
// Router's own docblock for the real live bug this mechanism fixes, and
// why it isn't derived from real filesystem paths). RequestMountDepth::
// set() below carries the same fact to UrlService::getRootUrl() (outbound
// link generation) -- set as early as possible, before
// RequestBootstrap::bootEntryPoint() runs, since URL generation can be
// reached from deep inside that bootstrap chain, well before the request
// object (and its own MOUNT_DEPTH_ATTRIBUTE) exists.
require __DIR__ . '/../../vendor/autoload.php';

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\AdminContext;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Routing\Router;

$paths = Paths::fromRoot(dirname(__DIR__, 2));
RequestMountDepth::set(1);
AdminContext::mark();
\Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths);

CommonBootstrap::run($paths);

$request = RequestFactory::fromGlobals()->withAttribute(Router::MOUNT_DEPTH_ATTRIBUTE, 1);
$response = RequestPipeline::handle($request);
new ResponseEmitter()
    ->emit($response);
