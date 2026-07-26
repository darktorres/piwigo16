<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `tags` table (`piwigo_tags` once Piwigo\Db\TablePrefixListener
 * applies db_prefix at metadata-load time). Real shape: id smallint PK
 * auto-increment, name varchar(255), url_name varchar(255), lastmodified
 * TIMESTAMP (DB-managed ON UPDATE CURRENT_TIMESTAMP -- set explicitly to
 * Env::now() on insert() only, matching pre-ORM behavior; never touched on
 * any update method since none exists). `lastmodified` stays a plain
 * string, not \DateTimeImmutable -- matches Tag\Projection\Tag's own
 * already-documented decision ("no real consumer needs anything but the
 * raw DB DATETIME string form").
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
final class TagEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $name,
        #[ORM\Column(name: 'url_name', type: 'string', length: 255)]
        public string $urlName,
        #[ORM\Column(type: 'string', length: 19)]
        public string $lastmodified,
    ) {}
}
