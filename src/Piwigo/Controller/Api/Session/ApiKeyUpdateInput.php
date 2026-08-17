<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Session;

/**
 * `PATCH /api/v1/session/api-keys/{pkid}` body DTO -- mirrors
 * `Ws\Users\EditApiKeyParams`'s own `key_name` field.
 */
final readonly class ApiKeyUpdateInput
{
    public function __construct(
        public string $keyName,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $keyName = $raw['keyName'] ?? null;

        return new self(
            keyName: is_string($keyName) ? $keyName : '',
        );
    }
}
