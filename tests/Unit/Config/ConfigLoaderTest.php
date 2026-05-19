<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\Filesystem;

final class ConfigLoaderTest extends TestCase
{
    private string $tmpDir = '';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    private mixed $headerBackup = null;

    private const array TOUCHED_VARS = ['PIWIGO_DB_HOST', 'PIWIGO_DB_USER', 'PIWIGO_DB_PASSWORD', 'PIWIGO_DB_BASE'];

    #[\Override]
    protected function setUp(): void
    {
        // Under paratest, bootstrap rewrites the header to "test-w<N>"
        // so ConfigLoader::loadEnv() would default to .env.test-w<N>.
        // Pin the header to plain "test" so the assertions about .env.test
        // hold, then restore in tearDown.
        $this->headerBackup = $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null;
        $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';

        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piwigo-config-loader-test-' . uniqid();
        mkdir($this->tmpDir, 0o755, true);

        // Snapshot pre-test env state so tearDown can restore it. The bootstrap
        // (or a prior suite) may have set PIWIGO_DB_* via .env.test, and the
        // integration suite expects them populated; we must not leak our
        // test-only mutations across suite boundaries.
        $this->envBackup = [];
        foreach (self::TOUCHED_VARS as $k) {
            $this->envBackup[$k] = getenv($k);
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['.env', '.env.test'] as $f) {
            if (is_file($this->tmpDir . DIRECTORY_SEPARATOR . $f)) {
                unlink($this->tmpDir . DIRECTORY_SEPARATOR . $f);
            }
        }
        Filesystem::tryRmdir($this->tmpDir);

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

        if ($this->headerBackup === null) {
            unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        } else {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $this->headerBackup;
        }
    }

    public function test_loadEnv_is_a_noop_when_no_env_files_present(): void
    {
        ConfigLoader::loadEnv($this->tmpDir);
        self::assertFalse(getenv('PIWIGO_DB_HOST'));
    }

    public function test_loadEnv_reads_dotenv_test_in_test_mode(): void
    {
        // tests/bootstrap.php sets HTTP_X_PIWIGO_ENV=test → default reads .env.test.
        file_put_contents($this->tmpDir . '/.env.test', "PIWIGO_DB_HOST=db.example.com\n");
        ConfigLoader::loadEnv($this->tmpDir);
        self::assertSame('db.example.com', getenv('PIWIGO_DB_HOST'));
    }

    public function test_loadEnv_default_picks_dotenv_test_in_test_mode(): void
    {
        // tests/bootstrap.php sets HTTP_X_PIWIGO_ENV=test, so the default
        // file argument resolves to .env.test (not .env). The two files
        // are independent — .env is never read in test mode.
        file_put_contents($this->tmpDir . '/.env', "PIWIGO_DB_USER=prod\n");
        file_put_contents($this->tmpDir . '/.env.test', "PIWIGO_DB_USER=testing\n");
        ConfigLoader::loadEnv($this->tmpDir);
        self::assertSame('testing', getenv('PIWIGO_DB_USER'));
    }

    public function test_loadEnv_explicit_files_override_default(): void
    {
        // Callers that need to test a specific file list pass it explicitly.
        file_put_contents($this->tmpDir . '/.env', "PIWIGO_DB_USER=prod\n");
        file_put_contents($this->tmpDir . '/.env.test', "PIWIGO_DB_USER=testing\n");
        ConfigLoader::loadEnv($this->tmpDir, ['.env']);
        self::assertSame('prod', getenv('PIWIGO_DB_USER'));
    }

    public function test_applyEnvOverrides_writes_set_env_vars_into_conf(): void
    {
        putenv('PIWIGO_DB_HOST=mysql.local');
        putenv('PIWIGO_DB_USER=piwigouser');

        Config::reset();
        Config::override('db_host', 'old.localhost');
        Config::override('db_user', 'olduser');
        Config::override('db_password', 'keepme');
        ConfigLoader::applyEnvOverrides();

        self::assertSame('mysql.local', Config::dbHost());
        self::assertSame('piwigouser', Config::dbUser());
        self::assertSame('keepme', Config::dbPassword()); // untouched, env var unset
    }

    public function test_applyEnvOverrides_skips_empty_env_vars(): void
    {
        putenv('PIWIGO_DB_PASSWORD=');

        Config::reset();
        Config::override('db_password', 'existing');
        ConfigLoader::applyEnvOverrides();

        self::assertSame('existing', Config::dbPassword());
    }
}
