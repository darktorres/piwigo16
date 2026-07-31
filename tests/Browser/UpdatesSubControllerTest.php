<?php

declare(strict_types=1);

use Piwigo\Tests\Browser\Helpers\BrowserTestHelpers as H;

/**
 * Piwigo\Controller\Admin\UpdatesSubController (admin.php?page=updates) --
 * already smoke-tested for both real tabs (pwg/ext) by
 * AdminExtendedSmokeTest.php. This file closes its one remaining branch:
 * the "update system is disabled" fatalError, reached only when BOTH
 * enable_extensions_install and enable_core_update are false.
 */
it('fatal-errors when both the core-update and extensions-install systems are disabled', function (): void {
    $snapshot = H::snapshotConfig(['enable_extensions_install', 'enable_core_update']);
    H::setConfigValue('enable_extensions_install', 'false');
    H::setConfigValue('enable_core_update', 'false');

    try {
        $page = H::loginAsAdmin($this);
        $page = H::navigateOk($page, '/admin.php?page=updates');

        expect($page->content())->toContain('update system is disabled');
    } finally {
        H::restoreConfig($snapshot);
    }
});
