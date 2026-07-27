<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\UserId;

/**
 * Maps the `user_group` table (group membership) -- composite PK
 * (group_id, user_id), no other columns. No custom repositoryClass:
 * GroupRepository (this table's real owner) queries it directly via DQL/
 * QueryBuilder rather than through a dedicated repository class.
 *
 * Both id columns use custom Doctrine Types ({@see \Piwigo\Db\Type\GroupIdType},
 * {@see \Piwigo\Db\Type\UserIdType}) -- same underlying `INT` SQL, real VOs
 * on the PHP side, including as part of this composite identity.
 */
#[ORM\Entity]
#[ORM\Table(name: 'user_group')]
final class UserGroupEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'group_id', type: 'group_id')]
        public GroupId $groupId,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'user_id')]
        public UserId $userId,
    ) {}
}
