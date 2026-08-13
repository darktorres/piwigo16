<?php

declare(strict_types=1);

namespace Piwigo\Job;

use Closure;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\Persistence\ConnectionRegistry;
use Override;
use Piwigo\Core\Paths;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Builds real Symfony Messenger infrastructure from config/messenger.php --
 * no DI container involvement (matches Piwigo\Storage\StorageRegistry's
 * own "load from a plain config array" shape). Not wired into any
 * existing page yet (a concern once a real console worker
 * exists); a direct dispatch -> transport -> handler round-trip
 * integration test covers this instead.
 */
final class MessengerFactory
{
    /**
     * @return array{transport_table: string, transport_queue: string, routing: array<class-string, string>, handlers: array<class-string, Closure():callable>}
     */
    public static function config(Paths $paths): array
    {
        /**
         * @var array{transport_table: string, transport_queue: string, routing: array<class-string, string>, handlers: array<class-string, Closure():callable>}
         */
        return require $paths->root . 'config/messenger.php';
    }

    /**
     * The 'async' Doctrine transport (DB-polling, FOR UPDATE SKIP LOCKED).
     * Built via the real DoctrineTransportFactory (not `new
     * .../Transport/Connection(...)` directly, which is an @internal
     * class) + a minimal single-connection ConnectionRegistry -- this
     * project has no Doctrine\Persistence\ManagerRegistry of its own
     * (DbConnection::build() is the one real connection).
     *
     * Return type is the honest intersection (not the wider
     * TransportInterface) -- DoctrineTransportFactory's own
     * `@implements TransportFactoryInterface<DoctrineTransport>` means
     * this always really is a DoctrineTransport, which also implements
     * MessageCountAwareInterface (used by the round-trip integration
     * test to assert the transport's queue depth directly).
     */
    public static function transport(DbalConnection $connection, Paths $paths): TransportInterface&MessageCountAwareInterface
    {
        $config = self::config($paths);
        $registry = new readonly class($connection) implements ConnectionRegistry {
            public function __construct(
                private DbalConnection $connection,
            ) {}

            #[Override]
            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            #[Override]
            public function getConnection(?string $name = null): DbalConnection
            {
                return $this->connection;
            }

            /**
             * @return array<string, object>
             */
            #[Override]
            public function getConnections(): array
            {
                return [
                    'default' => $this->connection,
                ];
            }

            /**
             * @return array<string, string>
             */
            #[Override]
            public function getConnectionNames(): array
            {
                return [
                    'default' => 'default',
                ];
            }
        };

        return new DoctrineTransportFactory($registry)
            ->createTransport(
                'doctrine://default',
                [
                    'table_name' => $config['transport_table'],
                    'queue_name' => $config['transport_queue'],
                ],
                new PhpSerializer(),
            );
    }

    /**
     * Real production dispatch path: sends the message onto the async
     * transport instead of handling it immediately.
     */
    public static function sendingBus(TransportInterface $transport, Paths $paths): MessageBusInterface
    {
        $config = self::config($paths);
        $senders = array_fill_keys(array_keys($config['routing']), ['async']);
        $container = self::containerOf([
            'async' => static fn (): TransportInterface => $transport,
        ]);

        return new MessageBus([new SendMessageMiddleware(new SendersLocator($senders, $container))]);
    }

    /**
     * Real consumption path: a worker (`messenger:consume`) pulls
     * Envelopes off the transport and dispatches them through this bus to
     * actually invoke handlers.
     */
    public static function receivingBus(Paths $paths): MessageBusInterface
    {
        $config = self::config($paths);
        $handlersMap = [];
        foreach ($config['handlers'] as $messageClass => $factory) {
            $handlersMap[$messageClass] = [$factory()];
        }

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator($handlersMap))]);
    }

    /**
     * `Symfony\Component\Messenger\Command\ConsumeMessagesCommand`'s own
     * $routableBus param -- this app has a single message bus with no
     * multi-bus routing (see sendingBus()/receivingBus()'s own single-bus
     * shape), so every consumed Envelope always falls back to
     * receivingBus() rather than being routed by a real BusNameStamp
     * lookup. The empty locator makes RoutableMessageBus::getMessageBus()
     * always miss, guaranteeing the fallback path.
     */
    public static function routableBus(Paths $paths): RoutableMessageBus
    {
        return new RoutableMessageBus(self::containerOf([]), self::receivingBus($paths));
    }

    /**
     * ConsumeMessagesCommand's own $receiverLocator param -- maps this
     * app's one real receiver name ('async', matching sendingBus()'s own
     * sender-locator key) to the same transport instance a real worker
     * consumes from.
     */
    public static function receiverLocator(TransportInterface $transport): ContainerInterface
    {
        return self::containerOf([
            'async' => static fn (): TransportInterface => $transport,
        ]);
    }

    /**
     * $factories' return values are genuinely arbitrary by design -- any
     * PHP-DI-bound service, matching PSR-11 ContainerInterface::get()'s own
     * `mixed` return below (a real interface override, not a design choice).
     *
     * @param array<string, Closure> $factories
     */
    private static function containerOf(array $factories): ContainerInterface
    {
        return new readonly class($factories) implements ContainerInterface {
            /**
             * @param array<string, Closure> $factories
             */
            public function __construct(
                private array $factories,
            ) {}

            #[Override]
            public function get(string $id): mixed
            {
                if (! isset($this->factories[$id])) {
                    throw new class('Service "' . $id . '" not found.') extends RuntimeException implements NotFoundExceptionInterface {};
                }

                return ($this->factories[$id])();
            }

            #[Override]
            public function has(string $id): bool
            {
                return isset($this->factories[$id]);
            }
        };
    }
}
