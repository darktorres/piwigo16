<?php

declare(strict_types=1);

namespace Piwigo\Group;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `groups` table (`piwigo_groups` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * Real shape: id smallint PK auto-increment, name varchar(255) unique,
 * is_default tinyint(1), lastmodified TIMESTAMP (DB-managed ON UPDATE
 * CURRENT_TIMESTAMP -- set explicitly to Env::now() on insert only,
 * matching GroupRepository::insert()'s pre-ORM behavior exactly; never
 * touched on update(), same as before).
 */
#[ORM\Entity(repositoryClass: GroupRepository::class)]
#[ORM\Table(name: 'groups')]
final class GroupEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $name,
        #[ORM\Column(name: 'is_default', type: 'boolean')]
        public bool $isDefault,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $lastmodified,
    ) {}
}
