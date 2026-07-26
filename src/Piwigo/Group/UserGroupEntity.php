<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `user_group` table (group membership) -- composite PK
 * (group_id, user_id), no other columns. No custom repositoryClass:
 * GroupRepository (this table's real owner) queries it directly via DQL/
 * QueryBuilder rather than through a dedicated repository class.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_group')]
final class UserGroupEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'group_id', type: 'integer')]
        public int $groupId,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'integer')]
        public int $userId,
    ) {}
}
