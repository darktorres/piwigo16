<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Config\Config;
use Piwigo\Core\Kernel;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Db\Tables;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Mail\MailService;
use Piwigo\Users\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendNotificationEmailHandler
{
    public function __invoke(SendNotificationEmailJob $job): void
    {
        $to = is_string($job->params['to'] ?? null) ? (string) $job->params['to'] : '';

        if ($to === '') {
            $userFields = Config::userFields();
            $to = Kernel::service(UserRepository::class)->findEmailByUserId(
                $userFields->email,
                $userFields->id,
                Tables::users(),
                $job->userId,
            );
            if ($to === '') {
                LoggerRegistry::current()->warning('notification.user_not_found', ['user_id' => $job->userId]);
                return;
            }
        }

        Kernel::service(MailService::class)->pwgMail(
            $to,
            [
                'subject'        => is_scalar($job->params['subject'] ?? null) ? (string) $job->params['subject'] : '',
                'content'        => is_scalar($job->params['content'] ?? null) ? (string) $job->params['content'] : '',
                'content_format' => is_scalar($job->params['content_format'] ?? null) ? (string) $job->params['content_format'] : 'text/html',
            ]
        );
    }
}
