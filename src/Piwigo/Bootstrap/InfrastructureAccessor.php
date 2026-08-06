<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;
use Piwigo\Core\WsContext;
use Piwigo\Db\DbCredentials;
use Piwigo\Storage\StorageRegistry;

/**
 * Typed accessor to the container-resolved `EntityManagerInterface` --
 * same "Kernel::container() is restricted to Bootstrap/ + index.php by an
 * arch test" rationale as CoreDomainAccessor/ExtendedDomainAccessor/
 * PresentationAccessor/AdminAccessor's own docblocks, but for
 * L1Infrastructure rather than a domain layer (there is no
 * "InfrastructureAccessor" sibling among those 4 because nothing needed
 * one until now).
 *
 * Distinct from `Piwigo\Db\EntityManagerFactory::build()`: that factory
 * always constructs a brand-new EntityManager (correct for its own
 * stated callers -- static methods, self-managed singletons -- which
 * must not accidentally depend on container/request state). This
 * accessor instead returns the container's own single, per-request
 * EntityManagerInterface instance -- the one every container-resolved
 * repository shares -- so a raw DBAL/BatchWriter write elsewhere in the
 * same request can clear() the *same* identity map those repositories
 * read through, not a throwaway one of its own.
 *
 * Singleton/service-locator elimination campaign, Phase 10: PwgImages's
 * own conversion (the last Ws/Pwg* class using this accessor) emptied
 * every real `src/Piwigo` caller of the first 3 methods -- but `config/
 * messenger.php` (outside `src/Piwigo`, and deliberately outside the
 * `Kernel::container()` arch-test boundary too, per its own docblock)
 * calls all 4 to build its handler factories' object graphs (Phase 12
 * sub-phase 12B added dbCredentials(), for the same file's own
 * DbCredentials::current() closure), so this class stays permanently, not
 * just until Phase 10's own close-out.
 */
final class InfrastructureAccessor
{
    public static function entityManager(): EntityManagerInterface
    {
        $em = Kernel::container()->get(EntityManagerInterface::class);
        if (! $em instanceof EntityManagerInterface) {
            throw new LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }
        return $em;
    }

    /**
     * Same rationale as entityManager() above -- gives still-static callers
     * (e.g. config/messenger.php's handler factories) the real
     * container-shared CurrentLogger instance, not just the Logger value
     * its own get() unwraps to (singleton/service-locator elimination
     * campaign, Phase 2).
     */
    public static function currentLogger(): CurrentLogger
    {
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        return $currentLogger;
    }

    /**
     * Same rationale as currentLogger() above -- StorageRegistry's own
     * `get()` method needs a real, container-shared instance to call it
     * on, which a still-static caller (config/messenger.php) can't obtain
     * via constructor injection.
     */
    public static function storageRegistry(): StorageRegistry
    {
        $storageRegistry = Kernel::container()->get(StorageRegistry::class);
        if (! $storageRegistry instanceof StorageRegistry) {
            throw new LogicException('Container returned an unexpected type for ' . StorageRegistry::class);
        }
        return $storageRegistry;
    }

    /**
     * Same rationale as currentLogger()/storageRegistry() above -- gives a
     * still-static caller (e.g. Admin\Install\InstallService's own
     * genuinely-static-context install flow) the real, container-shared
     * WsContext instance, singleton/service-locator elimination campaign,
     * Phase 11 sub-phase 11D.
     */
    public static function wsContext(): WsContext
    {
        $wsContext = Kernel::container()->get(WsContext::class);
        if (! $wsContext instanceof WsContext) {
            throw new LogicException('Container returned an unexpected type for ' . WsContext::class);
        }
        return $wsContext;
    }

    /**
     * Same rationale as wsContext() above -- gives config/messenger.php's
     * still-static handler factories the real, container-shared
     * DbCredentials instance instead of the DbCredentials::current() shim
     * (singleton/service-locator elimination campaign, Phase 12 sub-phase
     * 12B). No Kernel::isBooted()-false fallback needed here, unlike the
     * shim itself: this file's own handler factories only ever run after
     * the bus (and therefore the container) is already built.
     */
    public static function dbCredentials(): DbCredentials
    {
        $dbCredentials = Kernel::container()->get(DbCredentials::class);
        if (! $dbCredentials instanceof DbCredentials) {
            throw new LogicException('Container returned an unexpected type for ' . DbCredentials::class);
        }
        return $dbCredentials;
    }
}
