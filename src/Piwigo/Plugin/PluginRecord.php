<?php

declare(strict_types=1);

namespace Piwigo\Plugin;

/**
 * Typed row from the `plugins` table. Replaces the loose
 * `array{id: string, state: string, version: string}` shape that
 * `PluginRepository::findAll()` previously returned, which downstream
 * code (Admin\Plugins, PluginRegistry, ExtensionsController, WS
 * extension handlers, UpgradeService) destructured by string key.
 */
final readonly class PluginRecord
{
    public function __construct(
        public string      $id,
        public PluginState $state,
        public string      $version,
    ) {
    }
}
