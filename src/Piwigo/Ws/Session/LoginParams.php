<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Session;

use Piwigo\Ws\WsParams;

/**
 * `pwg.session.login` input DTO. `username`: no 'default' key -- mandatory,
 * always a plain string. `password`: null default -- always present,
 * string|null.
 */
final readonly class LoginParams implements WsParams
{
    public function __construct(
        public string $username,
        public ?string $password,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $username = $raw['username'] ?? null;
        $password = $raw['password'] ?? null;

        return new self(
            username: is_string($username) ? $username : '',
            password: is_string($password) ? $password : null,
        );
    }
}
