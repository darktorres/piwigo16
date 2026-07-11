<?php

declare(strict_types=1);

namespace Piwigo\Config;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the CURRENT, un-migrated `config` table (`piwigo_config` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time --
 * the table name below is bare, not prefixed, same convention every
 * future entity uses). Real shape: param varchar(40) PK, value text,
 * comment varchar(255) -- verified directly against
 * install/piwigo_structure-mysql.sql, not assumed from the reference
 * (whose own ConfigRepository treats `value` as a native JSON column,
 * which it isn't yet: docs/PLAN-REPLAY.md's own P15 section lists
 * config.value's text->JSON conversion as one of the 43 deferred
 * column-type changes, co-migrating with its consuming service code in
 * P17-23 -- not P14 or P15). `value` stays raw ?string here; encoding is
 * ConfigService's job (matching the legacy load_conf_from_db()'s own
 * split of responsibility).
 */
#[ORM\Entity(repositoryClass: ConfigRepository::class)]
#[ORM\Table(name: 'config')]
final class ConfigEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 40)]
    public string $param;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $value = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    public ?string $comment = null;

    public function __construct(string $param, ?string $value = null, ?string $comment = null)
    {
        $this->param = $param;
        $this->value = $value;
        $this->comment = $comment;
    }
}
