<?php

declare(strict_types=1);

namespace Piwigo\Users;

/** Username + hashed password pair returned by the auth-fields query for auto-login key computation. */
final readonly class StoredCredentials
{
    public function __construct(
        public string $username,
        public string $password,
    ) {
    }
}
