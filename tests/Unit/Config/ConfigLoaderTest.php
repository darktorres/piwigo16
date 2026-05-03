<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\ConfigLoader;

final class ConfigLoaderTest extends TestCase
{
    private string $tmpDir = '';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    private const TOUCHED_VARS = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE'];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piwigo-config-loader-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);

        // Snapshot pre-test env state so tearDown can restore it. The bootstrap
        // (or a prior suite) may have set PIWIGO_DB_* via .env.local, and the
        // integration suite expects them populated; we must not leak our
        // test-only mutations across suite boundaries.
        $this->envBackup = [];
        foreach (self::TOUCHED_VARS as $k) {
            $this->envBackup[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach (['.env', '.env.local'] as $f) {
            if (is_file($this->tmpDir . DIRECTORY_SEPARATOR . $f)) {
                unlink($this->tmpDir . DIRECTORY_SEPARATOR . $f);
            }
        }
        @rmdir($this->tmpDir);

        // Restore env vars to their pre-test values.
        foreach (self::TOUCHED_VARS as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
            $original = $this->envBackup[$k] ?? false;
            if ($original !== false) {
                putenv("$k=$original");
                $_ENV[$k]    = $original;
                $_SERVER[$k] = $original;
            }
        }
    }

    public function test_loadEnv_is_a_noop_when_no_env_files_present(): void
    {
        ConfigLoader::loadEnv($this->tmpDir);
        self::assertFalse(getenv('PIWIGO_DB_HOST'));
    }

    public function test_loadEnv_reads_dotenv(): void
    {
        file_put_contents($this->tmpDir . '/.env', "PIWIGO_DB_HOST=db.example.com\n");
        ConfigLoader::loadEnv($this->tmpDir);
        self::assertSame('db.example.com', getenv('PIWIGO_DB_HOST'));
    }

    public function test_loadEnv_default_does_not_load_dotenv_local(): void
    {
        // Runtime default: only .env is loaded. .env.local is reserved for
        // the test runner and must NOT bleed into a real web request.
        file_put_contents($this->tmpDir . '/.env', "PIWIGO_DB_USER=base\n");
        file_put_contents($this->tmpDir . '/.env.local', "PIWIGO_DB_USER=overridden\n");
        ConfigLoader::loadEnv($this->tmpDir);
        self::assertSame('base', getenv('PIWIGO_DB_USER'));
    }

    public function test_loadEnv_explicit_local_overrides_dotenv(): void
    {
        // Test bootstrap passes ['.env', '.env.local'] explicitly to opt in.
        file_put_contents($this->tmpDir . '/.env', "PIWIGO_DB_USER=base\n");
        file_put_contents($this->tmpDir . '/.env.local', "PIWIGO_DB_USER=overridden\n");
        ConfigLoader::loadEnv($this->tmpDir, ['.env', '.env.local']);
        self::assertSame('overridden', getenv('PIWIGO_DB_USER'));
    }

    public function test_applyEnvOverrides_writes_set_env_vars_into_conf(): void
    {
        putenv('PIWIGO_DB_HOST=mysql.local');
        putenv('PIWIGO_DB_USER=piwigouser');

        \Piwigo\Config\Config::reset();
        \Piwigo\Config\Config::override('db_host', 'old.localhost');
        \Piwigo\Config\Config::override('db_user', 'olduser');
        \Piwigo\Config\Config::override('db_password', 'keepme');
        ConfigLoader::applyEnvOverrides();

        self::assertSame('mysql.local', \Piwigo\Config\Config::dbHost());
        self::assertSame('piwigouser', \Piwigo\Config\Config::dbUser());
        self::assertSame('keepme', \Piwigo\Config\Config::dbPassword()); // untouched, env var unset
    }

    public function test_applyEnvOverrides_skips_empty_env_vars(): void
    {
        putenv('PIWIGO_DB_PASSWORD=');

        \Piwigo\Config\Config::reset();
        \Piwigo\Config\Config::override('db_password', 'existing');
        ConfigLoader::applyEnvOverrides();

        self::assertSame('existing', \Piwigo\Config\Config::dbPassword());
    }
}
