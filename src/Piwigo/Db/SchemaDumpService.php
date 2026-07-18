<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Process\Process;

/**
 * Regenerates `install/schema/{mysql,mariadb,pgsql}.sql`, generated from
 * Doctrine Migrations (the real source of truth for the schema), never
 * hand-edited. NOT yet the live install path -- `install.php`
 * (unreplaced legacy installer, no InstallController exists yet) still
 * hard-references `install/piwigo_structure-mysql.sql` via its own
 * execute_sqlfile(), whose naive line-based parser and hardcoded
 * `DEFAULT CHARACTER SET utf8` CREATE-TABLE rewrite can't correctly
 * consume this migrations-generated schema (multi-line DDL, FKs, real
 * utf8mb4). That's real "install flow rework" scope, deliberately not
 * folded into P15 (matching the standing project decision that the
 * install/init flow gets its own later, larger rework). These files
 * exist now as CI drift-guard proof that the migrations are internally
 * consistent across all 3 providers, and as the future InstallController's
 * eventual input once that rework lands.
 *
 * Boots against whichever database the current connection
 * (Piwigo\Db\DbConnection, i.e. whatever `bin/piwigo migrations:migrate`
 * was just run against) points to -- there is no separate
 * multi-connection-string mechanism here; the operator runs
 * `schema:dump` once per provider, pointing PIWIGO_DB_* at that
 * provider's throwaway database each time, the same way
 * `migrations:migrate` already works.
 *
 * Provider label is auto-detected from `Connection::getDatabasePlatform()`
 * (MariaDBPlatform checked before the broader AbstractMySQLPlatform,
 * since MariaDBPlatform extends it) rather than a manual `--provider`
 * flag -- verified empirically that DBAL correctly distinguishes a real
 * MySQL 9.7 container from a real MariaDB 12.x container via the
 * connection handshake alone, no hinting needed.
 *
 * Shells out to mysqldump/pg_dump via the same Symfony\Process pattern
 * BackupService (P12) already uses -- schema-only, no data
 * (`--no-data` / `--schema-only`).
 */
final readonly class SchemaDumpService
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @return array{label: string, path: string}
     */
    public function dump(): array
    {
        $label = $this->detectLabel();
        $credentials = DbCredentials::fromEnv();

        $raw = $label === 'pgsql'
            ? $this->runPgDump($credentials)
            : $this->runMysqldump($credentials);

        $normalized = $this->normalize($raw, $label);

        $outputDir = dirname(__DIR__, 3) . '/install/schema';
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0o775, true);
        }
        $outputPath = $outputDir . '/' . $label . '.sql';
        file_put_contents($outputPath, $normalized);

        return [
            'label' => $label,
            'path' => $outputPath,
        ];
    }

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

        throw new \RuntimeException('schema:dump only supports MySQL, MariaDB, and PostgreSQL connections, got ' . $platform::class);
    }

    /**
     * Doctrine's own migration-execution ledger -- real and necessary for
     * `migrations:migrate`, but out of place in a fast-install artifact
     * that doesn't go through the migration runner at all.
     */
    private const string MIGRATIONS_LEDGER_TABLE = 'doctrine_migration_versions';

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
            // which doesn't have that table. Verified empirically
            // against a real MariaDB 12.x container.
            '--column-statistics=0',
            '--ignore-table=' . $credentials->database . '.' . self::MIGRATIONS_LEDGER_TABLE,
            $credentials->database,
        ]);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('mysqldump failed: ' . $process->getErrorOutput());
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
            '--exclude-table=' . self::MIGRATIONS_LEDGER_TABLE,
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
            throw new \RuntimeException('pg_dump failed: ' . $process->getErrorOutput());
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
                return ! str_starts_with($trimmed, '-- Dumped from database version')
                    && ! str_starts_with($trimmed, '-- Dumped by pg_dump version');
            }

            return ! str_starts_with($trimmed, '-- MySQL dump')
                && ! str_starts_with($trimmed, '-- Host:')
                && ! str_starts_with($trimmed, '-- Server version')
                && ! str_starts_with($trimmed, '-- Dump completed on');
        });

        $result = implode("\n", $lines);
        $result = preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $result) ?? $result;
        $result = preg_replace('/\n{3,}/', "\n\n", $result) ?? $result;

        return trim($result) . "\n";
    }
}
