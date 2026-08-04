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
        $v = $this->conn->executeQuery(<<<SQL
            SELECT VERSION()
            SQL)
            ->fetchOne();
        return is_string($v) ? $v : '';
    }

    /**
     * The DB server's own current date/time, read live (not via
     * Env::now(), which is invisible to this and would defeat the whole
     * point) -- Admin\MaintenanceActionsPageRenderer/MaintenanceEnvPageRenderer's
     * own "does the DB server's clock match the PHP server's clock"
     * diagnostic display, and PiwigoInfosSender's own equivalent
     * `db_datetime` telemetry field.
     */
    public function currentDateTime(): ?string
    {
        $v = $this->conn->fetchOne(<<<SQL
            SELECT NOW()
            SQL);

        return is_string($v) ? $v : null;
    }

    /**
     * A cheap "did this table change" fingerprint (its own max
     * `lastmodified` timestamp plus row count) -- Admin\AdminUiHelper's
     * own client-side cache-busting key, called on different tables from
     * multiple unrelated domains (categories/groups/images/tags/
     * user_infos), no single owning domain.
     */
    public function getTableFingerprint(string $table): string
    {
        // SQL-modernization audit: {$table} verified structural -- its one
        // real caller (Admin\AdminUiHelper::getAdminClientCacheKeys())
        // only ever passes a value drawn from a fixed Db\Tables::xxx()
        // array via array_intersect() against that same array's own keys,
        // never an arbitrary/request-derived table name.
        $value = $this->conn->fetchOne(<<<SQL
            SELECT CONCAT(
                UNIX_TIMESTAMP(MAX(lastmodified)),
                "_",
                COUNT(*)
              )
            FROM `{$table}`
            SQL);

        return is_string($value) ? $value : '';
    }
}
