<?php

declare(strict_types=1);

namespace Piwigo\Job;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Job\Handler\BatchUploadHandler;
use Piwigo\Job\Handler\GenerateDerivativeHandler;
use Piwigo\Job\Handler\RegenerateAllDerivativesHandler;
use Piwigo\Job\Handler\ReindexImagesHandler;
use Piwigo\Job\Handler\SendNotificationEmailHandler;
use Psr\Container\ContainerInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class MessengerFactory
{
    public static function build(Connection $dbalConn): MessageBus
    {
        $serializer = new PhpSerializer();
        $tableName  = Config::dbPrefix() . 'messenger_messages';

        $asyncTransport  = self::makeTransport($tableName, 'piwigo_async', $dbalConn, $serializer);
        $failedTransport = self::makeTransport($tableName, 'piwigo_failed', $dbalConn, $serializer);

        $asyncTransport->setup();
        $failedTransport->setup();

        $handlersLocator = new HandlersLocator([
            GenerateDerivativeJob::class          => [new GenerateDerivativeHandler()],
            RegenerateAllDerivativesJob::class     => [new RegenerateAllDerivativesHandler()],
            SendNotificationEmailJob::class        => [new SendNotificationEmailHandler()],
            BatchUploadJob::class                  => [new BatchUploadHandler()],
            ReindexImagesJob::class                => [new ReindexImagesHandler()],
        ]);

        /** @var array{transports: array<string, array<string,mixed>>, routing: array<string, string>} $messengerConfig */
        $messengerConfig = require PHPWG_ROOT_PATH . 'config/messenger.php';

        /** @var array<string, list<string>> $sendersMap */
        $sendersMap = array_map(
            static fn (string $alias): array => [$alias],
            $messengerConfig['routing']
        );

        $transportMap = new readonly class (['async' => $asyncTransport, 'failed' => $failedTransport]) implements ContainerInterface {
            /** @param array<string, DoctrineTransport> $transports */
            public function __construct(private array $transports)
            {
            }

            public function get(string $id): mixed
            {
                return $this->transports[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->transports[$id]);
            }
        };

        $sendersLocator = new SendersLocator($sendersMap, $transportMap);

        return new MessageBus([
            new SendMessageMiddleware($sendersLocator),
            new HandleMessageMiddleware($handlersLocator),
        ]);
    }

    public static function buildTransport(string $queueName, Connection $conn): DoctrineTransport
    {
        $tableName = Config::dbPrefix() . 'messenger_messages';
        return self::makeTransport($tableName, $queueName, $conn, new PhpSerializer());
    }

    private static function makeTransport(
        string $tableName,
        string $queueName,
        Connection $conn,
        PhpSerializer $serializer,
    ): DoctrineTransport {
        $messengerConn = new DoctrineMessengerConnection(
            ['table_name' => $tableName, 'queue_name' => $queueName, 'auto_setup' => true],
            $conn
        );
        return new DoctrineTransport($messengerConn, $serializer);
    }
}
