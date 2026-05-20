<?php

declare(strict_types=1);

namespace Piwigo\Permission;

/** (group_id, cat_id) pair from the group_access table. */
final readonly class GroupCatAccess
{
    public function __construct(
        public int $groupId,
        public int $catId,
    ) {
    }
}
