<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\UserId;

/**
 * {@see \Piwigo\Users\UserRepository::findByUsernameCaseInsensitive()}'s
 * own row shape -- {@see \Piwigo\Users\UserService::
 * notifyExistingAccountOfDuplicateRegistration()}'s real (and only)
 * consumer, the SEC-31 duplicate-registration notice. `$email` defaults to
 * `''` (not `null`) for a user with no mail address -- `mailAddress` is
 * nullable at the entity level, but the one real consumer already treats
 * an empty string the same as an invalid address.
 */
final readonly class UsernameLookup
{
    public function __construct(
        public UserId $id,
        public string $username,
        public string $email,
    ) {}
}
