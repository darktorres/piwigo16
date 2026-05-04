<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Psr\Log\InvalidArgumentException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level as MonologLevel;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger backed by Monolog StreamHandler.
 *
 * Extends AbstractLogger so all 8 PSR-3 leveled methods are provided
 * automatically. Keeps Piwigo's integer severity constants and helper
 * methods for the handful of callers that still reference them.
 */
class Logger extends AbstractLogger
{
    /** Integer severity — higher = more verbose (0=emergency, 7=debug). */
    public const EMERGENCY = 0;
    public const ALERT     = 1;
    public const CRITICAL  = 2;
    public const ERROR     = 3;
    public const WARNING   = 4;
    public const NOTICE    = 5;
    public const INFO      = 6;
    public const DEBUG     = 7;

    /** Disable logging entirely. */
    public const OFF = -1;

    public const ARCHIVE_NO_PURGE = -1;

    private readonly MonologLogger $mono;
    private readonly int $configuredSeverity;
    private readonly string $logDir;
    private readonly string $globPattern;
    private readonly int $archiveDays;

    /** @param array<string,mixed> $options */
    public function __construct(array $options)
    {
        $severityOpt = $options['severity'] ?? self::DEBUG;
        $this->configuredSeverity = is_string($severityOpt)
            ? self::codeToLevel($severityOpt)
            : (is_int($severityOpt) ? $severityOpt : self::DEBUG);

        $dir = rtrim(is_scalar($options['directory'] ?? null) ? (string) $options['directory'] : '', '\\/') . DIRECTORY_SEPARATOR;
        $filename = is_scalar($options['filename'] ?? null) ? (string) $options['filename'] : 'log_' . date('Y-m-d') . '.txt';

        $this->logDir = $dir;
        $this->globPattern = is_scalar($options['globPattern'] ?? null) ? (string) $options['globPattern'] : 'log_*.txt';
        $this->archiveDays = is_scalar($options['archiveDays'] ?? null) ? (int) $options['archiveDays'] : self::ARCHIVE_NO_PURGE;

        $this->mono = new MonologLogger('piwigo');

        if ($this->configuredSeverity !== self::OFF) {
            $formatter = new LineFormatter(
                "[%datetime%][exec=%extra.exec_uuid%]\t[%level_name%]\t%message% %context%\n",
                'Y-m-d G:i:s.u',
                allowInlineLineBreaks: true,
                ignoreEmptyContextAndExtra: true,
            );
            $handler = new StreamHandler($dir . $filename, $this->intToMonologLevel($this->configuredSeverity));
            $handler->setFormatter($formatter);
            $this->mono->pushHandler($handler);
        }

        $this->mono->pushProcessor(static function (LogRecord $record): LogRecord {
            $extra = $record->extra;
            $extra['exec_uuid'] = PageState::current()->executionUuid ?: 'unknown';
            return $record->with(extra: $extra);
        });

        if ($this->archiveDays !== self::ARCHIVE_NO_PURGE && random_int(0, mt_getrandmax()) % 97 == 0) {
            $this->purge();
        }
    }

    // -------------------------------------------------------------------------
    // PSR-3 — the single required implementation
    // -------------------------------------------------------------------------

    /** @param array<mixed> $context */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->mono->log($this->psrLevelToMonolog($level), (string) $message, $context);
    }

    // -------------------------------------------------------------------------
    // Piwigo-specific helpers retained for callers that reference them
    // -------------------------------------------------------------------------

    public function severity(): int
    {
        return $this->configuredSeverity;
    }

    public function purge(): void
    {
        $files = glob($this->logDir . $this->globPattern);
        $limit = time() - $this->archiveDays * 86400;
        foreach ($files ?: [] as $file) {
            $mtime = Filesystem::tryFileMtime($file);
            if ($mtime !== false && $mtime < $limit) {
                Filesystem::tryUnlink($file);
            }
        }
    }

    public static function levelToCode(int $level): string
    {
        return match ($level) {
            self::EMERGENCY => 'EMERGENCY',
            self::ALERT     => 'ALERT',
            self::CRITICAL  => 'CRITICAL',
            self::ERROR     => 'ERROR',
            self::WARNING   => 'WARNING',
            self::NOTICE    => 'NOTICE',
            self::INFO      => 'INFO',
            self::DEBUG     => 'DEBUG',
            default         => throw new \RuntimeException('Unknown severity level ' . $level),
        };
    }

    public static function codeToLevel(string $code): int
    {
        return match (strtoupper($code)) {
            'EMERGENCY' => self::EMERGENCY,
            'ALERT'     => self::ALERT,
            'CRITICAL'  => self::CRITICAL,
            'ERROR'     => self::ERROR,
            'WARNING'   => self::WARNING,
            'NOTICE'    => self::NOTICE,
            'INFO'      => self::INFO,
            'DEBUG'     => self::DEBUG,
            default     => throw new \RuntimeException('Unknown severity code ' . $code),
        };
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function psrLevelToMonolog(mixed $level): MonologLevel
    {
        if (!is_string($level) && !($level instanceof \Stringable)) {
            throw new InvalidArgumentException('Log level must be a string, got: ' . gettype($level));
        }
        $levelStr = (string) $level;
        return match ($levelStr) {
            LogLevel::EMERGENCY => MonologLevel::Emergency,
            LogLevel::ALERT     => MonologLevel::Alert,
            LogLevel::CRITICAL  => MonologLevel::Critical,
            LogLevel::ERROR     => MonologLevel::Error,
            LogLevel::WARNING   => MonologLevel::Warning,
            LogLevel::NOTICE    => MonologLevel::Notice,
            LogLevel::INFO      => MonologLevel::Info,
            LogLevel::DEBUG     => MonologLevel::Debug,
            default             => throw new InvalidArgumentException('Unknown log level: ' . $levelStr),
        };
    }

    private function intToMonologLevel(int $level): MonologLevel
    {
        return match (true) {
            $level <= self::EMERGENCY => MonologLevel::Emergency,
            $level <= self::ALERT     => MonologLevel::Alert,
            $level <= self::CRITICAL  => MonologLevel::Critical,
            $level <= self::ERROR     => MonologLevel::Error,
            $level <= self::WARNING   => MonologLevel::Warning,
            $level <= self::NOTICE    => MonologLevel::Notice,
            $level <= self::INFO      => MonologLevel::Info,
            default                   => MonologLevel::Debug,
        };
    }
}
