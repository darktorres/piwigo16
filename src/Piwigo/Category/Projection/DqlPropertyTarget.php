<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * An entity class paired with one of its DQL property paths --
 * {@see \Piwigo\Category\CategoryAccessTarget::entityClassAndFieldProperty()}
 * and {@see \Piwigo\Category\CategoryOrphanTarget::entityClassAndProperty()}'s
 * own fixed 2-value result, previously a bare `[class-string, string]`
 * tuple.
 */
final readonly class DqlPropertyTarget
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        public string $entityClass,
        public string $property,
    ) {}
}
