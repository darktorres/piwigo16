<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

/**
 * {@see \Piwigo\Users\UserInfoUpdateResult::$account}'s own shape --
 * unlike `$infos` (a genuinely dynamic `user_infos` partial-update bag,
 * see that class's own docblock), the `users` table side of
 * `UserService::checkAndSaveUserInfos()` only ever touches these 3
 * columns, each independently optional.
 */
final readonly class AccountFieldUpdates
{
    public function __construct(
        public ?string $username = null,
        public ?string $password = null,
        public ?string $mailAddress = null,
    ) {}
}
