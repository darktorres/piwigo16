<?php

declare(strict_types=1);

namespace Piwigo\Permission\Projection;

/**
 * Shared row shape for {@see \Piwigo\Permission\PermissionRepository::findDirectUserAccessRows()}
 * and {@see \Piwigo\Permission\PermissionRepository::findIndirectUserAccessRows()}
 * (identical `user_id`/`cat_id` pair, one direct via `user_access`, one via
 * group membership) -- both feed {@see \Piwigo\Ws\PwgPermissions::getList()}'s
 * own per-category user-id grouping.
 */
final readonly class UserAccessRow
{
    public function __construct(
        public int $userId,
        public int $catId,
    ) {}
}
