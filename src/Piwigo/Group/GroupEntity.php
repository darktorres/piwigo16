<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\ORM\Mapping as ORM;
use Override;
use Piwigo\Common\ValueObject\GroupId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Db\HasLastModified;

/**
 * Maps the `groups` table. Real shape: id smallint PK auto-increment, name
 * varchar(255) unique, is_default tinyint(1), lastmodified TIMESTAMP --
 * kept on `Env::now()` by {@see \Piwigo\Db\LastModifiedListener} on every
 * entity-flush insert/update, `EntityManagerFactory::build()`-wide, not by
 * this entity or GroupRepository individually.
 *
 * `id`'s `group_id` column type is a custom Doctrine Type
 * ({@see \Piwigo\Db\Type\GroupIdType}, registered in
 * EntityManagerFactory::build()) -- same underlying `INT` SQL, but hydrates
 * straight into a real GroupId VO instead of a raw int.
 */
#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: '`groups`')]
final class GroupEntity implements HasLastModified
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
        #[ORM\Column(type: 'sql_datetime', length: 19)]
        public SqlDateTime $lastmodified,
    ) {}

    #[Override]
    public function touchLastModified(SqlDateTime $now): void
    {
        $this->lastmodified = $now;
    }
}
