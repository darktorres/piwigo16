<?php

declare(strict_types=1);

namespace Piwigo\Permission;

/** (cat_id, uppercats, global_rank) from PermissionRepository::findGroupAuthorizedCategoriesForUser(). */
final readonly class CatUppercatRank
{
    public function __construct(
        public int     $catId,
        public string  $uppercats,
        public ?string $globalRank,
    ) {
    }
}
