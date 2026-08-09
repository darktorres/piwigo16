<?php

declare(strict_types=1);

use DI\Definition\Exception\InvalidDefinition;
use Piwigo\Core\Container;
use Piwigo\Core\Paths;

use function DI\factory;

test('build returns a ContainerInterface', function (): void {
    // Container::build()'s own return type already guarantees the
    // instance's class -- what's actually meaningful to verify is that
    // building with no overrides (config/container.php's real
    // definitions, PHP-DI's own autowiring) completes without throwing.
    expect(static fn () => Container::build())->not->toThrow(Throwable::class);
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

test('build without a $paths argument does not register a Paths definition at all', function (): void {
    // Kills the InstanceOfToTrue mutation on the `$paths instanceof
    // Paths` guard: without it, real code leaves Paths::class
    // undefined and PHP-DI's own autowiring correctly fails (Paths'
    // constructor needs concrete string arguments it can't guess). The
    // mutant instead unconditionally registers `Paths::class => null`,
    // so get() would return null instead of throwing -- confirmed live
    // via a sed-applied mutation rerun.
    $container = Container::build();

    expect(fn () => $container->get(Paths::class))->toThrow(InvalidDefinition::class);
});

test('build with a $paths argument registers it so Paths::class resolves to that same instance', function (): void {
    $paths = Paths::fromRoot('/home/torres/piwigo17-rewrite');

    $container = Container::build(paths: $paths);

    expect($container->get(Paths::class))->toBe($paths);
});
