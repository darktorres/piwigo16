<?php

declare(strict_types=1);

// Doctrine Migrations configuration array (P14). Genuinely empty of real
// migrations -- P15 adds the first one, same growth discipline as every
// other config/*.php file since P8. Consumed by
// Piwigo\Command\MigrationRunCommand via Doctrine\Migrations\Configuration\
// Migration\ConfigurationArray.

/**
 * @return array<string, mixed>
 */
return [
    'migrations_paths' => [
        'Piwigo\\Migrations' => dirname(__DIR__) . '/src/Piwigo/Migrations',
    ],
];
