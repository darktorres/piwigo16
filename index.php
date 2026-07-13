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
// P16 mints a real Paths for src/Piwigo/ code (passed to CommonBootstrap::
// run() below), but PHPWG_ROOT_PATH itself deliberately keeps its exact
// legacy value ('./', CWD-relative) rather than switching to $paths->root
// (absolute) -- found empirically that several legacy call sites
// (get_root_url() in functions_url.inc.php, section_init.inc.php, i.php)
// hard-assume PHPWG_ROOT_PATH's literal string shape to compute relative
// URL prefixes for generated links (`str_starts_with($x, './')` etc.),
// not just filesystem include paths. Switching to an absolute value broke
// every generated href/src on the live site (confirmed via a real curl
// request). Fixing those call sites is real legacy-logic surgery, not
// bootstrap wiring -- out of scope here, matching this project's
// established "typed source of truth for new code, legacy stays
// unchanged until its own domain migrates" discipline (P17-23).
$paths = Paths::fromIndex(__FILE__);
define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

CommonBootstrap::run($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
