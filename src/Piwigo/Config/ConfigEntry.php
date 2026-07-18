<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `config` table (`piwigo_config` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time --
 * the table name below is bare, not prefixed, same convention every
 * future entity uses). Real shape: param varchar(40) PK, value text,
 * comment varchar(255). P15 gives the table InnoDB+utf8mb4, but --
 * per docs/PLAN-REPLAY.md's own P15 section -- `value`'s text->JSON
 * conversion is one of the 43 deferred column-type changes, co-migrating
 * with its consuming service code in P17-23, not P14 or P15 (the
 * reference's own ConfigRepository already treats `value` as native
 * JSON, since it did that conversion upfront rather than phased). `value`
 * stays raw ?string here; encoding is ConfigService's job (matching the
 * legacy load_conf_from_db()'s own split of responsibility).
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
