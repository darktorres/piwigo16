<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\ConfigLoader;

final class ConfigLoaderTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piwigo-config-loader-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);

        // Scrub env vars BEFORE each test. Necessary because (a) the test
        // runner may have loaded its own .env / .env.local at bootstrap, and
        // (b) phpdotenv's createImmutable mode refuses to overwrite already-
        // set vars, so leftover state from a prior test breaks the next one.
        foreach (['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE'] as $k) {
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

    public function test_loadEnv_local_overrides_dotenv(): void
    {
        file_put_contents($this->tmpDir . '/.env', "PIWIGO_DB_USER=base\n");
        file_put_contents($this->tmpDir . '/.env.local', "PIWIGO_DB_USER=overridden\n");
        ConfigLoader::loadEnv($this->tmpDir);
        self::assertSame('overridden', getenv('PIWIGO_DB_USER'));
    }

    public function test_applyEnvOverrides_writes_set_env_vars_into_conf(): void
    {
        putenv('PIWIGO_DB_HOST=mysql.local');
        putenv('PIWIGO_DB_USER=piwigouser');

        $conf = ['db_host' => 'old.localhost', 'db_user' => 'olduser', 'db_password' => 'keepme'];
        ConfigLoader::applyEnvOverrides($conf);

        self::assertSame('mysql.local', $conf['db_host']);
        self::assertSame('piwigouser', $conf['db_user']);
        self::assertSame('keepme', $conf['db_password']); // untouched, env var unset
    }

    public function test_applyEnvOverrides_skips_empty_env_vars(): void
    {
        putenv('PIWIGO_DB_PASSWORD=');

        $conf = ['db_password' => 'existing'];
        ConfigLoader::applyEnvOverrides($conf);

        self::assertSame('existing', $conf['db_password']);
    }
}
