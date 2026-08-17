<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

/**
 * `POST /api/v1/session/api-keys` body DTO.
 */
final readonly class ApiKeyCreateInput
{
    public function __construct(
        public string $keyName,
        public int $duration,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $keyName = $raw['keyName'] ?? null;
        $duration = $raw['duration'] ?? null;

        return new self(
            keyName: is_string($keyName) ? $keyName : '',
            duration: is_int($duration) ? $duration : 0,
        );
    }
}
