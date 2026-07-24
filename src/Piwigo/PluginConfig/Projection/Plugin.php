<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Projection;

/**
 * Typed row shape for `piwigo_plugins` (P17-23 Stage 1b, PluginConfig
 * domain -- `docs/PLAN-REPLAY.md`'s own "7 Entity types, 73 projection
 * shapes" reference). `fromRow()` centralises the `is_string($row['x']) ?
 * ... : default` narrowing {@see \Piwigo\PluginConfig\PluginRepository}'s
 * own caller ({@see \Piwigo\Admin\PluginLoader::loadPlugin()}) used to do
 * inline, same shape as {@see \Piwigo\Category\Projection\Category}.
 *
 * `state` stays `string`, not an enum -- 'inactive'/'active' is a genuine
 * multi-value status column, not a boolean-string retype candidate (same
 * category as `categories.status`/`user_infos.status`).
 */
final readonly class Plugin
{
    public function __construct(
        public string $id,
        public string $state,
        public string $version,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_plugins`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            state: is_string($row['state'] ?? null) ? $row['state'] : 'inactive',
            version: is_string($row['version'] ?? null) ? $row['version'] : '0',
        );
    }

    /**
     * @return array{id: string, state: string, version: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'version' => $this->version,
        ];
    }
}
