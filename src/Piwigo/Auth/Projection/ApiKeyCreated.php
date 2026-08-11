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
        public int $userId,
        public string $createdOn,
        public int $duration,
        public string $keyType,
        public string $expiredOn,
    ) {}

    /**
     * {@see \Piwigo\Ws\Users::createApiKey()} returns this directly as
     * the WS RPC response -- unbox to array at that wire-format boundary.
     *
     * @return array{auth_key: string, apikey_secret: string, apikey_name: string, user_id: int, created_on: string, duration: int, key_type: string, expired_on: string}
     */
    public function toArray(): array
    {
        return [
            'auth_key' => $this->authKey,
            'apikey_secret' => $this->apikeySecret,
            'apikey_name' => $this->apikeyName,
            'user_id' => $this->userId,
            'created_on' => $this->createdOn,
            'duration' => $this->duration,
            'key_type' => $this->keyType,
            'expired_on' => $this->expiredOn,
        ];
    }
}
