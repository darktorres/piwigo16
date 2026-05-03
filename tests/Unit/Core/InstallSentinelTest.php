<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\InstallSentinel;

final class InstallSentinelTest extends TestCase
{
    private string $stampPath;

    protected function setUp(): void
    {
        if (!defined('PHPWG_ROOT_PATH')) {
            define('PHPWG_ROOT_PATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piwigo-install-sentinel-test' . DIRECTORY_SEPARATOR);
        }
        $this->stampPath = PHPWG_ROOT_PATH . 'local/.installed';
        @mkdir(dirname($this->stampPath), 0o755, true);
        InstallSentinel::markUninstalled();
    }

    protected function tearDown(): void
    {
        InstallSentinel::markUninstalled();
    }

    public function test_isInstalled_false_when_no_signal_present(): void
    {
        // Guard: only meaningful when PHPWG_INSTALLED is not defined in this process.
        if (defined('PHPWG_INSTALLED')) {
            self::markTestSkipped('PHPWG_INSTALLED is defined in this test process');
        }
        self::assertFalse(InstallSentinel::isInstalled());
    }

    public function test_markInstalled_creates_stamp_and_isInstalled_true(): void
    {
        InstallSentinel::markInstalled();
        self::assertFileExists($this->stampPath);
        self::assertTrue(InstallSentinel::isInstalled());
    }

    public function test_markUninstalled_removes_stamp(): void
    {
        InstallSentinel::markInstalled();
        InstallSentinel::markUninstalled();
        self::assertFileDoesNotExist($this->stampPath);
    }
}
