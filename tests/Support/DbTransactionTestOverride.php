<?php

declare(strict_types=1);

namespace Piwigo\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Piwigo\Db\DbConnection;

/**
 * Wires DbConnection::useTestOverride() into a real begin/rollback pair for
 * one Unit test -- tests/Pest.php's own global beforeEach()/afterEach()
 * hooks call begin()/rollback() around every Unit test, so every
 * DbConnection::build() call anywhere in that test's call graph (test code,
 * service internals, container-resolved Connection::class) transparently
 * returns the same connection, already inside an open transaction that never
 * commits. This is what makes a test's own writes invisible to every other
 * --parallel worker's connection without that test needing to thread one
 * connection through by hand.
 *
 * A fresh physical connection is opened per test (not reused across tests in
 * a worker) and explicitly closed in rollback(), matching tests/Pest.php's
 * own existing gc_collect_cycles() hook and the connection-accumulation risk
 * its docblock documents -- reusing one memoized connection for a whole
 * worker's lifetime would sidestep that safeguard.
 */
final class DbTransactionTestOverride
{
    private static ?Connection $conn = null;

    public static function begin(): void
    {
        $conn = DriverManager::getConnection(DbConnection::params());
        $conn->beginTransaction();
        self::$conn = $conn;
        DbConnection::useTestOverride($conn);
    }

    public static function rollback(): void
    {
        if (self::$conn instanceof Connection && self::$conn->isTransactionActive()) {
            self::$conn->rollBack();
        }

        self::$conn?->close();
        self::$conn = null;
        DbConnection::useTestOverride(null);
    }
}
