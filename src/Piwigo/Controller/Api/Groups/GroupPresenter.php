<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Groups;

use Piwigo\Group\Projection\GroupListing;

/**
 * Shared `GroupListing` -> REST JSON row shape, camelCased -- every group
 * mutation in this family returns this same row shape for the group(s) it
 * touched, so every mutating controller here needs this same mapping, not
 * just the list endpoint.
 */
final class GroupPresenter
{
    /**
     * @return array{id: int, name: string, isDefault: bool, lastmodified: string, nbUsers: int}
     */
    public static function toArray(GroupListing $group): array
    {
        return [
            'id' => $group->id->value,
            'name' => $group->name,
            'isDefault' => $group->isDefault,
            'lastmodified' => $group->lastmodified,
            'nbUsers' => $group->nbUsers,
        ];
    }
}
