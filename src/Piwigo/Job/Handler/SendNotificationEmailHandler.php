<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Doctrine\DBAL\Connection;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\ServiceLocator;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Mail\MailService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendNotificationEmailHandler
{
    public function __invoke(SendNotificationEmailJob $job): void
    {
        $emailField = Config::userFields()['email'];
        $idField    = Config::userFields()['id'];

        $row = ServiceLocator::get(Connection::class)
            ->executeQuery(
                'SELECT ' . $emailField . ' AS email FROM ' . USERS_TABLE . ' WHERE ' . $idField . ' = ?',
                [$job->userId]
            )
            ->fetchAssociative();

        if ($row === false || empty($row['email'])) {
            LoggerRegistry::current()->warning('notification.user_not_found', ['user_id' => $job->userId]);
            return;
        }

        ServiceLocator::get(MailService::class)->pwgMail(
            is_string($row['email']) ? $row['email'] : '',
            [
                'subject' => is_scalar($job->params['subject'] ?? null) ? (string) $job->params['subject'] : '',
                'content' => is_scalar($job->params['content'] ?? null) ? (string) $job->params['content'] : '',
            ]
        );
    }
}
