<?php

declare(strict_types=1);

namespace Piwigo\Lang;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\LangCode;

/**
 * Maps the `languages` table. Real shape: id varchar(64) PK, version
 * varchar(64) NOT NULL default '0', name varchar(64) nullable.
 */
#[ORM\Entity(repositoryClass: LangRepository::class)]
#[ORM\Table(name: 'languages')]
final class LanguageEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'lang_code', length: 64)]
        public LangCode $id,
        #[ORM\Column(type: 'string', length: 64)]
        public string $version = '0',
        #[ORM\Column(type: 'string', length: 64, nullable: true)]
        public ?string $name = null,
    ) {}
}
