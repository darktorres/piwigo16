<?php

declare(strict_types=1);

namespace Piwigo\Auth\Projection;

/**
 * {@see \Piwigo\Auth\AuthService::calculateAutoLoginKey()}'s own return
 * shape -- `key` is `false` when the user wasn't found.
 */
final readonly class AutoLoginKey
{
    public function __construct(
        public string|false $key,
        public string $username,
    ) {}
}
