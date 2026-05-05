<?php

declare(strict_types=1);

namespace Piwigo\Job;

final readonly class SendNotificationEmailJob
{
    /** @param array<string, mixed> $params */
    public function __construct(
        public int $userId,
        public string $template,
        public array $params,
    ) {
    }
}
