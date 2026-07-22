<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P22: page logic moved to Piwigo\Controller\GalleryController
// (config/routes.php's `/index.php` route); this file is now pure
// bootstrap + dispatch, matching every other P22 controller's root file.
require __DIR__ . '/vendor/autoload.php';

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// ----------------------------------------------------------- include
// P16 mints a real Paths for src/Piwigo/ code, passed to CommonBootstrap::
// run() below. Legacy Coupling Retirement gap-closure (entry-shell
// define()/include round): PHPWG_ROOT_PATH (the former './', CWD-relative
// legacy constant every raw include and several URL-generation call sites
// used to read) is gone entirely -- filesystem paths go through $paths,
// URL generation goes through UrlService's own request-derived mount
// prefix instead of ever reading a filesystem-path constant, so nothing
// depends on a single string serving both purposes any more.
$paths = Paths::fromIndex(__FILE__);
include_once $paths->root . 'include/common.inc.php';

CommonBootstrap::run($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
