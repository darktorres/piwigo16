<?php

declare(strict_types=1);

// Doctrine Migrations configuration array, consumed by config/container.php's
// DependencyFactory::class binding (which also merges in a
// table_storage.table_name at resolution time -- see that binding's own
// docblock).

/**
 * @return array<string, mixed>
 */
return [
    'migrations_paths' => [
        'Piwigo\\Migrations' => dirname(__DIR__) . '/src/Piwigo/Migrations',
    ],
];
