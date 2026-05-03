<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\TestMode;

final class TestModeTest extends TestCase
{
    /** @var array{header: mixed, remote: mixed} */
    private array $serverBackup;

    protected function setUp(): void
    {
        // tests/bootstrap.php sets HTTP_X_PIWIGO_ENV=test for the whole
        // process. Snapshot it so each test can install its own value
        // and restore in tearDown.
        $this->serverBackup = [
            'header' => $_SERVER['HTTP_X_PIWIGO_ENV'] ?? null,
            'remote' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
    }

    protected function tearDown(): void
    {
        if ($this->serverBackup['header'] === null) {
            unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        } else {
            $_SERVER['HTTP_X_PIWIGO_ENV'] = $this->serverBackup['header'];
        }
        if ($this->serverBackup['remote'] === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->serverBackup['remote'];
        }
    }

    public function test_isActive_true_when_header_set_under_cli(): void
    {
        // We're running under PHPUnit CLI; the header is enough.
        $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';
        unset($_SERVER['REMOTE_ADDR']);
        self::assertTrue(TestMode::isActive());
    }

    public function test_isActive_false_when_header_absent(): void
    {
        unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        self::assertFalse(TestMode::isActive());
    }

    public function test_isActive_false_when_header_value_wrong(): void
    {
        $_SERVER['HTTP_X_PIWIGO_ENV'] = 'production';
        self::assertFalse(TestMode::isActive());
    }

    public function test_envFile_reflects_active_state(): void
    {
        $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';
        self::assertSame('.env.test', TestMode::envFile());

        unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        self::assertSame('.env', TestMode::envFile());
    }

    public function test_installedStamp_reflects_active_state(): void
    {
        $_SERVER['HTTP_X_PIWIGO_ENV'] = 'test';
        self::assertSame('.installed.test', TestMode::installedStamp());

        unset($_SERVER['HTTP_X_PIWIGO_ENV']);
        self::assertSame('.installed', TestMode::installedStamp());
    }
}
