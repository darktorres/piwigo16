<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Logger;
use Psr\Log\InvalidArgumentException;
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

    private function logFileContent(): string
    {
        $files = glob($this->logDir . '/log_*.txt') ?: [];
        return $files ? (string) file_get_contents($files[0]) : '';
    }

    public function test_implements_psr3_logger_interface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, $this->makeLogger());
    }

    public function test_debug_writes_to_file(): void
    {
        $this->makeLogger()->debug('hello debug');
        $content = $this->logFileContent();
        $this->assertStringContainsString('DEBUG', $content);
        $this->assertStringContainsString('hello debug', $content);
    }

    public function test_error_writes_to_file(): void
    {
        $this->makeLogger()->error('something went wrong');
        $content = $this->logFileContent();
        $this->assertStringContainsString('ERROR', $content);
        $this->assertStringContainsString('something went wrong', $content);
    }

    public function test_context_array_is_appended(): void
    {
        $this->makeLogger()->info('context test', ['key' => 'value']);
        $content = $this->logFileContent();
        $this->assertStringContainsString('context test', $content);
        $this->assertStringContainsString('key', $content);
    }

    public function test_log_with_psr3_level_string(): void
    {
        $this->makeLogger()->log(LogLevel::WARNING, 'psr3 warning');
        $content = $this->logFileContent();
        $this->assertStringContainsString('WARNING', $content);
        $this->assertStringContainsString('psr3 warning', $content);
    }

    public function test_severity_threshold_suppresses_lower_levels(): void
    {
        $logger = $this->makeLogger(Logger::ERROR);
        $logger->debug('suppressed debug');
        $logger->error('visible error');
        $content = $this->logFileContent();
        $this->assertStringNotContainsString('suppressed debug', $content);
        $this->assertStringContainsString('visible error', $content);
    }

    public function test_severity_returns_configured_level(): void
    {
        $this->assertSame(Logger::ERROR, $this->makeLogger(Logger::ERROR)->severity());
        $this->assertSame(Logger::DEBUG, $this->makeLogger(Logger::DEBUG)->severity());
    }

    public function test_invalid_level_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
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

    public function test_off_level_writes_nothing(): void
    {
        $logger = $this->makeLogger(Logger::OFF);
        $logger->error('should not appear');
        $this->assertEmpty($this->logFileContent());
    }
}
