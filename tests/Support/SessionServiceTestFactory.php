<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\TypedRepository;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;

/**
 * Returns the container-shared instance once Kernel has booted, or a
 * fresh, unmemoized instance built directly via
 * `EntityManagerFactory::build()` otherwise. Each pre-boot call returns
 * a brand-new instance -- never memoized.
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

        return new SessionService(TypedRepository::narrow(EntityManagerFactory::build()->getRepository(SessionEntity::class), SessionRepository::class), new CurrentConfig());
    }
}
