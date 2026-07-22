<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// Workstream C3 Part III: joins the real routing pipeline like every other
// P22/P23 root file (same Paths::fromRoot()/CommonBootstrap::run()/
// RequestPipeline::handle()/ResponseEmitter shape) -- but does NOT
// include/common.inc.php (RequestBootstrap::configure()/connect()/
// finalize(): plugin loading, native session bootstrap, template init),
// and is dispatched with RequestPipeline::WITHOUT_SESSION instead of the
// default 7-middleware list. See Piwigo\Controller\
// ImageDerivativeController's own docblock for why: this endpoint's own
// permission check is already a direct, DB-backed SessionUserResolver/
// SessionRepository lookup that never touches native $_SESSION, and it
// serves no template/plugin-hookable content at all -- the highest-QPS
// endpoint in the app has no use for either.
require __DIR__ . '/../vendor/autoload.php';

use Piwigo\Bootstrap\CommonBootstrap;
use Piwigo\Bootstrap\RequestPipeline;
use Piwigo\Core\Env;
use Piwigo\Core\Paths;
use Piwigo\Http\RequestFactory;
use Piwigo\Http\ResponseEmitter;

$paths = Paths::fromRoot(dirname(__DIR__));

// Found live (a real, immediate 500 -- Doctrine trying to connect with
// empty credentials): every other entry file's own include/common.inc.php
// loads .env overrides via RequestBootstrap::configure() before
// CommonBootstrap::run() ever touches the DB (ConfigService::
// loadConfFromDb()); this file deliberately skips common.inc.php entirely,
// so nothing did. Env::loadEnvFile() is idempotent (same "requiring twice
// is safe" contract every autoload/env include in this codebase already
// relies on) -- ImageDerivativeController::__invoke() still calls it again
// internally, unchanged, per its own "keep the existing bootstrap exactly
// as today" design.
Env::loadEnvFile($paths->root);

// Real before/after latency measurement (this phase's own explicit
// verification requirement, not assumed): CommonBootstrap::run() itself,
// under the classic Apache+mod_php dev environment this was measured
// against (a fresh PHP process per request, not a persistent worker),
// roughly doubles a cache-hit derivative request's latency (~12ms -> ~23ms
// average, 20-request samples, back-to-back on the same machine) versus
// this file's own pre-Part-III shape. The single largest identified
// contributor is NOT Kernel::boot() itself (a real one-time cost, and the
// actual reason this call is needed at all -- ImageDerivativeController
// must be container-resolvable to be a routed ControllerInterface): it's
// ConfigService::loadConfFromDb()'s full-config-table Doctrine ORM read,
// unconditionally run by CommonBootstrap::run(), *in addition to* (not
// instead of) this controller's own existing lightweight targeted 2-key
// DBAL read (Config::override() for just 'derivatives'/
// 'disabled_derivatives', see ImageDerivativeController::__invoke()'s own
// comment) -- a real, measurable, not-obviously-worker-amortizable cost
// this phase's own scope didn't extend to removing. Flagged here rather
// than silently accepted or hastily "fixed" under this phase's own time
// budget; a future pass could investigate whether this route genuinely
// needs the full ConfigService::loadConfFromDb() call CommonBootstrap::run()
// always makes, or could skip straight to Kernel::boot() instead.
CommonBootstrap::run($paths);

$response = RequestPipeline::handle(RequestFactory::fromGlobals(), RequestPipeline::WITHOUT_SESSION);
new ResponseEmitter()
    ->emit($response);
