<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// +-----------------------------------------------------------------------+

// P23 batch 6a: page logic moved to
// Piwigo\Controller\Admin\AdminPopuphelpController (config/routes.php's
// `/admin/popuphelp.php` route); this file is now pure bootstrap +
// dispatch, matching every other P22/P23 controller's own root-file shape.
// One directory deeper than the true repo root, unlike every sibling root
// file -- Paths::fromRoot(dirname(__DIR__)) points at the real root
// instead of Paths::fromIndex(__FILE__), which would resolve to admin/.
// That same extra depth is also attached to the request itself
// (Router::MOUNT_DEPTH_ATTRIBUTE) so Router::pathInfo() strips the right
// number of SCRIPT_NAME directory levels -- see that class's own docblock
// for the real live bug this fixes and why it isn't derived from real
// filesystem paths (this dev instance serves the app through a symlink,
// so SCRIPT_FILENAME and PHP's own resolved paths are never comparable).
// RequestMountDepth::set() below carries the same fact to
// UrlService::getRootUrl() (outbound link generation) -- set as early as
// possible, before include/common.inc.php runs, since URL generation can
// be reached from deep inside that bootstrap chain, well before the
// request object (and its own MOUNT_DEPTH_ATTRIBUTE) exists.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Core\RequestMountDepth;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Routing\Router;

$paths = Paths::fromRoot(dirname(__DIR__));
RequestMountDepth::set(1);
define('IN_ADMIN', true);
include_once $paths->root . 'include/common.inc.php';

CommonBootstrap::run($paths);

$request = RequestFactory::fromGlobals()->withAttribute(Router::MOUNT_DEPTH_ATTRIBUTE, 1);
$response = RequestPipeline::handle($request);
new ResponseEmitter()
    ->emit($response);
