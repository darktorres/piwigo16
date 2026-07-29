<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ConnectionRegistry;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;
use Piwigo\Job\MessengerFactory;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * transport()/sendingBus()/receivingBus()'s own real wiring is already
 * proven end-to-end (real DB round trip) by
 * tests/Integration/MessengerRoundTripTest.php. This file closes two more
 * reachable gaps: containerOf()'s "service not found" branch, called
 * through Reflection since containerOf() is private -- its one real call
 * site (sendingBus()) can never actually trigger that branch in practice,
 * confirmed by reading Symfony's own SendersLocator::getSenderFromAlias()
 * source: it always calls the container's has() *before* get(), and
 * sendingBus() builds both the $senders map and the container's
 * $factories from the exact same $config['routing'] keys, so has() is
 * always true for every alias SendersLocator could ever ask for -- and
 * transport()'s own anonymous ConnectionRegistry class's
 * getDefaultConnectionName()/getConnections() bodies.
 *
 * That registry is a local variable inside transport(), discarded the
 * moment createTransport() returns, and reading Symfony's real
 * DoctrineTransportFactory::createTransport() source confirms it only
 * ever calls $registry->getConnection($name) on it -- never
 * getDefaultConnectionName()/getConnections()/getConnectionNames(), so
 * none of those 3 bodies are reachable through transport()'s own return
 * value. There IS still a real seam though, with no source change: PHP
 * compiles exactly one class definition per anonymous-class call site
 * (keyed by file+line), so simply calling transport() once is enough to
 * get that class declared and then locatable via get_declared_classes()
 * -- from there a fresh instance (built from the very same $connection
 * that was passed in) exercises the real method bodies directly.
 * getConnectionNames() (`['default' => 'default']`) is deliberately left
 * alone even though the same seam reaches it too -- it's a pure
 * scalar-literal array, the OPcache-constant-folding pattern this whole
 * coverage pass's scoping already treats as a non-gap (see this batch's
 * own artifact caveat), unlike getConnections()'s `['default' =>
 * $this->connection]`, which embeds a variable and is never folded.
 */
beforeEach(function (): void {
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    CurrentPaths::reset();
});

test('transport()\'s anonymous ConnectionRegistry answers "default" for getDefaultConnectionName and keys getConnections off the passed-in connection', function (): void {
    $connection = DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true,
    ]);

    // transport() itself is enough to get the anonymous class declared --
    // its own genuine wiring (registry -> DoctrineTransportFactory ->
    // DoctrineTransport) is already covered by
    // tests/Integration/MessengerRoundTripTest.php, so the return value
    // isn't needed here.
    MessengerFactory::transport($connection);

    $registryClass = null;
    foreach (get_declared_classes() as $declared) {
        if (str_contains($declared, '@anonymous') && is_a($declared, ConnectionRegistry::class, true)) {
            $registryClass = $declared;
            break;
        }
    }
    if ($registryClass === null) {
        throw new RuntimeException('Could not locate transport()\'s anonymous ConnectionRegistry class.');
    }

    // $registryClass was filtered above via is_a($declared, ConnectionRegistry::class, true),
    // which already guarantees any instance built from it implements
    // ConnectionRegistry -- no further runtime check needed.
    $registry = new $registryClass($connection);

    expect($registry->getDefaultConnectionName())->toBe('default')
        ->and($registry->getConnections())->toBe(['default' => $connection]);
});

test('containerOf-built container throws Psr NotFoundExceptionInterface for a service id it was not given', function (): void {
    $method = new ReflectionMethod(MessengerFactory::class, 'containerOf');

    $container = $method->invoke(null, [
        'async' => static fn (): string => 'the-async-sender',
    ]);
    if (! $container instanceof ContainerInterface) {
        throw new RuntimeException('containerOf() did not return a ContainerInterface');
    }

    expect($container->has('async'))->toBeTrue()
        ->and($container->get('async'))->toBe('the-async-sender')
        ->and($container->has('missing'))->toBeFalse();

    // Pest's toThrow() only special-cases its first argument via
    // class_exists(), which is false for an interface -- passing
    // NotFoundExceptionInterface::class silently falls through to a
    // substring-match-against-the-message branch instead of an instanceof
    // check, confirmed live. A manual try/catch is the real way to assert
    // both the interface and the exact message.
    $threw = false;
    try {
        $container->get('missing');
    } catch (NotFoundExceptionInterface $e) {
        $threw = true;
        expect($e->getMessage())->toBe('Service "missing" not found.');
    }

    expect($threw)->toBeTrue();
});
