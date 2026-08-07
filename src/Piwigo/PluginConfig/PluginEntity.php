<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Doctrine\ORM\Mapping as ORM;
use Piwigo\Common\ValueObject\PluginId;

/**
 * Maps the `plugins` table (`piwigo_plugins` once
 * Piwigo\Db\TablePrefixListener applies db_prefix at metadata-load time).
 * `id` is the plugin directory-name identifier (application-assigned).
 *
 * `state` is `PluginState` (a native Doctrine `enumType` column) --
 * {@see PluginRepository::getDbPlugins()}'s own real caller unwraps
 * `->value` right after full-entity hydration, preserving
 * {@see \Piwigo\PluginConfig\Projection\Plugin}'s plain-string `$state`
 * contract.
 *
 * `$id` uses the `plugin_id` custom Doctrine Type
 * (`Piwigo\Common\ValueObject\PluginId`), same shape as
 * `ThemeEntity::$id`.
 */
#[ORM\Entity(repositoryClass: PluginRepository::class)]
#[ORM\Table(name: 'plugins')]
final class PluginEntity
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'plugin_id', length: 64)]
        public PluginId $id,
        #[ORM\Column(type: 'string', length: 10, enumType: PluginState::class)]
        public PluginState $state,
        #[ORM\Column(type: 'string', length: 64)]
        public string $version,
    ) {}
}
