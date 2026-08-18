<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;

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
        // sqlite3 has no VERSION() -- sqlite_version() is its own real
        // equivalent, returning the linked libsqlite3 version (e.g.
        // '3.46.1'), verified live.
        $versionExpr = $this->conn->getDatabasePlatform() instanceof SQLitePlatform ? 'sqlite_version()' : 'VERSION()';

        $v = $this->conn->executeQuery(<<<SQL
            SELECT {$versionExpr}
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
        // sqlite3 has no NOW() -- datetime('now') is its own real
        // equivalent, matching SqlDialectExecutor's own established
        // SQLite branch for the identical "no NOW()" gap.
        $nowExpr = $this->conn->getDatabasePlatform() instanceof SQLitePlatform ? "datetime('now')" : 'NOW()';

        $v = $this->conn->fetchOne(<<<SQL
            SELECT {$nowExpr}
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
     * `CONCAT()` itself is portable (Postgres has had
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
        // {$table} is structural, not caller-controlled -- its one real
        // caller (Admin\AdminUiHelper::getAdminClientCacheKeys()) only
        // ever passes a value drawn from a fixed literal table-name array
        // via array_intersect() against that same array's own keys, never
        // an arbitrary/request-derived table name. Still quoted via the
        // platform's own quoteSingleIdentifier(): `groups` is a reserved
        // word on MySQL (added 8.0.2+) -- a bare, unquoted `FROM groups`
        // is a real syntax error there, once the table
        // stopped being always-prefixed and could collide with a keyword.
        $quotedTable = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier($table);
        // COALESCE, because an empty table makes MAX(lastmodified) NULL and
        // CONCAT() returns NULL if any argument is NULL on both engines --
        // so a fresh install fingerprinted as '' rather than as a
        // fingerprint, and every empty table shared that one value. `0_0`
        // is well-formed and still changes the moment a first row lands.
        // CONCAT() itself also covers sqlite3 -- a real, built-in scalar
        // function there since SQLite 3.44.0 (2023), verified live, no
        // branch needed for it specifically.
        $platform = $this->conn->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform) {
            $epochExpr = 'COALESCE(EXTRACT(EPOCH FROM MAX(lastmodified))::bigint, 0)';
        } elseif ($platform instanceof SQLitePlatform) {
            // sqlite3 has no UNIX_TIMESTAMP() -- CAST(strftime('%s', ...)
            // AS INTEGER) is its own real equivalent, matching
            // SqlDialect::dateToTs()'s own established SQLite branch for
            // the identical gap.
            $epochExpr = "COALESCE(CAST(strftime('%s', MAX(lastmodified)) AS INTEGER), 0)";
        } else {
            $epochExpr = 'COALESCE(UNIX_TIMESTAMP(MAX(lastmodified)), 0)';
        }

        $value = $this->conn->fetchOne(<<<SQL
            SELECT CONCAT(
                {$epochExpr},
                '_',
                COUNT(*)
              )
            FROM {$quotedTable}
            SQL);

        return is_string($value) ? $value : '';
    }

    /**
     * `InstallWizard::performInstall()` seeds `sites`/`users`
     * with explicit `id` values (webmaster=1, guest=2, matching
     * `install/config.sql`'s own `webmaster_id` reference), which is
     * exactly why those columns are `GENERATED BY DEFAULT AS IDENTITY`
     * and not `GENERATED ALWAYS`. MySQL's
     * `AUTO_INCREMENT` tracks the highest value ever inserted regardless
     * of whether it came from an explicit value or the default, so a
     * later default-value insert never collides -- Postgres's identity
     * sequence does NOT auto-advance for an explicit insert (`users_id_seq`
     * stays at `last_value=1` after explicitly
     * inserting ids 1 and 2), so the next default-generated
     * `pwg.users.add()` call collides head-on with the already-seeded
     * guest row ("duplicate key value violates unique constraint
     * users_pkey"). `pg_get_serial_sequence()` resolves the real
     * sequence name regardless of Doctrine's own naming convention,
     * rather than guessing
     * `{$table}_id_seq` by string convention. No-op on MySQL --
     * `AUTO_INCREMENT` never needs this.
     */
    public function resyncIdentitySequence(string $table): void
    {
        if (! $this->conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            return;
        }

        // Quoted via the platform, matching getTableFingerprint() above --
        // both callers pass a hardcoded literal today, but interpolating
        // $table unquoted here while binding the identical value as a
        // parameter to pg_get_serial_sequence() below was an inconsistency,
        // not a deliberate distinction.
        $quotedTable = $this->conn->getDatabasePlatform()
            ->quoteSingleIdentifier($table);

        $this->conn->executeStatement(<<<SQL
            SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM {$quotedTable}), 1))
            SQL
            , [$table]);
    }
}
