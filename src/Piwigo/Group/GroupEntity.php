<?php

declare(strict_types=1);

namespace Piwigo\Group;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\GroupId;

/**
 * Maps the `groups` table. Real shape: id smallint PK auto-increment, name
 * varchar(255) unique, is_default tinyint(1), lastmodified TIMESTAMP
 * (DB-managed ON UPDATE CURRENT_TIMESTAMP -- set explicitly to Env::now() on
 * insert only, matching GroupRepository::insert()'s pre-ORM behavior exactly;
 * never touched on update(), same as before).
 *
 * `id`'s `group_id` column type is a custom Doctrine Type
 * ({@see \Piwigo\Db\Type\GroupIdType}, registered in
 * EntityManagerFactory::build()) -- same underlying `INT` SQL, but hydrates
 * straight into a real GroupId VO instead of a raw int.
 */
#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: '`groups`')]
final class GroupEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'group_id')]
    public ?GroupId $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $name,
        #[ORM\Column(name: 'is_default', type: 'boolean')]
        public bool $isDefault,
        #[ORM\Column(type: 'datetime_immutable')]
        public DateTimeImmutable $lastmodified,
    ) {}
}
