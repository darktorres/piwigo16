<?php

declare(strict_types=1);

use Piwigo\Admin\Maintenance\MaintenanceActionDispatcher;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

/**
 * Piwigo\Admin\Maintenance\MaintenanceActionDispatcher -- handles the
 * maintenance action switch shared by the "actions" and "env" admin
 * tabs (16 real maintenance operations, most of them real DB writes).
 * Resolved via `Kernel::container()->get()` (same rationale as
 * `UpdatesSubControllerTest.php`) -- 19 constructor deps, none touched
 * by the branch under test.
 *
 * Only the `default` branch (an unrecognized `$action`) is covered
 * here: `dispatch()`'s own `switch` falls through every real case
 * straight to `default: $register_activity = false; break;`, so
 * nothing is written, recorded, or read for real -- a genuine
 * zero-side-effect no-op. Every real action (`lock_gallery`/
 * `categories`/`database`/`c13y`/`derivatives`/`check_upgrade`/etc)
 * performs a real write or a real outbound HTTP call
 * (`check_upgrade`'s own `HttpClientService::fetch()`), and `phpinfo`
 * ends in a real `exit()` -- none attempted here.
 */
test('dispatch is a safe no-op for an unrecognized action, recording no activity', function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));

    try {
        $dispatcher = Kernel::container()->get(MaintenanceActionDispatcher::class);
        if (! $dispatcher instanceof MaintenanceActionDispatcher) {
            throw new LogicException('Container returned an unexpected type for ' . MaintenanceActionDispatcher::class);
        }

        $dispatcher->dispatch('not-a-real-maintenance-action');
    } finally {
        Kernel::reset();
    }
})->throwsNoExceptions();
