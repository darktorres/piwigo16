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
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

$paths = Paths::fromRoot(dirname(__DIR__));

\Piwigo\Bootstrap\RequestBootstrap::bootEntryPoint($paths, isAdmin: true);

new AdminShell(new RedirectService(), new UrlService(new HtmlService()), CurrentConfigService::get(), $paths, \Piwigo\Bootstrap\RequestBootstrap::filesystemIntegrityChecker(), \Piwigo\Bootstrap\RequestBootstrap::coreTabs(), \Piwigo\Bootstrap\RequestBootstrap::sessionService(), \Piwigo\Bootstrap\RequestBootstrap::eventDispatcher(), \Piwigo\Bootstrap\RequestBootstrap::deploymentPolicy(), \Piwigo\Bootstrap\RequestBootstrap::pageState())
    ->run();
