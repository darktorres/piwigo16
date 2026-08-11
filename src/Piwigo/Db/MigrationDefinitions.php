<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * Doctrine Migrations configuration array, consumed by
 * MigrationDependencyFactory::build() (which also merges in a
 * table_storage.table_name at resolution time -- see that method's own
 * docblock).
 */
final class MigrationDefinitions
{
    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'migrations_paths' => [
                'Piwigo\\Migrations' => dirname(__DIR__) . '/Migrations',
            ],
        ];
    }
}
