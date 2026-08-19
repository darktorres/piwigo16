<?php

declare(strict_types=1);

namespace Piwigo\Users\Projection;

use Piwigo\Common\ValueObject\UserId;

/**
 * {@see \Piwigo\Users\Event\RegisterUser}'s own `$user` shape -- built
 * from local scalars inside {@see \Piwigo\Users\UserService::
 * registerUser()} right after the user row is inserted. Not `User::
 * fromUserArray()` reuse: this is a 3-field post-insert summary (no
 * language/theme/status/etc yet), not a full user row.
 */
final readonly class NewUserSummary
{
    public function __construct(
        public UserId $id,
        public string $username,
        public ?string $email,
    ) {}
}
