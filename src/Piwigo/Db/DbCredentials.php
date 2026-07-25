<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * The 7 PIWIGO_DB_* connection parameters, read from the process
 * environment only. Originally P12-only (CLI commands shelling out to
 * mysql/mysqldump via env-var credentials, bypassing the legacy
 * include/common.inc.php bootstrap chain untested under CLI SAPI) --
 * generalized (Config generic-accessor removal) into the one source of DB
 * credentials for the whole app, replacing CurrentConfig::dbHost()/dbPort()/
 * dbDriver()/dbUser()/dbPassword()/dbName()/dbPrefix(). No relationship to
 * Piwigo\Config\CurrentConfig's properties/attributes/getters at all --
 * these are infrastructure-bootstrapping values needed before a DB
 * connection (and therefore CurrentConfig's own DB-backed load) can exist,
 * not ordinary site settings.
 *
 * current()/reset() are a self-contained request-lifetime memo, added
 * alongside the original fromEnv() rather than replacing it: fromEnv()'s
 * original CLI callers (BackupService/BackupRestoreCommand/UserListCommand)
 * are one-shot processes where a fresh read doesn't matter, but
 * DbConnection::params() alone is now reached from well over 100 real call
 * sites on every HTTP request (Tables.php's ~50 table-name methods,
 * TablePrefixListener, ImageDerivativeController) -- re-reading env vars on
 * every one of those would be real, measurable overhead.
 *
 * toMysqlArgs() mirrors tools/restore-drill.sh's own mysql_args
 * construction exactly, so backup/restore commands shell out to the
 * mysql/mysqldump client the same proven way the existing dev tooling
 * already does.
 */
final class DbCredentials
{
    private static ?self $current = null;

    public function __construct(
        public readonly string $host,
        public readonly string $user,
        public readonly string $password,
        public readonly string $database,
        public readonly string $prefix,
        public readonly ?int $port = null,
        public readonly string $driver = 'mysqli',
    ) {}

    public static function fromEnv(): self
    {
        $portEnv = getenv('PIWIGO_DB_PORT');
        $driverEnv = getenv('PIWIGO_DB_DRIVER');

        return new self(
            host: self::env('PIWIGO_DB_HOST', 'localhost'),
            user: self::env('PIWIGO_DB_USER', ''),
            password: self::env('PIWIGO_DB_PASSWORD', ''),
            database: self::env('PIWIGO_DB_BASE', ''),
            prefix: self::env('PIWIGO_DB_PREFIX', 'piwigo_'),
            port: $portEnv !== false && $portEnv !== '' && is_numeric($portEnv) ? (int) $portEnv : null,
            driver: $driverEnv !== false && $driverEnv !== '' ? $driverEnv : 'mysqli',
        );
    }

    public static function current(): self
    {
        return self::$current ??= self::fromEnv();
    }

    /**
     * Test-only, for test-isolation between requests -- mirrors
     * CurrentConfigService's/CurrentLogger's/CurrentTemplate's own reset()
     * methods. Also forces the next current() call to re-derive from the
     * process environment after seed() changes it.
     */
    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Seeds the current process's PIWIGO_DB_* env vars directly (no .env
     * write) so current() reflects them for the rest of this request --
     * install.php's freshly submitted form values need this before
     * InstallBootstrap::activateConfigService() runs, the same "real
     * credentials before anything connects" ordering CurrentConfig::override()
     * used to provide.
     *
     * @param array<string, string|null> $values keyed by PIWIGO_DB_* env var name
     */
    public static function seed(array $values): void
    {
        foreach ($values as $envKey => $value) {
            if ($value !== null) {
                putenv($envKey . '=' . $value);
            }
        }
        self::reset();
    }

    /**
     * @return list<string>
     */
    public function toMysqlArgs(): array
    {
        $args = ['-h' . $this->host, '-u' . $this->user];
        if ($this->port !== null) {
            $args[] = '-P' . $this->port;
        }
        if ($this->password !== '') {
            $args[] = '-p' . $this->password;
        }

        return $args;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value !== false && $value !== '' ? $value : $default;
    }
}
