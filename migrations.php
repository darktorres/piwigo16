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
    // Per-migration transactional behavior via isTransactional(). The
    // MyISAM-DDL migrations under src/Piwigo/Migrations/Version2026052*
    // opt out of transactions because CREATE TABLE implicit-commits;
    // all_or_nothing=true would refuse to run them. Each migration is
    // responsible for its own atomicity.
    'all_or_nothing'          => false,
    'check_database_platform' => false,
];
