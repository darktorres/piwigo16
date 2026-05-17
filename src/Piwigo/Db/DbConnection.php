<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Piwigo\Config\Config;
use Piwigo\Core\Kernel;

/**
 * Factory for the shared Doctrine DBAL connection.
 *
 * Session tweaks applied on every new connection:
 *   - utf8mb4 charset (via driver 'charset' option)
 *   - ONLY_FULL_GROUP_BY removed from SESSION sql_mode
 */
final class DbConnection
{
    /**
     * Returns the shared connection. Post-boot: from the DI container.
     * Pre-boot (install/upgrade): lazily builds one and caches it statically.
     */
    public static function get(): Connection
    {
        if (Kernel::isBooted()) {
            return Kernel::service(Connection::class);
        }
        static $conn = null;
        if (!($conn instanceof Connection)) {
            $conn = self::build();
        }
        return $conn;
    }

    public static function build(): Connection
    {
        $host   = Config::dbHost();
        $params = [
            'driver'  => 'mysqli',
            'user'    => Config::dbUser(),
            'password' => Config::dbPassword(),
            'dbname'  => Config::dbName(),
            'charset' => 'utf8mb4',
            // Return native int/float types instead of strings for integer
            // and floating-point columns. Without this, mysqli stringifies
            // every numeric column and every read site must re-cast.
            'driverOptions' => [
                MYSQLI_OPT_INT_AND_FLOAT_NATIVE => true,
            ],
        ];

        // A host starting with '/' is treated as a Unix socket path.
        if (str_starts_with($host, '/')) {
            $params['unix_socket'] = $host;
        } else {
            $params['host'] = $host;
        }

        $conn = DriverManager::getConnection($params);

        // Remove ONLY_FULL_GROUP_BY so queries that
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
