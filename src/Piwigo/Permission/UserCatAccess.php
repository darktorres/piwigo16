<?php

declare(strict_types=1);

namespace Piwigo\Permission;

/** (user_id, cat_id) pair from user_access or the user_group×group_access join. */
final readonly class UserCatAccess
{
    public function __construct(
        public int $userId,
        public int $catId,
    ) {
    }
}
