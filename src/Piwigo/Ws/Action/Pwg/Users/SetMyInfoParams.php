<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.users.setMyInfo` input DTO — self-service profile fields.
 *
 * Keeps the raw assoc array around so the handler can pass it
 * directly to UserService::checkAndSaveUserInfos() after stripping
 * privileged fields. `password` here is the user's *current* password
 * (verification), not a new one — that's `newPassword`.
 */
final readonly class SetMyInfoParams implements WsParams
{
    /** @param array<int|string, mixed> $raw */
    public function __construct(
        public string $pwgToken,
        public ?string $password,
        public ?string $newPassword,
        public ?string $confNewPassword,
        public array $raw,
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
        $passwordIn = $raw['password']           ?? null;
        $newPwdIn   = $raw['new_password']       ?? null;
        $confPwdIn  = $raw['conf_new_password']  ?? null;
        return new self(
            pwgToken:        $pwgToken,
            password:        is_string($passwordIn) ? $passwordIn : null,
            newPassword:     is_string($newPwdIn) ? $newPwdIn : null,
            confNewPassword: is_string($confPwdIn) ? $confPwdIn : null,
            raw:             $raw,
        );
    }
}
