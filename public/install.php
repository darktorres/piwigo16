<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
// Thin bootstrap shell (matching admin.php's shape). Orchestration lives
// in Piwigo\Admin\Install\InstallWizard; this file keeps only what's
// forbidden inside src/Piwigo by Arch rule SEC-60 (every define()) or
// must run before InstallWizard is constructed (the db_prefix override).
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

if (isset($_POST['install'])) {
    // Narrow to string (and guard the possibly-missing array key) rather than
    // trusting raw POST data downstream in SQL/file-content concatenation.
    $post_prefix = $_POST['prefix'] ?? null;
    $prefixeTable = is_string($post_prefix) ? $post_prefix : InstallWizard::DEFAULT_PREFIX_TABLE;
} else {
    $prefixeTable = InstallWizard::DEFAULT_PREFIX_TABLE;
}

// Piwigo\Db\Tables::*() (used throughout the wizard) reads
// DbCredentials::current()->prefix -- there's no database.inc.php/.env to
// read a real db_prefix from until this wizard writes one, so the
// user-chosen $prefixeTable must be seeded into the process environment
// directly here, or every Tables::*() call downstream would fall back to
// whatever's already there (a coincidental PIWIGO_DB_PREFIX env var, or
// the 'piwigo_' default) instead of the real chosen prefix.
$dbCredentials = InstallBootstrap::dbCredentials();
$dbCredentials->seed([
    'PIWIGO_DB_PREFIX' => $prefixeTable,
]);

// SessionBootstrap::register() carries the same internal PHPWG_INSTALLED
// guard, so it stays a no-op at this point of a fresh install.
SessionBootstrap::register();

// ---------------------------------------------------------------- orchestration
$wizard = new InstallWizard(
    RequestBootstrap::lang(),
    $prefixeTable,
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
            // SEC-60 keeps these define()s out of src/Piwigo: performInstall()
            // (and everything it reaches, e.g. the themes class needing
            // PWG_CHARSET to build fs_themes) relies on them being defined at
            // this point of the flow.
            defined('PHPWG_INSTALLED') or define('PHPWG_INSTALLED', true);
            defined('PWG_CHARSET') or define('PWG_CHARSET', 'utf-8');
            defined('DB_CHARSET') or define('DB_CHARSET', 'utf8');
            defined('DB_COLLATE') or define('DB_COLLATE', '');

            $wizard->performInstall();
        }
    }

    $wizard->render();
} catch (ResponseReadyException $e) {
    new ResponseEmitter()
        ->emit($e->response());
}
