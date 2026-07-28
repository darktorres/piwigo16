<?php

declare(strict_types=1);

use Piwigo\Job\MessengerFactory;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * transport()/sendingBus()/receivingBus()'s own real wiring is already
 * proven end-to-end (real DB round trip) by
 * tests/Integration/MessengerRoundTripTest.php. This file closes the one
 * remaining reachable gap: containerOf()'s "service not found" branch,
 * called through Reflection since containerOf() is private -- its one
 * real call site (sendingBus()) can never actually trigger that branch in
 * practice, confirmed by reading Symfony's own
 * SendersLocator::getSenderFromAlias() source: it always calls the
 * container's has() *before* get(), and sendingBus() builds both the
 * $senders map and the container's $factories from the exact same
 * $config['routing'] keys, so has() is always true for every alias
 * SendersLocator could ever ask for.
 *
 * transport()'s own anonymous ConnectionRegistry class (getDefaultConnectionName()/
 * getConnections()/getConnectionNames(), the other 3 uncovered lines in
 * this class) is deliberately NOT chased: reading Symfony's real
 * DoctrineTransportFactory::createTransport() source confirms it only
 * ever calls $registry->getConnection($name) -- never any of those other
 * 3 ConnectionRegistry methods. The anonymous instance itself is a local
 * variable inside transport() that's discarded the moment createTransport()
 * returns (DoctrineTransport only stores the already-resolved driver
 * Connection, not the registry), so there is no seam anywhere to reach
 * those 3 method bodies without a source change purely for interface-
 * completeness boilerplate that no real caller ever exercises.
 */
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
