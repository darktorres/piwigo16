<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RequestBootstrap;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Page-shell orchestration lives in Piwigo\Admin\AdminShell; this file is
// pure bootstrap + dispatch, matching index.php's own shape.

// vendor/autoload.php must be required directly here -- Paths::fromRoot()
// below and RequestBootstrap::bootEntryPoint() are both Piwigo\ classes,
// so the autoloader must already be active before either is referenced.
// Mirrors index.php's own ordering exactly.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Admin\AdminShell;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Core\Paths;

$paths = Paths::fromRoot(dirname(__DIR__));

RequestBootstrap::bootEntryPoint($paths, isAdmin: true);

new AdminShell(RequestBootstrap::lang(), RequestBootstrap::accessControl(), new RedirectService(RequestBootstrap::lang(), RequestBootstrap::userService(), RequestBootstrap::eventDispatcher(), RequestBootstrap::pageState()), RequestBootstrap::urlService(), RequestBootstrap::currentConfigService()->get(), $paths, RequestBootstrap::filesystemIntegrityChecker(), RequestBootstrap::coreTabs(), RequestBootstrap::sessionService(), RequestBootstrap::eventDispatcher(), RequestBootstrap::deploymentPolicy(), RequestBootstrap::pageState(), RequestBootstrap::currentUser(), RequestBootstrap::currentTemplate(), RequestBootstrap::commentService(), RequestBootstrap::imageService(), RequestBootstrap::preferencesService(), RequestBootstrap::userService(), RequestBootstrap::htmlService(), RequestBootstrap::currentConfig(), RequestBootstrap::inputValidator())
    ->run();
