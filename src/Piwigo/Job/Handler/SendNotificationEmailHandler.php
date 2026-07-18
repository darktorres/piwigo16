<?php

declare(strict_types=1);

namespace Piwigo\Job\Handler;

use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Mail\MailService;

/**
 * Not attribute-discovered (#[AsMessageHandler]) -- this project doesn't
 * use Symfony's framework bundle/DI-container auto-wiring for Messenger;
 * Piwigo\Job\MessengerFactory wires this class to SendNotificationEmailJob
 * explicitly via config/messenger.php's handler map.
 */
final readonly class SendNotificationEmailHandler
{
    public function __construct(
        private MailService $mailService,
    ) {}

    public function __invoke(SendNotificationEmailJob $job): void
    {
        $this->mailService->mail($job->to, $job->args, $job->tpl);
    }
}
