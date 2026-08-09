<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ConnectionRegistry;
use Piwigo\Core\Kernel;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\Paths;
use Piwigo\Job\MessengerFactory;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Messenger\Envelope;

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
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 3)));
});

afterEach(function (): void {
    Kernel::reset();
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
    MessengerFactory::transport($connection, CurrentPathsTestFactory::get());

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

test('config() reads config/messenger.php relative to CurrentPathsTestFactory::get()->root, not the working directory', function (): void {
    // Kills line 49's ConcatRemoveLeft (`require 'config/messenger.php'`
    // instead of `require CurrentPathsTestFactory::get()->root . 'config/...'`) --
    // a bare relative require would instead resolve against the process's
    // own working directory (the real project root when tests run via
    // `vendor/bin/pest` from there), silently loading the REAL
    // config/messenger.php instead of this test's own throwaway one.
    // Also closes lines 114/115's RemoveArrayItem together: this fake
    // config's table_name/queue_name only end up in the real, persisted
    // row if BOTH survive transport()'s own createTransport() options
    // array, since the query below targets the fake table name directly
    // and asserts the exact queue_name column value.
    $root = sys_get_temp_dir() . '/piwigo-messenger-factory-test-' . bin2hex(random_bytes(8));
    mkdir($root . '/config', 0o777, true);
    file_put_contents(
        $root . '/config/messenger.php',
        '<?php return ["transport_table" => "mutation_sweep_messages", "transport_queue" => "mutation_sweep_queue", "routing" => [], "handlers" => []];'
    );
    // Kernel is already booted (beforeEach()'s own default real-repo-root
    // boot) -- reset first, or this bare boot() would silently no-op
    // against its own idempotency guard and Paths::class would keep
    // pointing at the wrong (real repo) root.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));

    try {
        expect(MessengerFactory::config(Paths::fromRoot($root)))->toBe([
            'transport_table' => 'mutation_sweep_messages',
            'transport_queue' => 'mutation_sweep_queue',
            'routing' => [],
            'handlers' => [],
        ]);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $transport = MessengerFactory::transport($connection, Paths::fromRoot($root));

        $transport->send(new Envelope(new stdClass()));

        // $connection is a fresh in-memory sqlite connection created a few
        // lines above (not the live DB staabm/phpstan-dba's bootstrap
        // connects to), and mutation_sweep_messages is auto-created on it
        // by transport()'s own send() call just above -- neither the
        // engine nor the table exists in the persistent schema the live
        // reflector inspects.
        // @phpstan-ignore dba.syntaxError
        $rows = $connection->fetchAllAssociative('SELECT queue_name FROM mutation_sweep_messages');
        expect($rows)->toBe([['queue_name' => 'mutation_sweep_queue']]);
    } finally {
        unlink($root . '/config/messenger.php');
        rmdir($root . '/config');
        rmdir($root);
    }
});
