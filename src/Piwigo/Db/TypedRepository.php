<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\ORM\EntityRepository;

/**
 * Narrows an `EntityManagerInterface::getRepository()` call's generic
 * `EntityRepository<T>` return value down to the concrete custom
 * repository class Doctrine actually constructs for it (per the entity's
 * own `#[ORM\Entity(repositoryClass: ...)]`) -- Psalm has no equivalent to
 * phpstan-doctrine's repositoryClass-aware return-type resolution, so it
 * only ever sees the generic type. The `assert()` is a real runtime check
 * (catches an actual entity/repository-class mapping mistake), not just a
 * type-level cast.
 */
final class TypedRepository
{
    /**
     * @template E of object
     * @template R of EntityRepository<E>
     * @param EntityRepository<E> $repository
     * @param class-string<R> $repositoryClass
     * @return R
     */
    public static function narrow(EntityRepository $repository, string $repositoryClass): EntityRepository
    {
        assert($repository instanceof $repositoryClass);

        return $repository;
    }
}
