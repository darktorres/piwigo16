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
 * Singleton/service-locator elimination campaign, Phase 3: this class used
 * to be a genuine self-managed static memo (its own `private static ?self
 * $current`, written by `reset()`/`seed()`, read by `current()`). It is now
 * a plain, mutable, container-shared instance (`config/container.php`'s own
 * `factory(fn () => DbCredentials::fromEnv())` binding) -- properties are
 * NOT `readonly`, unlike most value objects in this codebase, specifically
 * so `reload()`/`seed()` can mutate the *same* shared instance every
 * already-injected consumer holds, rather than replacing it: a genuine,
 * previously-live production bug (see InstallBootstrap's own docblock)
 * showed that anything resolving DB credentials before InstallWizard's own
 * mid-request `seed()` call must still see the freshly-submitted values
 * afterward, not a stale copy captured at construction time.
 *
 * `fromEnv()` (bypassing the container entirely) is the direct answer for
 * the campaign's own documented "raw entry-shell root file, runs before
 * Kernel::boot()" exception category -- `public/install.php` resolves its
 * one real credentials read via `InstallBootstrap::dbCredentials()`'s own
 * direct container access instead (no object graph exists yet for install.php
 * itself to receive this via constructor injection through, but
 * InstallBootstrap::boot() has already run by that point); `public/ready.php`
 * called `fromEnv()` directly as of sub-phase 12F-3, closing the former
 * `@deprecated current()` transitional bridge outright (env vars are
 * always meaningfully available regardless of DI wiring, and the vast
 * majority of this codebase's own Unit tests construct a `Connection`/
 * read a `Tables::*()` name without ever calling `Kernel::boot()` at all,
 * exactly the "one-shot process, a fresh read doesn't matter" reasoning
 * `fromEnv()` itself already existed for). `Tables`/`DbConnection` closed
 * their own former `current()` shim usage earlier, in Phase 11
 * sub-phase 11I (their own private `dbCredentials()` container-resolve
 * helpers).
 *
 * toMysqlArgs() mirrors tools/restore-drill.sh's own mysql_args
 * construction exactly, so backup/restore commands shell out to the
 * mysql/mysqldump client the same proven way the existing dev tooling
 * already does.
 */
final class DbCredentials
{
    public function __construct(
        public string $host,
        public string $user,
        public string $password,
        public string $database,
        public string $prefix,
        public ?int $port = null,
        public string $driver = 'mysqli',
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

    /**
     * Re-derives every property from the current process environment,
     * mutating this same instance in place -- every other consumer holding
     * this same container-shared instance sees the update immediately, no
     * re-resolution needed.
     */
    public function reload(): void
    {
        $fresh = self::fromEnv();
        $this->host = $fresh->host;
        $this->user = $fresh->user;
        $this->password = $fresh->password;
        $this->database = $fresh->database;
        $this->prefix = $fresh->prefix;
        $this->port = $fresh->port;
        $this->driver = $fresh->driver;
    }

    /**
     * Seeds the current process's PIWIGO_DB_* env vars directly (no .env
     * write) then reload()s this instance so every consumer holding it
     * reflects them for the rest of this request -- install.php's freshly
     * submitted form values need this before
     * InstallBootstrap::activateConfigService() runs, the same "real
     * credentials before anything connects" ordering CurrentConfig::override()
     * used to provide.
     *
     * @param array<string, string|null> $values keyed by PIWIGO_DB_* env var name
     */
    public function seed(array $values): void
    {
        foreach ($values as $envKey => $value) {
            if ($value !== null) {
                putenv($envKey . '=' . $value);
            }
        }
        $this->reload();
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

    /**
     * pgsql support pass: mirrors toMysqlArgs() for the psql/pg_dump/
     * pg_restore client family -- real, deliberate flag differences, not
     * an oversight: psql's own `-p` is the PORT flag (mysql's `-P`), and
     * psql has no password CLI flag at all (`PGPASSWORD` env var or
     * `~/.pgpass` only) -- the caller is responsible for setting
     * `PGPASSWORD` in the child process's own env when {@see $password}
     * is non-empty, same convention every real psql-shelling call site in
     * this codebase already uses (IntegrationTestCase::loadFixtureViaPsql(),
     * RegenerateFixtureTest's own pg_dump call).
     *
     * @return list<string>
     */
    public function toPsqlArgs(): array
    {
        $args = ['-h' . $this->host, '-U' . $this->user];
        if ($this->port !== null) {
            $args[] = '-p' . $this->port;
        }

        return $args;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value !== false && $value !== '' ? $value : $default;
    }
}
