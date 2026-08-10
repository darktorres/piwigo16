<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `config` table. Real shape: param varchar(40) PK, value text,
 * comment varchar(255), InnoDB+utf8mb4. `value` stays raw ?string here,
 * not a native JSON column type; encoding is ConfigService's job
 * (matching the legacy load_conf_from_db()'s own split of
 * responsibility).
 */
#[ORM\Entity(repositoryClass: ConfigRepository::class)]
#[ORM\Table(name: 'config')]
final class ConfigEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 40)]
        public string $param,
        #[ORM\Column(type: 'text', nullable: true)]
        public ?string $value = null,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        public ?string $comment = null
    ) {}
}
