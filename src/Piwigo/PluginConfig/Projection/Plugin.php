<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Projection;

use Piwigo\Common\ValueObject\PluginId;

/**
 * Typed row shape for `piwigo_plugins`.
 * {@see \Piwigo\PluginConfig\PluginRepository}'s
 * `getDbPlugins()` builds this straight from `PluginEntity`'s
 * already-typed properties.
 *
 * `state` stays `string`, not an enum -- 'inactive'/'active' is a genuine
 * multi-value status column, not a boolean-string retype candidate (same
 * category as `categories.status`/`user_infos.status`).
 */
final readonly class Plugin
{
    public function __construct(
        public PluginId $id,
        public string $state,
        public string $version,
    ) {}

    /**
     * @return array{id: string, state: string, version: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'state' => $this->state,
            'version' => $this->version,
        ];
    }
}
