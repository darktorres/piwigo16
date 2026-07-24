<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Piwigo\Core\Env;
use Piwigo\Core\Paths;

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
 * TablePrefixListener, every DbPatch/VersionUpgrade class doing raw SQL,
 * InstallWizard/UpgradeRunner/UpgradeFeedRunner, ImageDerivativeController)
 * -- re-reading env vars on every one of those would be real, measurable
 * overhead.
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
     * process environment after seed()/migrateFromLegacyFile() changes it.
     */
    public static function reset(): void
    {
        self::$current = null;
    }

    /**
     * Seeds the current process's PIWIGO_DB_* env vars directly (no .env
     * write) so current() reflects them for the rest of this request --
     * install.php's freshly submitted form values and upgrade.php/
     * upgrade_feed.php's database.inc.php-sourced values both need this
     * before InstallBootstrap::activateConfigService() runs, the same
     * "real credentials before anything connects" ordering
     * CurrentConfig::override() used to provide.
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
     * One-time migration for a real, pre-existing Piwigo installation
     * being upgraded whose only copy of its DB credentials lives in the
     * classic local/config/database.inc.php file, not .env --
     * upgrade.php's and upgrade_feed.php's entire purpose is upgrading
     * such sites in place, so this is the one caller-facing exception to
     * "env-only, no file fallback." A site installed by this codebase's
     * own InstallWizard already has .env, so this is a no-op there; also a
     * no-op on a second upgrade run against the same site.
     *
     * Reads the file's side effects in an isolated function scope (same
     * pattern as LegacyDbLayer::value()) -- database.inc.php is a
     * site-local file outside this codebase, PHPStan can't see its effect
     * on $conf/$prefixeTable.
     */
    public static function migrateFromLegacyFile(Paths $paths): void
    {
        if (getenv('PIWIGO_DB_HOST') !== false) {
            return;
        }

        $legacy = (static function () use ($paths): array {
            $conf = [];
            $prefixeTable = null;
            @include $paths->siteLocal . 'config/database.inc.php';

            return [
                'conf' => $conf,
                'prefixeTable' => $prefixeTable,
            ];
        })();

        $values = self::extractLegacyValues($legacy['conf'], $legacy['prefixeTable']);
        if ($values === []) {
            return;
        }

        self::seed($values);
        Env::mergeIntoEnvFile($paths->root . '/' . Env::testModeEnvFile(), $values);
    }

    /**
     * Split out from migrateFromLegacyFile() so $conf's declared parameter
     * type (a plain array, not the empty-literal shape PHPStan infers for
     * a local `$conf = [];` it can't see database.inc.php's raw `include`
     * mutate) is what this method's body gets analyzed against.
     *
     * @param array<string, mixed> $conf
     * @return array<string, string>
     */
    private static function extractLegacyValues(array $conf, mixed $prefixeTable): array
    {
        $values = [
            'PIWIGO_DB_HOST' => $conf['db_host'] ?? null,
            'PIWIGO_DB_USER' => $conf['db_user'] ?? null,
            'PIWIGO_DB_PASSWORD' => $conf['db_password'] ?? null,
            'PIWIGO_DB_BASE' => $conf['db_base'] ?? null,
            'PIWIGO_DB_PREFIX' => $prefixeTable,
        ];

        return array_filter($values, static fn (mixed $v): bool => is_string($v) && $v !== '');
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
