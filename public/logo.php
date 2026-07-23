<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Serves the standard_pages theme's configured custom logo -- see
// Piwigo\Controller\CustomLogoController's own docblock. A plain,
// low-traffic, fully-public page: no reason to deviate from the standard
// public/*.php shape every other non-i.php entry file uses.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

// ----------------------------------------------------------- include
$paths = Paths::fromRoot(dirname(__DIR__));
\Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths);

CommonBootstrap::run($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals());
new ResponseEmitter()
    ->emit($response);
