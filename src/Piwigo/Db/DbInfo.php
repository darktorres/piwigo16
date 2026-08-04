<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

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
     *
     * pgsql support pass: `CONCAT()` itself is portable (Postgres has had
     * it since 9.1); only `UNIX_TIMESTAMP()` needed a real per-platform
     * swap (`EXTRACT(EPOCH FROM ...)::bigint` -- the `::bigint` cast
     * matches `UNIX_TIMESTAMP()`'s own whole-seconds-integer result,
     * `EXTRACT()` alone returns a fractional `numeric`). Also fixed a
     * latent portability bug found while touching this method: the `"_"`
     * separator was double-quoted, which MySQL's default (non-ANSI_QUOTES)
     * SQL mode happens to accept as a string literal but Postgres always
     * rejects as an identifier reference -- switched to the single-quoted
     * form both platforms treat identically as a real string literal.
     */
    public function getTableFingerprint(string $table): string
    {
        // SQL-modernization audit: {$table} verified structural -- its one
        // real caller (Admin\AdminUiHelper::getAdminClientCacheKeys())
        // only ever passes a value drawn from a fixed Db\Tables::xxx()
        // array via array_intersect() against that same array's own keys,
        // never an arbitrary/request-derived table name. Left unquoted
        // (no backticks/double-quotes) -- every real table name here is
        // already lowercase snake_case, valid unquoted on both platforms.
        $epochExpr = $this->conn->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? 'EXTRACT(EPOCH FROM MAX(lastmodified))::bigint'
            : 'UNIX_TIMESTAMP(MAX(lastmodified))';

        $value = $this->conn->fetchOne(<<<SQL
            SELECT CONCAT(
                {$epochExpr},
                '_',
                COUNT(*)
              )
            FROM {$table}
            SQL);

        return is_string($value) ? $value : '';
    }
}
