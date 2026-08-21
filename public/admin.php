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
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;
use Psr\Http\Message\ResponseInterface;

$paths = Paths::fromRoot(dirname(__DIR__));

RequestBootstrap::bootEntryPoint($paths, isAdmin: true);

// admin.php never calls RequestPipeline::handle() -- AdminShell::run() is
// its own, deliberately separate dispatcher (workstream C3 Phase 3,
// "reconcile AdminShell/admin.php", investigation only). But every other
// real entry point reaches AdminShell's own dependencies (CurrentConfig,
// CurrentUser, the plugin registry, a loaded Lang, ...) only because
// RequestPipeline::handle()'s own BOOTSTRAP_MIDDLEWARE already ran first
// -- run it here too, the one thing this file must do that no other
// admin-adjacent entry point needs to (RequestPipeline::BOOTSTRAP_MIDDLEWARE's
// own docblock explains why this file alone needs the explicit call).
$bootstrapPhaseResponse = RequestPipeline::runBootstrapPhase(RequestFactory::fromGlobals());
if ($bootstrapPhaseResponse instanceof ResponseInterface) {
    new ResponseEmitter()
        ->emit($bootstrapPhaseResponse);
    exit;
}

new AdminShell(RequestBootstrap::lang(), RequestBootstrap::accessControl(), new RedirectService(RequestBootstrap::lang(), RequestBootstrap::userService(), RequestBootstrap::eventDispatcher(), RequestBootstrap::layoutState(), RequestBootstrap::templateRenderer()), RequestBootstrap::urlService(), RequestBootstrap::currentConfigService()->get(), RequestBootstrap::filesystemIntegrityChecker(), RequestBootstrap::coreTabs(), RequestBootstrap::sessionService(), RequestBootstrap::eventDispatcher(), RequestBootstrap::deploymentPolicy(), RequestBootstrap::pageState(), RequestBootstrap::layoutState(), RequestBootstrap::currentUser(), RequestBootstrap::currentTemplate(), RequestBootstrap::commentService(), RequestBootstrap::imageService(), RequestBootstrap::preferencesService(), RequestBootstrap::userService(), RequestBootstrap::htmlService(), RequestBootstrap::currentConfig(), RequestBootstrap::inputValidator(), RequestBootstrap::entityManager(), RequestBootstrap::templateRenderer())
    ->run();
