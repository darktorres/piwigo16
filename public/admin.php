<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// P23 batch 10: page-shell orchestration moved to Piwigo\Admin\AdminShell;
// this file is now pure bootstrap + dispatch, matching index.php's own
// final form.

// vendor/autoload.php must be required directly here -- Paths::fromRoot()
// below and RequestBootstrap::bootEntryPoint() are both Piwigo\ classes,
// so the autoloader must already be active before either is referenced.
// Mirrors index.php's own ordering exactly.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Admin\AdminShell;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Core\Paths;

$paths = Paths::fromRoot(dirname(__DIR__));

\Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths, isAdmin: true);

new AdminShell(\Piwigo\Bootstrap\RequestBootstrap::lang(), \Piwigo\Auth\AccessControl::current(), new RedirectService(\Piwigo\Bootstrap\RequestBootstrap::lang(), \Piwigo\Bootstrap\RequestBootstrap::userService()), \Piwigo\Bootstrap\RequestBootstrap::urlService(), \Piwigo\Bootstrap\RequestBootstrap::currentConfigService()->get(), $paths, \Piwigo\Bootstrap\RequestBootstrap::filesystemIntegrityChecker(), \Piwigo\Bootstrap\RequestBootstrap::coreTabs(), \Piwigo\Bootstrap\RequestBootstrap::sessionService(), \Piwigo\Bootstrap\RequestBootstrap::eventDispatcher(), \Piwigo\Bootstrap\RequestBootstrap::deploymentPolicy(), \Piwigo\Bootstrap\RequestBootstrap::pageState(), \Piwigo\Bootstrap\RequestBootstrap::currentUser(), \Piwigo\Bootstrap\RequestBootstrap::currentTemplate(), \Piwigo\Bootstrap\RequestBootstrap::commentService(), \Piwigo\Bootstrap\RequestBootstrap::imageService(), \Piwigo\Bootstrap\RequestBootstrap::preferencesService(), \Piwigo\Bootstrap\RequestBootstrap::userService(), \Piwigo\Bootstrap\RequestBootstrap::htmlService(), \Piwigo\Bootstrap\RequestBootstrap::currentConfig())
    ->run();
