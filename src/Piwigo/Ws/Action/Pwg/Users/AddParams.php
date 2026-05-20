<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.add` input DTO. */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public string $username,
        public string $password,
        public ?string $passwordConfirm,
        public ?string $email,
        public bool $autoPassword,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        $usernameIn = $raw['username'] ?? null;
        $passwordIn = $raw['password'] ?? null;
        $confirmIn  = $raw['password_confirm'] ?? null;
        $emailIn    = $raw['email'] ?? null;
        return new self(
            pwgToken:        $pwgToken,
            username:        is_string($usernameIn) ? $usernameIn : '',
            password:        is_string($passwordIn) ? $passwordIn : '',
            passwordConfirm: is_string($confirmIn) ? $confirmIn : null,
            email:           is_string($emailIn) ? $emailIn : null,
            autoPassword:    (bool) ($raw['auto_password'] ?? false),
        );
    }
}
