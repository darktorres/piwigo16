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
 * PresentationAccessor's own docblocks, but for L1Infrastructure rather
 * than a domain layer.
 *
 * Distinct from `Piwigo\Db\EntityManagerFactory::build()`: that factory
 * always constructs a brand-new EntityManager (correct for its own
 * callers -- static methods, self-managed singletons -- which must not
 * accidentally depend on container/request state). This accessor instead
 * returns the container's own single, per-request EntityManagerInterface
 * instance -- the one every container-resolved repository shares -- so a
 * raw DBAL/BatchWriter write elsewhere in the same request can clear()
 * the *same* identity map those repositories read through, not a
 * throwaway one of its own.
 *
 * `config/messenger.php` (outside `src/Piwigo`, and deliberately outside
 * the `Kernel::container()` arch-test boundary too, per its own
 * docblock) is the only caller, using all 4 methods to build its handler
 * factories' object graphs.
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
     * its own get() unwraps to.
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
     * WsContext instance.
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
     * DbCredentials instance. No Kernel::isBooted()-false fallback needed
     * here: this file's own handler factories only ever run after the bus
     * (and therefore the container) is already built.
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
