<?php

declare(strict_types=1);

namespace Piwigo\Job;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\Persistence\ConnectionRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Builds real Symfony Messenger infrastructure from config/messenger.php --
 * no DI container involvement (matches Piwigo\Storage\StorageRegistry's
 * own "load from a plain config array" shape). Not wired into any
 * existing page this phase (P21+ concern once a real console worker
 * exists) -- verified via a direct dispatch -> transport -> handler
 * round-trip integration test instead.
 */
final class MessengerFactory
{
    /**
     * @return array{
     *     transport_table: string,
     *     transport_queue: string,
     *     routing: array<class-string, string>,
     *     handlers: array<class-string, \Closure(): callable>,
     * }
     */
    public static function config(): array
    {
        /**
         * @var array{
         *     transport_table: string,
         *     transport_queue: string,
         *     routing: array<class-string, string>,
         *     handlers: array<class-string, \Closure(): callable>,
         * }
         */
        return require \Piwigo\Core\CurrentPaths::get()->root . 'config/messenger.php';
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
    public static function transport(DbalConnection $connection): TransportInterface&MessageCountAwareInterface
    {
        $config = self::config();
        $registry = new readonly class($connection) implements ConnectionRegistry {
            public function __construct(
                private DbalConnection $connection,
            ) {}

            #[\Override]
            public function getDefaultConnectionName(): string
            {
                return 'default';
            }

            #[\Override]
            public function getConnection(?string $name = null): \Doctrine\DBAL\Connection
            {
                return $this->connection;
            }

            /**
             * @return array<string, object>
             */
            #[\Override]
            public function getConnections(): array
            {
                return [
                    'default' => $this->connection,
                ];
            }

            /**
             * @return array<string, string>
             */
            #[\Override]
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
    public static function sendingBus(TransportInterface $transport): MessageBusInterface
    {
        $config = self::config();
        $senders = array_fill_keys(array_keys($config['routing']), ['async']);
        $container = self::containerOf([
            'async' => static fn (): TransportInterface => $transport,
        ]);

        return new MessageBus([new SendMessageMiddleware(new SendersLocator($senders, $container))]);
    }

    /**
     * Real consumption path: a worker (P21+, `messenger:consume`) pulls
     * Envelopes off the transport and dispatches them through this bus to
     * actually invoke handlers.
     */
    public static function receivingBus(): MessageBusInterface
    {
        $config = self::config();
        $handlersMap = [];
        foreach ($config['handlers'] as $messageClass => $factory) {
            $handlersMap[$messageClass] = [$factory()];
        }

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator($handlersMap))]);
    }

    /**
     * @param array<string, \Closure(): mixed> $factories
     */
    private static function containerOf(array $factories): ContainerInterface
    {
        return new readonly class($factories) implements ContainerInterface {
            /**
             * @param array<string, \Closure(): mixed> $factories
             */
            public function __construct(
                private array $factories,
            ) {}

            public function get(string $id): mixed
            {
                if (! isset($this->factories[$id])) {
                    throw new class('Service "' . $id . '" not found.') extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {};
                }

                return ($this->factories[$id])();
            }

            public function has(string $id): bool
            {
                return isset($this->factories[$id]);
            }
        };
    }
}
