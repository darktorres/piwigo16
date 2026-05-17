<?php

declare(strict_types=1);

namespace Piwigo\Search;

use Doctrine\DBAL\Connection;
use Piwigo\Db\Tables;

/**
 * Persistence for the admin "search filter views" preset list, which
 * controls which filter fields the gallery search UI renders and at
 * what access level. One row per preset name.
 *
 * Replaces the legacy `piwigo_conf.filters_views` row (which held the
 * whole map as a single PHP-serialized blob). The dedicated table
 * makes the storage match the access semantics: each filter view is
 * a discrete row.
 *
 * Entries are keyed by name. Values are heterogeneous: most are an
 * `array{access: string, default: bool}` map; the special
 * `last_filters_conf` entry is a plain bool. The repo round-trips
 * both shapes through `config_json` (JSON-encoded).
 */
final readonly class SearchFilterViewRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * Load every filter view as a flat map name → config. Matches the
     * shape the legacy serialized blob returned.
     *
     * @return array<string, mixed>
     */
    public function listAll(): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT name, config_json FROM ' . Tables::searchFilterView()
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $name = is_string($row['name'] ?? null) ? $row['name'] : null;
            $raw  = is_string($row['config_json'] ?? null) ? $row['config_json'] : null;
            if ($name === null || $raw === null) {
                continue;
            }
            $decoded = json_decode($raw, associative: true);
            $out[$name] = is_array($decoded) || is_bool($decoded) ? $decoded : null;
        }
        return $out;
    }

    /**
     * Replace the entire filter-views map atomically: DELETE everything
     * then INSERT one row per provided entry. The map preserves the
     * legacy "single row, single value" overwrite semantics.
     *
     * @param array<string, mixed> $configs
     */
    public function replaceAll(array $configs): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::searchFilterView());
        foreach ($configs as $name => $config) {
            if ($name === '') {
                continue;
            }
            $this->conn->executeStatement(
                'INSERT INTO ' . Tables::searchFilterView()
                . ' (name, config_json, created_at) VALUES (?, ?, NOW())',
                [
                    $name,
                    json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]
            );
        }
    }

    public function deleteAll(): void
    {
        $this->conn->executeStatement('DELETE FROM ' . Tables::searchFilterView());
    }

    /**
     * True if any row exists. Callers use this to decide whether to
     * seed the default filter views on first access.
     */
    public function hasAny(): bool
    {
        $count = $this->conn->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::searchFilterView()
        )->fetchOne();
        return is_numeric($count) && (int) $count > 0;
    }
}
