<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Ws\WsParams;

/**
 * `pwg.users.add` input DTO. `username`/`pwg_token`: no 'default' key --
 * mandatory, always present. `auto_password`: non-null bool default,
 * always present. `password`/`email`: null default, always present,
 * string|null. `password_confirm`: `OPTIONAL` with no 'default' key --
 * may be entirely absent (`null` here means "not provided", matching
 * the original's `$params['password_confirm'] ?? ''` fallback).
 * `send_password_by_mail` is a registered param but deliberately NOT a
 * field here -- the god-class method this replaces already hardcoded
 * `false` for `UserService::registerUser()`'s notify-by-mail argument
 * regardless of what the client sent (see `AddHandler`'s own comment at
 * that call site), so there's nothing to read it into.
 */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public string $username,
        public bool $autoPassword,
        public ?string $password,
        public ?string $passwordConfirm,
        public ?string $email,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $username = $raw['username'] ?? null;
        $autoPassword = $raw['auto_password'] ?? null;
        $password = $raw['password'] ?? null;
        $passwordConfirm = $raw['password_confirm'] ?? null;
        $email = $raw['email'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            username: is_string($username) ? $username : '',
            autoPassword: is_bool($autoPassword) ? $autoPassword : false,
            password: is_string($password) ? $password : null,
            passwordConfirm: is_string($passwordConfirm) ? $passwordConfirm : null,
            email: is_string($email) ? $email : null,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
