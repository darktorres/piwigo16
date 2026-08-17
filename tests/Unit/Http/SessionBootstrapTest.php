<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Http\SessionBootstrap;
use Piwigo\Session\SessionService;

/**
 * Piwigo\Http\SessionBootstrap -- installs Piwigo's DB-backed session save
 * handler before session_start(). No dedicated Integration/Browser spec of
 * its own. Lives in Piwigo\Http\ since Http\Middleware\
 * ConfigBootstrapMiddleware (L3) needs to call it -- Piwigo\Bootstrap\*
 * is L4Integration, which Http\Middleware\* (L3Presentation) may not
 * depend upward on.
 *
 * Only the no-op guard is covered here: `register()`'s real body calls
 * `session_set_save_handler()`/`session_name()`/
 * `session_set_cookie_params()`/`register_shutdown_function()` --
 * genuine global PHP session state changes that would bleed into every
 * other test in the same process once made, not something a Unit test
 * should ever trigger for real. `InstallationFlag::isActive()` starts
 * `false` on a freshly constructed instance (its own `$marked` property
 * defaults `false`) -- `register()`'s own `and`-guard short-circuits on
 * that alone, regardless of `sessionSaveHandler()` (whose own default is
 * actually `'db'`, the value that would otherwise satisfy the other half).
 */
function sessionBootstrapTestSubject(): SessionBootstrap
{
    $currentConfig = Kernel::container()->get(CurrentConfig::class);
    $sessionService = Kernel::container()->get(SessionService::class);
    $currentLogger = Kernel::container()->get(CurrentLogger::class);
    $installationFlag = Kernel::container()->get(InstallationFlag::class);
    if (! $currentConfig instanceof CurrentConfig
        || ! $sessionService instanceof SessionService
        || ! $currentLogger instanceof CurrentLogger
        || ! $installationFlag instanceof InstallationFlag) {
        throw new LogicException('Container returned an unexpected type for one of SessionBootstrap\'s dependencies.');
    }

    return new SessionBootstrap($currentConfig, $sessionService, $currentLogger, $installationFlag);
}

test('register is a safe no-op when the installation flag is not active', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    try {
        sessionBootstrapTestSubject()->register();
    } finally {
        Kernel::reset();
    }
})->throwsNoExceptions();
