<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
// Thin bootstrap shell (matching admin.php's shape). Orchestration lives
// in Piwigo\Admin\Install\InstallWizard; kept as a separate entry point for
// the same reason every other public/*.php file is: autoload bootstrap
// (requiring vendor/autoload.php) has to happen before any Piwigo\ class,
// including Paths::fromRoot() below, can be referenced.
use Piwigo\Admin\Install\InstallWizard;
use Piwigo\Bootstrap\InstallBootstrap;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Bootstrap\SessionBootstrap;
use Piwigo\Core\Env;
use Piwigo\Core\Paths;
use Piwigo\Http\ResponseEmitter;
use Piwigo\Http\ResponseReadyException;

// vendor/autoload.php must be required directly here -- Paths::fromRoot()
// below is a Piwigo\ class, so the autoloader must already be active
// before it's referenced, matching every other real entry point.
require __DIR__ . '/../vendor/autoload.php';

$paths = Paths::fromRoot(dirname(__DIR__));

Env::loadEnvFile($paths->root);

InstallBootstrap::boot($paths);

// ----------------------------------------------------- variable initialization

$dbCredentials = InstallBootstrap::dbCredentials();

// SessionBootstrap::register() carries the same internal PHPWG_INSTALLED
// guard, so it stays a no-op at this point of a fresh install.
SessionBootstrap::register();

// ---------------------------------------------------------------- orchestration
$wizard = new InstallWizard(
    RequestBootstrap::lang(),
    $paths,
    $dbCredentials,
    RequestBootstrap::currentConfigService(),
    RequestBootstrap::currentConfig(),
    RequestBootstrap::inputValidator(),
    RequestBootstrap::adminContext(),
    RequestBootstrap::eventDispatcher(),
    RequestBootstrap::pageState(),
    RequestBootstrap::errorCollector(),
    RequestBootstrap::processCache(),
    RequestBootstrap::deploymentPolicy(),
    RequestBootstrap::currentTemplate(),
    RequestBootstrap::currentUser(),
);

// InstallWizard::boot()'s own "PHP extension mysqli is not loaded"/
// "Piwigo is already installed" checks call HtmlService::fatalError(),
// which throws ResponseReadyException -- this file has no
// RequestPipeline::handle() to catch it downstream, so it needs its own
// catch point.
try {
    // InstallWizard::boot() itself calls InstallBootstrap::
    // activateConfigService() partway through its own body -- its own
    // Template construction at the end needs it active before this call
    // returns, so it can't wait until here.
    $wizard->boot();

    if (isset($_POST['install'])) {
        $wizard->analyzeForm();

        if (! $wizard->hasErrors()) {
            // Marks the container-shared InstallationFlag active for the
            // rest of this request -- performInstall() (and everything it
            // reaches) relies on isActive() being true from this point on,
            // the same point the former raw PHPWG_INSTALLED define() sat.
            RequestBootstrap::installationFlag()->mark();

            $wizard->performInstall();
        }
    }

    $wizard->render();
} catch (ResponseReadyException $e) {
    new ResponseEmitter()
        ->emit($e->response());
}
