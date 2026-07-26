<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `plugins` table (`piwigo_plugins` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `id` is the plugin directory-name identifier (application-assigned).
 */
#[ORM\Entity(repositoryClass: PluginRepository::class)]
#[ORM\Table(name: 'plugins')]
final class PluginEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        public string $id,
        #[ORM\Column(type: 'string', length: 10)]
        public string $state,
        #[ORM\Column(type: 'string', length: 64)]
        public string $version,
    ) {}
}
