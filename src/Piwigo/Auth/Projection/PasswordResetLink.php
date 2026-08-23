<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\AuthService::generatePasswordLink()}'s own fixed
 * result shape.
 */
final readonly class PasswordResetLink
{
    public function __construct(
        public string $timeValidation,
        public string $passwordLink,
    ) {}
}
