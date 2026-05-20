<?php

declare(strict_types=1);

namespace Piwigo\Job;

/** Serialized failed-job row returned by MessengerRepository::findFailedJobById(). */
final readonly class FailedJob
{
    public function __construct(
        public string $body,
        public string $headers,
    ) {
    }
}
