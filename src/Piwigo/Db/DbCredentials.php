<?php

declare(strict_types=1);

namespace Piwigo\Db;

/**
 * Reads the same PIWIGO_DB_* env vars include/env.inc.php's
 * pwg_apply_env_to_conf() maps into the legacy $conf array, for CLI code
 * (P12's bin/piwigo commands) that needs a DB connection without pulling
 * in the full include/common.inc.php legacy bootstrap chain -- untested
 * under CLI SAPI, see docs/PLAN-REPLAY.md P12's scope-decision section.
 *
 * toMysqlArgs() mirrors tools/restore-drill.sh's own mysql_args
 * construction exactly, so backup/restore commands shell out to the
 * mysql/mysqldump client the same proven way the existing dev tooling
 * already does.
 */
final readonly class DbCredentials
{
    public function __construct(
        public string $host,
        public string $user,
        public string $password,
        public string $database,
        public string $prefix,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            host: self::env('PIWIGO_DB_HOST', 'localhost'),
            user: self::env('PIWIGO_DB_USER', 'root'),
            password: self::env('PIWIGO_DB_PASSWORD', ''),
            database: self::env('PIWIGO_DB_BASE', 'piwigo'),
            prefix: self::env('PIWIGO_DB_PREFIX', 'piwigo_'),
        );
    }

    /**
     * @return list<string>
     */
    public function toMysqlArgs(): array
    {
        $args = ['-h' . $this->host, '-u' . $this->user];
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
