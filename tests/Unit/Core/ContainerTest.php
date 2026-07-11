<?php

declare(strict_types=1);

use Piwigo\Core\Container;
use Psr\Container\ContainerInterface;

use function DI\factory;

test('build returns a ContainerInterface', function (): void {
    expect(Container::build())->toBeInstanceOf(ContainerInterface::class);
});

test('build with no overrides leaves an unbound interface unresolved', function (): void {
    // config/container.php is empty and PHP-DI cannot autowire an interface
    // without an explicit binding.
    $container = Container::build();
    expect($container->has(Countable::class))->toBeFalse();
});

test('build honors extraDefinitions overrides', function (): void {
    // Reuses a built-in interface (Countable) rather than a named fixture
    // class, matching tests/Arch/StructuralTest.php's own convention.
    $container = Container::build([
        Countable::class => factory(static fn (): Countable => new class () implements Countable {
            public function count(): int
            {
                return 42;
            }
        }),
    ]);

    expect($container->has(Countable::class))->toBeTrue();

    $countable = $container->get(Countable::class);
    expect($countable instanceof Countable && $countable->count() === 42)->toBeTrue();
});
