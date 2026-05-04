<?php

declare(strict_types=1);

use Piwigo\Config\Config;

// Prefer the typed Config facade when it is already bootstrapped; fall back to
// the env var that ConfigLoader maps to db_prefix.
$prefix = (class_exists(Config::class) && Config::has('db_prefix'))
    ? Config::dbPrefix()
    : (getenv('PIWIGO_DB_PREFIX') ?: 'piwigo_');

return [
    'table_storage' => [
        'table_name'                 => $prefix . 'migration_versions',
        'version_column_name'        => 'version',
        'version_column_length'      => 191,
        'executed_at_column_name'    => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],
    'migrations_paths' => [
        'Piwigo\\Migrations' => __DIR__ . '/src/Piwigo/Migrations',
    ],
    'all_or_nothing'          => true,
    'check_database_platform' => false,
];
