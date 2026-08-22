<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/**
 * {@see \Piwigo\Users\Event\RegisterUserCheck}'s own `$user` shape --
 * built from local scalars inside {@see \Piwigo\Users\UserService::
 * registerUser()} *before* the user row is inserted, so no real `User`
 * instance exists yet at this point. Not `User::fromUserArray()` reuse:
 * this is a 3-field pre-insert candidate, not a full user row.
 */
final readonly class RegistrationCandidate
{
    public function __construct(
        public string $username,
        public string $password,
        public ?string $email,
    ) {}
}
