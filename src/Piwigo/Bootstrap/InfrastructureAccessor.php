<?php

declare(strict_types=1);

namespace Piwigo\Bootstrap;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Kernel;

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
 */
final class InfrastructureAccessor
{
    public static function entityManager(): EntityManagerInterface
    {
        $em = Kernel::container()->get(EntityManagerInterface::class);
        if (! $em instanceof EntityManagerInterface) {
            throw new \LogicException('Container returned an unexpected type for ' . EntityManagerInterface::class);
        }
        return $em;
    }

    /**
     * Same rationale as entityManager() above -- gives still-static callers
     * (e.g. Ws\PwgExtensions's PemCatalog construction) the real
     * container-shared CurrentLogger instance, not just the Logger value
     * CurrentLogger::getStatic() itself unwraps to (singleton/
     * service-locator elimination campaign, Phase 2).
     */
    public static function currentLogger(): CurrentLogger
    {
        $currentLogger = Kernel::container()->get(CurrentLogger::class);
        if (! $currentLogger instanceof CurrentLogger) {
            throw new \LogicException('Container returned an unexpected type for ' . CurrentLogger::class);
        }
        return $currentLogger;
    }
}
