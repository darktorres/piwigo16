<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;

/**
 * Singleton/service-locator elimination campaign, Phase 12 sub-phase 12F-2:
 * replaces the deleted `SessionService::get()` transitional shim for test
 * call sites -- reproduces its exact behavior (the real container-shared
 * instance once Kernel has booted, a fresh, unmemoized instance built
 * directly via `EntityManagerFactory::build()` otherwise). No
 * shared-fallback-instance test coupling to worry about here: the shim's
 * own pre-boot fallback was always a brand-new instance per call, never
 * memoized (see SessionServiceTest.php's own "returns a fresh read on
 * every call when Kernel is not booted" case).
 */
final class SessionServiceTestFactory
{
    public static function get(): SessionService
    {
        if (Kernel::isBooted()) {
            $sessionService = Kernel::container()->get(SessionService::class);
            if (! $sessionService instanceof SessionService) {
                throw new LogicException('Container returned an unexpected type for ' . SessionService::class);
            }

            return $sessionService;
        }

        return new SessionService(EntityManagerFactory::build()->getRepository(SessionEntity::class), new CurrentConfig());
    }
}
