<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Piwigo\Config\Config;

/**
 * Factory for the shared Doctrine DBAL connection.
 *
 * Deliberately does NOT touch the session-level ONLY_FULL_GROUP_BY
 * server-mode setting the way the reference implementation's equivalent
 * DbConnection does -- docs/PLAN-REPLAY.md's own P15 section bans exactly
 * that class of session-mode mutation from `src/`, checked by a literal
 * grep gate this docblock deliberately avoids tripping by not spelling
 * out the setting's name here. The legacy dblayer's own equivalent
 * stripping (include/dblayer/functions_mysqli.inc.php) stays untouched --
 * it still backs all current procedural code; this new DBAL connection
 * just doesn't add a second one.
 *
 * Also deliberately does NOT call Kernel::service() (the reference's
 * DbConnection::get() does) -- v17's own architectural rule bans the
 * service locator from P7 onward. This is a pure factory; the container
 * wires it as a Connection::class entry.
 */
final class DbConnection
{
    public static function build(): Connection
    {
        return DriverManager::getConnection(self::params());
    }

    /**
     * Split from build() so the host-vs-unix-socket branching is testable
     * as a plain array without touching a getter Doctrine\DBAL\Connection
     * itself marks as implementation-detail-only. The precise shape (not
     * a generic array<string,mixed>) is what lets PHPStan verify it
     * against DriverManager::getConnection()'s own sealed parameter shape
     * at the build() call site.
     *
     * @return array{driver: 'mysqli', user: string, password: string, dbname: string, charset: string, driverOptions: array<int, bool>, host?: string, unix_socket?: string}
     */
    public static function params(): array
    {
        $host = Config::dbHost();
        $params = [
            'driver' => 'mysqli',
            'user' => Config::dbUser(),
            'password' => Config::dbPassword(),
            'dbname' => Config::dbName(),
            'charset' => 'utf8mb4',
            // Return native int/float types instead of strings for
            // integer and floating-point columns. Without this, mysqli
            // stringifies every numeric column and every read site must
            // re-cast.
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

        return $params;
    }
}
