<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Users;

/**
 * `POST /api/v1/users` body DTO. `sendPasswordByMail` is accepted but
 * ignored -- a registered-but-unused no-op.
 */
final readonly class UserCreateInput
{
    public function __construct(
        public string $username,
        public bool $autoPassword,
        public ?string $password,
        public ?string $passwordConfirm,
        public ?string $email,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $username = $raw['username'] ?? null;
        $autoPassword = $raw['autoPassword'] ?? null;
        $password = $raw['password'] ?? null;
        $passwordConfirm = $raw['passwordConfirm'] ?? null;
        $email = $raw['email'] ?? null;

        return new self(
            username: is_string($username) ? $username : '',
            autoPassword: is_bool($autoPassword) ? $autoPassword : false,
            password: is_string($password) ? $password : null,
            passwordConfirm: is_string($passwordConfirm) ? $passwordConfirm : null,
            email: is_string($email) ? $email : null,
        );
    }
}
