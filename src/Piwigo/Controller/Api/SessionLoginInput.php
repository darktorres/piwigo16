<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

/**
 * `POST /api/v1/session` body DTO.
 */
final readonly class SessionLoginInput
{
    public function __construct(
        public string $username,
        public ?string $password,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $username = $raw['username'] ?? null;
        $password = $raw['password'] ?? null;

        return new self(
            username: is_string($username) ? $username : '',
            password: is_string($password) ? $password : null,
        );
    }
}
