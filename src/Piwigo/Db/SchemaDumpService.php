<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
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
 * Provider label collapses to just 'mysql'/'pgsql' (not a 3rd 'mariadb'
 * variant the recovered prior attempt produced) -- matching this
 * codebase's own established platform-branching granularity everywhere
 * else (RandFunction/GroupConcatFunction/every migration file's own
 * `instanceof AbstractMySQLPlatform` branch), which treats MySQL and
 * MariaDB as one portability tier throughout, never a 3-way split.
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
        $credentials = DbCredentials::fromEnv();

        $raw = $label === 'pgsql'
            ? $this->runPgDump($credentials)
            : $this->runMysqldump($credentials);

        $normalized = $this->normalize($raw, $label);

        $outputPath = dirname(__DIR__, 3) . '/install/piwigo_structure-' . $label . '.sql';
        file_put_contents($outputPath, $normalized);

        return new SchemaDumpResult($label, $outputPath);
    }

    private function detectLabel(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof AbstractMySQLPlatform) {
            return 'mysql';
        }
        if ($platform instanceof PostgreSQLPlatform) {
            return 'pgsql';
        }

        throw new RuntimeException('schema:dump only supports MySQL/MariaDB and PostgreSQL connections, got ' . $platform::class);
    }

    /**
     * Doctrine's own migration-execution ledger -- real and necessary for
     * `migrations:migrate`, but out of place in a schema snapshot that
     * doesn't go through the migration runner at all. Prefixed, matching
     * MigrationDependencyFactory's own `table_storage.table_name` value
     * (every other table in this schema carries PIWIGO_DB_PREFIX too) --
     * unlike the recovered prior attempt's own hardcoded, unprefixed
     * `doctrine_migration_versions` constant, which predates that prefix
     * fix.
     */
    private function ledgerTable(DbCredentials $credentials): string
    {
        return $credentials->prefix . 'migration_versions';
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
            '--ignore-table=' . $credentials->database . '.' . $this->ledgerTable($credentials),
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
            '--exclude-table=' . $this->ledgerTable($credentials),
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
     * Strips version-stamped headers and AUTO_INCREMENT counters so the
     * output is deterministic across runs/hosts -- the same schema
     * always produces a byte-identical file, which is what makes CI's
     * `git diff --exit-code` drift guard meaningful.
     */
    private function normalize(string $dump, string $label): string
    {
        $lines = explode("\n", $dump);
        $lines = array_filter($lines, static function (string $line) use ($label): bool {
            $trimmed = ltrim($line);
            if ($label === 'pgsql') {
                // \restrict/\unrestrict (pg_dump 18+, a psql meta-command
                // pair guarding against executing this file with plain
                // `psql -f` unless the matching random token is echoed
                // back) carry a freshly-random token on every single dump
                // -- confirmed live by dumping twice in a row and diffing.
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

        $header = '-- GENERATED FILE -- do not hand-edit. Regenerate with `bin/piwigo schema:dump`'
            . " after migrating a blank database to the latest version.\n"
            . "-- Source of truth is src/Piwigo/Migrations/ -- this snapshot is a human-\n"
            . "-- reviewable reference and CI drift guard only; InstallWizard no longer\n"
            . "-- reads this file to create a schema.\n\n";

        return $header . trim($result) . "\n";
    }
}
