<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\AuthRepository::findUsernameAndPassword()}'s own row
 * shape -- shared by {@see \Piwigo\Auth\AuthService::calculateAutoLoginKey()}
 * (the remember-me HMAC input) and {@see \Piwigo\Auth\AuthService::getPasswordHash()}.
 */
final readonly class UsernamePassword
{
    public function __construct(
        public string $username,
        public string $password,
    ) {}
}
