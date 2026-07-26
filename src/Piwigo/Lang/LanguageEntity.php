<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `languages` table (`piwigo_languages` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * Real shape: id varchar(64) PK, version varchar(64) NOT NULL default '0',
 * name varchar(64) nullable.
 */
#[ORM\Entity(repositoryClass: LangRepository::class)]
#[ORM\Table(name: 'languages')]
final class LanguageEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        public string $id,
        #[ORM\Column(type: 'string', length: 64)]
        public string $version = '0',
        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public ?string $name = null,
    ) {}
}
