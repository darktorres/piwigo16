<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Session;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.session.login` input DTO — typed username + password.
 * The handler optionally interprets a username of shape
 * `pkid-\d{8}-[a-z0-9]{20}` as an API-key id (the password is then
 * the corresponding key secret).
 */
final readonly class LoginParams implements WsParams
{
    public function __construct(
        public string $username,
        public string $password,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $username = $raw['username'] ?? null;
        $password = $raw['password'] ?? null;
        if (!is_string($username) || $username === '') {
            throw new WsParamException('Missing or invalid `username`');
        }
        if (!is_string($password)) {
            throw new WsParamException('Missing or invalid `password`');
        }
        return new self($username, $password);
    }
}
