<?php

declare(strict_types=1);

use Piwigo\Bootstrap\SessionBootstrap;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Piwigo\Bootstrap\SessionBootstrap -- installs Piwigo's DB-backed
 * session save handler before session_start(). No dedicated
 * Integration/Browser spec of its own.
 *
 * Only the no-op guard is covered here: `register()`'s real body calls
 * `session_set_save_handler()`/`session_name()`/
 * `session_set_cookie_params()`/`register_shutdown_function()` --
 * genuine global PHP session state changes that would bleed into every
 * other test in the same process once made, not something a Unit test
 * should ever trigger for real. `InstallationFlag::isActive()` starts
 * `false` on a freshly booted container (its own `$marked` property
 * defaults `false`) -- `register()`'s own `and`-guard short-circuits on
 * that alone,
 * regardless of `sessionSaveHandler()` (whose own default is actually
 * `'db'`, the value that would otherwise satisfy the other half).
 */
test('register is a safe no-op when the installation flag is not active', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    try {
        SessionBootstrap::register();
    } finally {
        Kernel::reset();
    }
})->throwsNoExceptions();
