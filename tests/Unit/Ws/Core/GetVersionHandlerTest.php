<?php

declare(strict_types=1);

use Piwigo\Core\AppInfo;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Ws\Core\GetVersionHandler;

/**
 * Piwigo\Ws\Core\GetVersionHandler -- `pwg.getVersion`. Resolved via
 * `Kernel::container()->get()`, same rationale as GetListHandlerTest.php
 * (Comments). Covers the trivial constant return -- its only branch.
 */
function pwgCoreGetVersionHandlerTestSubject(): GetVersionHandler
{
    $handler = Kernel::container()->get(GetVersionHandler::class);
    if (! $handler instanceof GetVersionHandler) {
        throw new LogicException('Container returned an unexpected type for ' . GetVersionHandler::class);
    }

    return $handler;
}

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
});

afterEach(function (): void {
    Kernel::reset();
});

test('getVersion returns the real app version constant', function (): void {
    $handler = pwgCoreGetVersionHandlerTestSubject();

    $result = $handler([]);

    expect($result)
        ->toBe(AppInfo::VERSION);
});
