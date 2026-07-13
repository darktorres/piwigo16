<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ZipExtractor;

// Only containerVersionCompare() is covered here -- checkPiwigoUpgrade()/
// getPiwigoNewVersions()/notifyPiwigoNewVersions()/upgradeTo() all talk to
// the real PHPWG_URL over the network via the legacy fetchRemote() free
// function (no injectable HTTP client seam), matching the project's own
// documented "piwigo.org outbound-call" flakiness class -- not exercised
// here.
function core_update_service(): CoreUpdateService
{
    return new CoreUpdateService(new ZipExtractor());
}

test('containerVersionCompare orders by semantic version first', function (): void {
    expect(core_update_service()->containerVersionCompare('16.1.0a', '16.2.0a'))->toBeLessThan(0)
        ->and(core_update_service()->containerVersionCompare('16.2.0a', '16.1.0a'))->toBeGreaterThan(0);
});

test('containerVersionCompare falls back to the container letter suffix on a semantic tie', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0b'))->toBeTrue()
        ->and(core_update_service()->containerVersionCompare('16.2.0b', '16.2.0a'))->toBeFalse();
});

test('containerVersionCompare treats an identical version as no earlier suffix', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0a'))->toBeFalse();
});
