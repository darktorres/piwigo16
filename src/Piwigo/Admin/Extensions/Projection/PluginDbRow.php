<?php

declare(strict_types=1);

namespace Piwigo\Admin\Extensions\Projection;

/**
 * {@see \Piwigo\Admin\Extensions\ExtensionRepository::findAllPlugins()}/
 * {@see \Piwigo\Admin\Extensions\ExtensionRepository::findPlugin()}'s own
 * `plugins` row shape (`id`/`state`/`version` -- confirmed via
 * `install/piwigo_structure-mysql.sql`, no `name` column on this table,
 * unlike `themes`/`languages`).
 */
final readonly class PluginDbRow
{
    public function __construct(
        public string $id,
        public string $state,
        public string $version,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: is_string($row['id'] ?? null) ? $row['id'] : '',
            state: is_string($row['state'] ?? null) ? $row['state'] : 'inactive',
            version: is_string($row['version'] ?? null) ? $row['version'] : '0',
        );
    }
}
