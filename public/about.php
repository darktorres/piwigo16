<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P22 proof-of-concept: the real PSR-15 pipeline (dormant since P9) is now
// live traffic's actual dispatcher. Page logic itself moved to
// Piwigo\Controller\AboutController (config/routes.php's `/about.php`
// route); this file is now pure bootstrap + dispatch, matching every other
// P22 controller's own root-file shape.
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
