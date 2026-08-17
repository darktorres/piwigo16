<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\ApiKeyService::create()}'s own fixed result shape.
 */
final readonly class ApiKeyCreated
{
    public function __construct(
        public string $authKey,
        public string $apikeySecret,
        public string $apikeyName,
        public string $createdOn,
        public int $duration,
        public string $expiredOn,
    ) {}
}
