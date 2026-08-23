<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
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
 * docblock) is the only caller, using entityManager() to build its
 * handler factories' object graphs. `currentLogger()`/
 * `translationsCachePool()`/`storageRegistry()`/`wsContext()`/
 * `dbCredentials()` used to exist alongside it for the same reason --
 * all 5 confirmed genuinely dead (zero real callers anywhere) once
 * `BatchUploadHandler`'s own constructor collapsed to `UploadService` +
 * `urlService()` (Finding 1's container-sharing fix absorbed the
 * `ApiContext`/`StorageRegistry`/`DbCredentials` construction those
 * resolvers used to feed it) and `ReindexImagesJob`'s handler factory
 * switched to `ExtendedDomainAccessor::metadataService()`/
 * `CoreDomainAccessor::permissionService()`, and removed.
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
}
