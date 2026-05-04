<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Logger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

final class LoggerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/piwigo_logger_test_' . uniqid();
        mkdir($this->logDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->logDir);
    }

    private function makeLogger(int $severity = Logger::DEBUG): Logger
    {
        return new Logger(['directory' => $this->logDir, 'severity' => $severity]);
    }

    public function test_implements_psr3_logger_interface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, $this->makeLogger());
    }

    public function test_debug_writes_to_file(): void
    {
        $logger = $this->makeLogger();
        $logger->debug('hello debug');
        $content = file_get_contents(glob($this->logDir . '/log_*.txt')[0]);
        $this->assertStringContainsString('[DEBUG]', $content);
        $this->assertStringContainsString('hello debug', $content);
    }

    public function test_error_writes_to_file(): void
    {
        $logger = $this->makeLogger();
        $logger->error('something went wrong');
        $content = file_get_contents(glob($this->logDir . '/log_*.txt')[0]);
        $this->assertStringContainsString('[ERROR]', $content);
        $this->assertStringContainsString('something went wrong', $content);
    }

    public function test_context_array_is_appended(): void
    {
        $logger = $this->makeLogger();
        $logger->info('context test', ['key' => 'value']);
        $content = file_get_contents(glob($this->logDir . '/log_*.txt')[0]);
        $this->assertStringContainsString('context test', $content);
        $this->assertStringContainsString('key', $content);
        $this->assertStringContainsString('value', $content);
    }

    public function test_log_with_psr3_level_string(): void
    {
        $logger = $this->makeLogger();
        $logger->log(LogLevel::WARNING, 'psr3 warning');
        $content = file_get_contents(glob($this->logDir . '/log_*.txt')[0]);
        $this->assertStringContainsString('[WARNING]', $content);
        $this->assertStringContainsString('psr3 warning', $content);
    }

    public function test_severity_threshold_suppresses_lower_levels(): void
    {
        $logger = $this->makeLogger(Logger::ERROR);
        $logger->debug('suppressed debug');
        $logger->error('visible error');
        $files = glob($this->logDir . '/log_*.txt');
        $content = $files ? file_get_contents($files[0]) : '';
        $this->assertStringNotContainsString('suppressed debug', $content);
        $this->assertStringContainsString('visible error', $content);
    }

    public function test_invalid_level_throws(): void
    {
        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        $this->makeLogger()->log('bogus_level', 'msg');
    }

    public function test_all_psr3_level_methods_exist(): void
    {
        $logger = $this->makeLogger();
        foreach ([
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL,
            LogLevel::ERROR, LogLevel::WARNING, LogLevel::NOTICE,
            LogLevel::INFO, LogLevel::DEBUG,
        ] as $level) {
            $this->assertTrue(method_exists($logger, $level), "Missing method: $level");
        }
    }
}
