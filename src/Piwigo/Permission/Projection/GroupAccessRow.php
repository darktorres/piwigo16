<?php

declare(strict_types=1);

namespace Piwigo\Permission\Projection;

/**
 * {@see \Piwigo\Permission\PermissionRepository::findGroupAccessRows()}'s
 * own row shape -- feeds {@see \Piwigo\Ws\Permissions::getList()}'s own
 * per-category group-id grouping.
 */
final readonly class GroupAccessRow
{
    public function __construct(
        public int $groupId,
        public int $catId,
    ) {}
}
