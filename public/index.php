<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P22: page logic moved to Piwigo\Controller\GalleryController
// (config/routes.php's `/index.php` route); this file is now pure
// bootstrap + dispatch, matching every other P22 controller's root file.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// ----------------------------------------------------------- include
// P16 mints a real Paths for src/Piwigo/ code, passed to
// RequestBootstrap::bootEntryPoint() below. Legacy Coupling Retirement
// gap-closure (entry-shell
// define()/include round): PHPWG_ROOT_PATH (the former './', CWD-relative
// legacy constant every raw include and several URL-generation call sites
// used to read) is gone entirely -- filesystem paths go through $paths,
// URL generation goes through UrlService's own request-derived mount
// prefix instead of ever reading a filesystem-path constant, so nothing
// depends on a single string serving both purposes any more.
// Part II (web-root isolation): this file (and every sibling P22
// controller root file) now lives one directory below the true repo root
// (public/), matching admin/popuphelp.php's own pre-existing depth --
// Paths::fromRoot(dirname(__DIR__)) instead of the former
// Paths::fromIndex(__FILE__), which would resolve to public/ itself. No
// change to Router::MOUNT_DEPTH_ATTRIBUTE/RequestMountDepth: that fact is
// about SCRIPT_NAME depth below DocumentRoot, unaffected since
// DocumentRoot moves to public/ together with every entry file (see
// Router's own docblock).
$paths = Paths::fromRoot(dirname(__DIR__));
RequestBootstrap::bootEntryPoint($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
