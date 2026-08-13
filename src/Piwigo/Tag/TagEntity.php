<?php

declare(strict_types=1);

namespace Piwigo\Tag;

use Doctrine\ORM\Mapping as ORM;
use Override;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Db\HasLastModified;

/**
 * Maps the `tags` table. Real shape: id smallint PK auto-increment, name
 * varchar(255), url_name varchar(255), lastmodified TIMESTAMP (DB-managed ON
 * UPDATE CURRENT_TIMESTAMP -- set explicitly to Env::now() on insert() only,
 * matching pre-ORM behavior; never touched on any update method since none
 * exists). `lastmodified` is `SqlDateTime`-typed -- matches
 * Tag\Projection\Tag's own plain-string convention at the DTO level ("no real
 * consumer needs anything but the raw DB DATETIME string form").
 *
 * `id`'s `tag_id` column type is a custom Doctrine Type
 * ({@see \Piwigo\Db\Type\TagIdType}, registered in
 * EntityManagerFactory::build()) -- same underlying `INT` SQL, but
 * hydrates straight into a real TagId VO instead of a raw int.
 */
#[ORM\Entity(repositoryClass: TagRepository::class)]
#[ORM\Table(name: 'tags')]
final class TagEntity implements HasLastModified
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'tag_id')]
    public ?TagId $id = null;

    public function __construct(
        #[ORM\Column(type: 'string', length: 255)]
        public string $name,
        #[ORM\Column(name: 'url_name', type: 'string', length: 255)]
        public string $urlName,
        #[ORM\Column(type: 'sql_datetime', length: 19)]
        public SqlDateTime $lastmodified,
    ) {}

    #[Override]
    public function touchLastModified(SqlDateTime $now): void
    {
        $this->lastmodified = $now;
    }
}
