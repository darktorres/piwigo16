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
use Piwigo\Db\Tables;

#[AsMessageHandler]
final class SendNotificationEmailHandler
{
    public function __invoke(SendNotificationEmailJob $job): void
    {
        $to = is_string($job->params['to'] ?? null) ? (string) $job->params['to'] : '';

        if ($to === '') {
            $emailField = Config::userFields()['email'];
            $idField    = Config::userFields()['id'];

            $row = ServiceLocator::get(Connection::class)
                ->executeQuery(
                    'SELECT ' . $emailField . ' AS email FROM ' . Tables::users() . ' WHERE ' . $idField . ' = ?',
                    [$job->userId]
                )
                ->fetchAssociative();

            if ($row === false || empty($row['email'])) {
                LoggerRegistry::current()->warning('notification.user_not_found', ['user_id' => $job->userId]);
                return;
            }

            $to = is_string($row['email']) ? $row['email'] : '';
        }

        if ($to === '') {
            return;
        }

        ServiceLocator::get(MailService::class)->pwgMail(
            $to,
            [
                'subject'        => is_scalar($job->params['subject'] ?? null) ? (string) $job->params['subject'] : '',
                'content'        => is_scalar($job->params['content'] ?? null) ? (string) $job->params['content'] : '',
                'content_format' => is_scalar($job->params['content_format'] ?? null) ? (string) $job->params['content_format'] : 'text/html',
            ]
        );
    }
}
