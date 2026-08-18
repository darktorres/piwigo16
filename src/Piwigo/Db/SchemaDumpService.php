<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Piwigo\Db\Projection\SchemaDumpResult;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Regenerates `install/piwigo_structure-{mysql,pgsql}.sql` from Doctrine
 * Migrations (the real source of truth for the schema now that
 * InstallWizard::performInstall() drives schema creation through the
 * Migrator directly -- see MigrationDependencyFactory's own docblock),
 * never hand-edited. No longer load-bearing for a fresh install the way
 * the recovered prior attempt's own version was written to be (that
 * version predates the current InstallWizard wiring, and its own docblock
 * says as much) -- this class is now purely a generated, human-reviewable
 * schema snapshot plus a CI drift guard (`schema:dump` + `git diff
 * --exit-code`, the same role Rails' `db/schema.rb` plays alongside its
 * own real migration mechanism): proof that the migrations are internally
 * consistent across both providers, and a readable reference for anyone
 * auditing the schema without reading every migration file.
 *
 * Boots against whichever database the current connection
 * (Piwigo\Db\DbConnection, i.e. whatever `bin/piwigo migrations:migrate`
 * was just run against) points to -- there is no separate
 * multi-connection-string mechanism here; the operator runs
 * `schema:dump` once per provider, pointing PIWIGO_DB_* at that
 * provider's throwaway (already-migrated) database each time, the same
 * way `migrations:migrate` already works.
 *
 * Four provider labels: 'mysql', 'mariadb', 'pgsql', 'sqlite'. SQLite's
 * own branch shells out to nothing at all -- `sqlite_master`'s own `sql`
 * column already stores the exact `CREATE` statement text for every real
 * object verbatim, no external `sqlite3` CLI dependency needed the way
 * mysqldump/pg_dump are for the other 3 (see {@see runSqliteDump()}).
 *
 * This deliberately does NOT follow the one-tier MySQL/MariaDB granularity
 * used elsewhere (RandFunction/GroupConcatFunction/every migration's own
 * `instanceof AbstractMySQLPlatform` branch). That tiering is right for
 * *queries*, which really are portable across both. It is wrong for
 * *schema representation*, which is what this file records: dumping the
 * same migrated schema from MariaDB 12 differs from MySQL 8.4 in 189
 * lines, and not cosmetically --
 *
 *   int unsigned        -> int(11) unsigned      (display widths 8.4 dropped)
 *   CURRENT_TIMESTAMP   -> current_timestamp()
 *   COLLATE utf8mb4_... -> omitted
 *   json                -> longtext ... CHECK (json_valid(...))
 *
 * -- the last because MariaDB has no native JSON type, so those columns
 * genuinely are a different type there. Sharing one file made the CI
 * drift guard unsatisfiable on the mariadb leg, which is why that leg had
 * the guard skipped until this split existed.
 *
 * MySQL's own `mysqldump` client is used for both, verified against a real
 * mariadb:12 server -- `--column-statistics=0` (already present) is what
 * makes that work, and MariaDB's own `mariadb-dump` is deliberately not
 * required, since it is absent from the MySQL client package and from CI
 * runners.
 *
 * Shells out to mysqldump/pg_dump via the same Symfony\Process pattern
 * BackupService already uses -- schema-only, no data
 * (`--no-data` / `--schema-only`).
 */
