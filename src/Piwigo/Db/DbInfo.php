<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;

/**
 * Constructor-injected, not the reference's Kernel::service(Connection::class)
 * locator call -- matches v17's DI convention.
 */
final readonly class DbInfo
{
    public function __construct(
        private Connection $conn,
    ) {}

    public function version(): string
    {
        $v = $this->conn->executeQuery('SELECT VERSION()')
            ->fetchOne();
        return is_string($v) ? $v : '';
    }
}
