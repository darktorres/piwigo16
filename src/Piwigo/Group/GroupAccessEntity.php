<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `group_access` table (per-group category permissions) --
 * composite PK (group_id, cat_id), no other columns. No custom
 * repositoryClass: GroupRepository (this table's real owner) queries it
 * directly via DQL/QueryBuilder rather than through a dedicated
 * repository class. Permission\PermissionRepository also reads this table
 * (cross-cutting forbidden-category computation) via plain DBAL on the
 * shared connection -- it doesn't own or map it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'group_access')]
final class GroupAccessEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'group_id', type: 'integer')]
        public int $groupId,
        #[ORM\Id]
        #[ORM\Column(name: 'cat_id', type: 'integer')]
        public int $catId,
    ) {}
}
