<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;

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
 * docblock) is the only caller, using both methods to build its handler
 * factories' object graphs. `storageRegistry()`/`wsContext()`/
 * `dbCredentials()` used to exist alongside these for the same reason --
 * all 3 confirmed genuinely dead (zero real callers anywhere) once
 * `BatchUploadHandler`'s own constructor collapsed to `UploadService` +
 * `urlService()` (Finding 1's container-sharing fix absorbed the
 * `WsContext`/`StorageRegistry`/`DbCredentials` construction those 3
 * resolvers used to feed it) and removed.
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
}
