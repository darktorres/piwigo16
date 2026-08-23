<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\AuthService::generateUserCode()}'s own fixed result
 * shape.
 */
final readonly class UserVerificationCode
{
    public function __construct(
        public string $secret,
        public string $code,
    ) {}
}
