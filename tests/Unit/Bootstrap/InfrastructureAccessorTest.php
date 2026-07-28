<?php

declare(strict_types=1);

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Bootstrap\InfrastructureAccessor;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\KernelContainerOverride;

/**
 * Piwigo\Bootstrap\InfrastructureAccessor -- had zero dedicated coverage
 * (see /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 * Same "lazy container binding, no real DB connection needed to resolve
 * it" shape as ExtendedDomainAccessorTest's own accessors -- Doctrine's
 * Connection/EntityManager are lazy until a query actually runs.
 */
beforeEach(function (): void {
    Kernel::reset();
    Kernel::boot();
});

afterEach(function (): void {
    Kernel::reset();
});

test('entityManager resolves the container\'s own single EntityManagerInterface instance', function (): void {
    $em = InfrastructureAccessor::entityManager();

    expect($em)->toBeInstanceOf(EntityManagerInterface::class);
    // The whole point (see this class's own docblock): every caller
    // shares ONE instance per request/container, not a fresh one per
    // call -- distinct from EntityManagerFactory::build()'s own
    // always-fresh contract.
    expect(InfrastructureAccessor::entityManager())->toBe($em);
});

test('entityManager throws when the container returns an unexpected type', function (): void {
    KernelContainerOverride::withWrongTypeFor(
        EntityManagerInterface::class,
        static fn () => InfrastructureAccessor::entityManager()
    );
})->throws(LogicException::class, 'Container returned an unexpected type for ' . EntityManagerInterface::class);
