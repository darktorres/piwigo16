<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Piwigo\Config\Config;

/**
 * Factory for the shared Doctrine DBAL connection.
 *
 * Applies the same session tweaks as pwg_db_connect() so DBAL queries
 * behave consistently with the legacy mysqli layer:
 *   - utf8mb4 charset
 *   - ONLY_FULL_GROUP_BY removed from SESSION sql_mode
 */
final class DbConnection
{
    public static function build(): Connection
    {
        $conn = DriverManager::getConnection([
            'driver'   => 'mysqli',
            'host'     => Config::dbHost(),
            'user'     => Config::dbUser(),
            'password' => Config::dbPassword(),
            'dbname'   => Config::dbName(),
            'charset'  => 'utf8mb4',
        ]);

        // Mirror pwg_db_connect(): remove ONLY_FULL_GROUP_BY so queries that
        // use SELECT t.*, COUNT(*) … GROUP BY t.id work without errors.
        $currentMode = $conn->executeQuery('SELECT @@SESSION.sql_mode')->fetchOne();
        if (is_string($currentMode) && str_contains($currentMode, 'ONLY_FULL_GROUP_BY')) {
            $parts   = array_values(array_diff(explode(',', $currentMode), ['ONLY_FULL_GROUP_BY']));
            $newMode = implode(',', $parts);
            // $newMode contains only uppercase MySQL mode identifiers — safe to embed.
            $conn->executeStatement("SET SESSION sql_mode = '" . $newMode . "'");
        }

        return $conn;
    }
}
