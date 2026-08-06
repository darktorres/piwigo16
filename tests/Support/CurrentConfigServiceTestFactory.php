<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfigService;
use Piwigo\Core\Kernel;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-5:
 * replaces the deleted `CurrentConfigService::current()` transitional shim
 * for test call sites -- reproduces its exact behavior (the real
 * container-shared instance once Kernel has booted, a MEMOIZED, not
 * fresh-per-call, instance otherwise). Several real tests (e.g.
 * CurrentConfigServiceTest's own "falls back to a memoized instance" case)
 * call `->set(...)` on one call's return value and expect a later call to
 * see it -- a fresh instance per call would silently lose that write.
 */
final class CurrentConfigServiceTestFactory
{
    private static ?CurrentConfigService $fallback = null;

    public static function get(): CurrentConfigService
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(CurrentConfigService::class);
            if (! $instance instanceof CurrentConfigService) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfigService::class);
            }

            return $instance;
        }

        return self::$fallback ??= new CurrentConfigService();
    }
}
