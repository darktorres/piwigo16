<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig\Projection;

/**
 * Typed row shape for `piwigo_plugins` (P17-23 Stage 1b, PluginConfig
 * domain -- `docs/PLAN.md`'s own "7 Entity types, 73 projection
 * shapes" reference). `fromRow()` is unused since the DBAL->ORM migration
 * (c9b0ff0a8): {@see \Piwigo\PluginConfig\PluginRepository}'s
 * `getDbPlugins()` now builds this straight from `PluginEntity`'s
 * already-typed properties instead of narrowing a raw `$row` array; kept
 * only for parity with the Projection convention's own
 * `fromRow()`/`toArray()` shape, same shape as
 * {@see \Piwigo\Category\Projection\Category}.
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
