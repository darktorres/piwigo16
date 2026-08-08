<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\UserId;

/**
 * {@see \Piwigo\Users\UserRepository::findUsernamesByIds()}'s own row
 * shape -- {@see \Piwigo\Users\UserService::getUsernamesByIds()}'s real
 * (and only) consumer, which immediately keys the result by id.
 *
 * Both fields stay nullable for the same gotcha #1 hydration-surprise
 * reason as {@see NotificationRecipient}.
 */
final readonly class UsernameById
{
    public function __construct(
        public ?UserId $id,
        public ?string $username,
    ) {}
}
