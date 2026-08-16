<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * An entity class paired with one of its DQL property paths --
 * {@see \Piwigo\Category\CategoryAccessTarget::entityClassAndFieldProperty()}
 * and {@see \Piwigo\Category\CategoryOrphanTarget::entityClassAndProperty()}'s
 * own fixed 2-value result.
 *
 * $isAssociation marks $property as an owning-side association path
 * (rather than a plain scalar column) -- a bare association path in a
 * `SELECT` clause changes the generated SQL itself (it would hydrate the
 * associated entity, not just extract the FK id), so a `SELECT`-context
 * consumer needs to wrap it in `IDENTITY()`; `WHERE`/join-condition
 * consumers use $property bare either way.
 */
final readonly class DqlPropertyTarget
{
    /**
     * @param class-string $entityClass
     */
    public function __construct(
        public string $entityClass,
        public string $property,
        public bool $isAssociation = false,
    ) {}
}