final readonly class SchemaDumpService
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function dump(): SchemaDumpResult
    {
        $label = $this->detectLabel();

        if ($label === 'sqlite') {
            $raw = $this->runSqliteDump();
        } else {
            $credentials = DbCredentials::fromEnv();
            $raw = $label === 'pgsql'
                ? $this->runPgDump($credentials)
                : $this->runMysqldump($credentials);
        }

        $normalized = $this->normalize($raw, $label);

        $outputPath = dirname(__DIR__, 3) . '/install/piwigo_structure-' . $label . '.sql';
        file_put_contents($outputPath, $normalized);

        return new SchemaDumpResult($label, $outputPath);
    }

    /**
     * MariaDB is checked first: `MariaDBPlatform` extends
     * `AbstractMySQLPlatform`, so the MySQL arm would otherwise swallow it
     * and every MariaDB dump would be written over MySQL's file.
     */
    private function detectLabel(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof MariaDBPlatform) {
            return 'mariadb';
        }
        if ($platform instanceof AbstractMySQLPlatform) {
            return 'mysql';
        }
        if ($platform instanceof PostgreSQLPlatform) {
            return 'pgsql';
        }
        if ($platform instanceof SQLitePlatform) {
            return 'sqlite';
        }

        throw new RuntimeException('schema:dump only supports MySQL, MariaDB, PostgreSQL and SQLite connections, got ' . $platform::class);
    }

    /**
     * Doctrine's own migration-execution ledger -- real and necessary for
     * `migrations:migrate`, but out of place in a schema snapshot that
     * doesn't go through the migration runner at all. Matches
     * MigrationDependencyFactory's own `table_storage.table_name` value.
     */
    private function ledgerTable(): string
    {
        return 'migration_versions';
    }

    private function runMysqldump(DbCredentials $credentials): string
    {
        $process = new Process([
            'mysqldump',
            ...$credentials->toMysqlArgs(),
            '--no-data',
            '--skip-comments',
            '--skip-add-drop-table',
            // GTID_PURGED embeds this specific server's real GTID set
            // (a UUID unique per instance) -- real, host-specific,
            // non-deterministic content that would break the CI
            // drift-guard's `git diff --exit-code` on every run.
            '--set-gtid-purged=OFF',
            // A MySQL mysqldump client assumes histogram-stats support
            // (information_schema.COLUMN_STATISTICS) exists purely from
            // its own version, regardless of which server it's actually
            // talking to -- errors out against a real MariaDB server,
            // which doesn't have that table.
            '--column-statistics=0',
            '--ignore-table=' . $credentials->database . '.' . $this->ledgerTable(),
            $credentials->database,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysqldump failed: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    private function runPgDump(DbCredentials $credentials): string
    {
        $env = [
            'PGPASSWORD' => $credentials->password,
        ];
        $args = [
            'pg_dump', '-h', $credentials->host, '-U', $credentials->user,
            '--schema-only', '--no-owner', '--no-privileges',
            '--exclude-table=' . $this->ledgerTable(),
        ];
        if ($credentials->port !== null) {
            $args[] = '-p';
            $args[] = (string) $credentials->port;
        }
        $args[] = $credentials->database;

        $process = new Process($args, env: $env);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump failed: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    /**
     * `sqlite_master`'s own `sql` column already holds the exact `CREATE`
     * statement text for every real object, verbatim, in creation order
     * (its own natural `rowid` order) -- already deterministic with no
     * host/version/timestamp noise a CLI dump tool would introduce, so
     * {@see normalize()} does no stripping for this label at all.
     *
     * Two real exclusions, neither a `sql IS NOT NULL`-alone filter
     * catches on its own:
     *  - `sql IS NULL` rows are SQLite's own auto-created indexes for a
     *    `PRIMARY KEY`/`UNIQUE` column constraint (`sqlite_autoindex_*`)
     *    -- never written by an explicit `CREATE INDEX`, and already
     *    fully implied by the owning table's own `CREATE TABLE` text, so
     *    including them would be redundant, not just noisy.
     *  - FTS5's own shadow tables (`<fts_table>_data`/`_idx`/`_docsize`/
     *    `_config`, confirmed live: exactly 4 per real
     *    `CREATE VIRTUAL TABLE ... USING fts5(...)`) are auto-created BY
     *    that one statement when it runs -- a real, not just redundant,
     *    problem if included verbatim: re-running their own captured
     *    `CREATE TABLE` text standalone would fail with "table already
     *    exists" once the owning virtual table's own statement already
     *    created them.
     */
    private function runSqliteDump(): string
    {
        // sqlite_master only exists on a real sqlite3 connection --
        // staabm/phpstan-dba validates this against whichever one
        // connection tests/phpstan-dba-bootstrap.php currently has
        // (mysqli or pgsql, following .env.test's own PIWIGO_DB_DRIVER),
        // never sqlite3 -- neither real target has a sqlite_master
        // table, same established convention as every other
        // deliberately-cross-platform raw query in this codebase (e.g.
        // UniqueExecLock::isRunningPostgres()'s own pg_locks query).
        // @phpstan-ignore dba.syntaxError
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, name, sql FROM sqlite_master WHERE sql IS NOT NULL AND name != ? ORDER BY rowid',
            [$this->ledgerTable()]
        );

        $fts5TableNames = [];
        foreach ($rows as $row) {
            if (is_string($row['sql']) && is_string($row['name']) && str_starts_with($row['sql'], 'CREATE VIRTUAL TABLE')) {
                $fts5TableNames[] = $row['name'];
            }
        }

        $shadowSuffixes = ['_data', '_idx', '_docsize', '_config'];
        $statements = [];
        foreach ($rows as $row) {
            if (! is_string($row['name']) || ! is_string($row['sql'])) {
                continue;
            }

            $isFts5Shadow = false;
            foreach ($fts5TableNames as $fts5TableName) {
                foreach ($shadowSuffixes as $suffix) {
                    if ($row['name'] === $fts5TableName . $suffix) {
                        $isFts5Shadow = true;

                        break 2;
                    }
                }
            }

            if ($isFts5Shadow) {
                continue;
            }

            $statements[] = rtrim($row['sql']) . ';';
        }

        return implode("\n\n", $statements) . "\n";
    }

    /**
     * Strips version-stamped headers and AUTO_INCREMENT counters so the
     * output is deterministic across runs/hosts -- the same schema
     * always produces a byte-identical file, which is what makes CI's
     * `git diff --exit-code` drift guard meaningful.
     */
    private function normalize(string $dump, string $label): string
    {
        // sqlite_master's own CREATE text (see runSqliteDump()'s own
        // docblock) already carries none of the version/host/timestamp
        // noise the stripping below exists to remove -- no filtering
        // needed for this label, only the shared header/footer wrap.
        if ($label === 'sqlite') {
            return self::wrapWithHeader($dump);
        }

        $lines = explode("\n", $dump);
        $lines = array_filter($lines, static function (string $line) use ($label): bool {
            $trimmed = ltrim($line);
            if ($label === 'pgsql') {
                // \restrict/\unrestrict (pg_dump 18+, a psql meta-command
                // pair guarding against executing this file with plain
                // `psql -f` unless the matching random token is echoed
                // back) carry a freshly-random token on every single dump.
                // Not schema content at all, and the single biggest
                // non-determinism source in a schema dump.
                return ! str_starts_with($trimmed, '-- Dumped from database version')
                    && ! str_starts_with($trimmed, '-- Dumped by pg_dump version')
                    && ! str_starts_with($trimmed, '\\restrict ')
                    && ! str_starts_with($trimmed, '\\unrestrict ');
            }

            return ! str_starts_with($trimmed, '-- MySQL dump')
                && ! str_starts_with($trimmed, '-- Host:')
                && ! str_starts_with($trimmed, '-- Server version')
                && ! str_starts_with($trimmed, '-- Dump completed on');
        });

        $result = implode("\n", $lines);
        $result = preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $result) ?? $result;
        $result = preg_replace('/\n{3,}/', "\n\n", $result) ?? $result;

        return self::wrapWithHeader($result);
    }

    private static function wrapWithHeader(string $result): string
    {
        $header = '-- GENERATED FILE -- do not hand-edit. Regenerate with `bin/piwigo schema:dump`'
            . " after migrating a blank database to the latest version.\n"
            . "-- Source of truth is src/Piwigo/Migrations/ -- this snapshot is a human-\n"
            . "-- reviewable reference and CI drift guard only; InstallWizard no longer\n"
            . "-- reads this file to create a schema.\n\n";

        return $header . trim($result) . "\n";
    }
}
