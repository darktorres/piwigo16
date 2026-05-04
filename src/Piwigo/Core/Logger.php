<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * PSR-3 file logger. Extends AbstractLogger so all 8 leveled convenience
 * methods (debug, info, notice, warning, error, critical, alert, emergency)
 * are provided automatically and routed to log().
 *
 * Modified version of KLogger 0.2.0 (Kenny Katzgrau <katzgrau@gmail.com>)
 */
class Logger extends AbstractLogger
{
    /** Integer severity constants — higher number = more verbose (syslog-inspired). */
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

    public const STATUS_LOG_OPEN    = 1;
    public const STATUS_OPEN_FAILED = 2;
    public const STATUS_LOG_CLOSED  = 3;

    public const ARCHIVE_NO_PURGE = -1;

    /** @var array<string,string> */
    private static array $_messages = [
        'writefail'   => 'The file could not be written to. Check that appropriate permissions have been set.',
        'opensuccess' => 'The log file was opened successfully.',
        'openfail'    => 'The file could not be opened. Check permissions.',
    ];

    /** @var array<string,mixed> */
    private array $options = [
        'directory'   => null,
        'filename'    => null,
        'globPattern' => 'log_*.txt',
        'severity'    => self::DEBUG,
        'dateFormat'  => 'Y-m-d G:i:s',
        'archiveDays' => self::ARCHIVE_NO_PURGE,
    ];

    private int $_logStatus = self::STATUS_LOG_CLOSED;

    /** @var resource|null */
    private $_fileHandle = null;

    /** @param array<string,mixed> $options */
    public function __construct(array $options)
    {
        $this->options = array_merge($this->options, $options);

        if (is_string($this->options['severity'])) {
            $this->options['severity'] = self::codeToLevel($this->options['severity']);
        }

        if ($this->options['severity'] === self::OFF) {
            return;
        }

        $dir = is_scalar($this->options['directory']) ? (string) $this->options['directory'] : '';
        $this->options['directory'] = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR;

        if ($this->options['filename'] == null) {
            $this->options['filename'] = 'log_' . date('Y-m-d') . '.txt';
        }

        $filename = is_scalar($this->options['filename']) ? (string) $this->options['filename'] : '';
        $this->options['filePath'] = (string) $this->options['directory'] . $filename;

        if ($this->options['archiveDays'] != self::ARCHIVE_NO_PURGE && random_int(0, mt_getrandmax()) % 97 == 0) {
            $this->purge();
        }
    }

    public function __destruct()
    {
        if ($this->_fileHandle) {
            fclose($this->_fileHandle);
        }
    }

    // -------------------------------------------------------------------------
    // PSR-3 LoggerInterface — the single required implementation
    // -------------------------------------------------------------------------

    /**
     * Logs with an arbitrary PSR-3 level.
     *
     * @param mixed   $level   A Psr\Log\LogLevel string constant.
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $intLevel = $this->psrLevelToInt($level);
        if ($this->severity() >= $intLevel) {
            $line = $this->formatMessage($intLevel, (string) $message, $context);
            $this->write($line);
        }
    }

    // -------------------------------------------------------------------------
    // Public helpers retained from the original API
    // -------------------------------------------------------------------------

    public function status(): int
    {
        return $this->_logStatus;
    }

    public function severity(): int
    {
        $v = $this->options['severity'];
        return is_int($v) ? $v : (is_scalar($v) ? (int) $v : self::DEBUG);
    }

    public function write(string $line): void
    {
        $this->open();
        $fh = $this->_fileHandle;
        if ($this->status() == self::STATUS_LOG_OPEN && $fh !== null) {
            if (fwrite($fh, $line) === false) {
                throw new \RuntimeException(self::$_messages['writefail']);
            }
        }
    }

    public function purge(): void
    {
        $dir = is_scalar($this->options['directory']) ? (string) $this->options['directory'] : '';
        $globPat = is_scalar($this->options['globPattern']) ? (string) $this->options['globPattern'] : '';
        $files = glob($dir . $globPat);
        $archiveDays = is_scalar($this->options['archiveDays']) ? (int) $this->options['archiveDays'] : self::ARCHIVE_NO_PURGE;
        $limit = time() - $archiveDays * 86400;

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
    // Private helpers
    // -------------------------------------------------------------------------

    private function open(): void
    {
        if ($this->status() == self::STATUS_LOG_CLOSED) {
            $dir = is_scalar($this->options['directory']) ? (string) $this->options['directory'] : '';
            $filePath = is_scalar($this->options['filePath']) ? (string) $this->options['filePath'] : '';
            if (!file_exists($dir)) {
                mkgetdir($dir, MKGETDIR_DEFAULT | MKGETDIR_PROTECT_HTACCESS);
            }

            if (file_exists($filePath) && !is_writable($filePath)) {
                $this->_logStatus = self::STATUS_OPEN_FAILED;
                throw new \RuntimeException(self::$_messages['writefail']);
            }

            $fh = fopen($filePath, 'a');
            if ($fh !== false) {
                $this->_fileHandle = $fh;
                $this->_logStatus = self::STATUS_LOG_OPEN;
            } else {
                $this->_logStatus = self::STATUS_OPEN_FAILED;
                throw new \RuntimeException(self::$_messages['openfail']);
            }
        }
    }

    /** @param array<mixed> $context */
    private function formatMessage(int $level, string $message, array $context): string
    {
        if (!empty($context)) {
            $message .= "\n" . $this->indent($this->contextToString($context));
        }
        $uuid = PageState::current()->executionUuid ?: 'unknown';
        return '[' . $this->getTimestamp() . '][exec=' . $uuid . "]\t[" . self::levelToCode($level) . "]\t" . $message . "\n";
    }

    private function getTimestamp(): string
    {
        $originalTime = microtime(true);
        $micro = sprintf('%06d', ($originalTime - floor($originalTime)) * 1000000);
        $date = new \DateTime(date('Y-m-d H:i:s.' . $micro, intval($originalTime)));
        $dateFormat = is_scalar($this->options['dateFormat']) ? (string) $this->options['dateFormat'] : 'Y-m-d G:i:s';
        return $date->format($dateFormat);
    }

    /** @param array<mixed> $context */
    private function contextToString(array $context): string
    {
        $export = '';
        foreach ($context as $key => $value) {
            $export .= $key . ': ';
            $export .= preg_replace(
                ['/=>\s+([a-zA-Z])/im', '/array\(\s+\)/im', '/^  |\G  /m'],
                ['=> $1', 'array()', '  '],
                str_replace('array (', 'array(', var_export($value, true))
            );
            $export .= PHP_EOL;
        }
        return str_replace(['\\\\', '\\\''], ['\\', '\''], rtrim($export));
    }

    private function indent(string $string, string $indent = '  '): string
    {
        return $indent . str_replace("\n", "\n" . $indent, $string);
    }

    private function psrLevelToInt(mixed $level): int
    {
        if (!is_string($level) && !($level instanceof \Stringable)) {
            throw new InvalidArgumentException('Log level must be a string, got: ' . gettype($level));
        }
        $levelStr = (string) $level;
        return match ($levelStr) {
            LogLevel::EMERGENCY => self::EMERGENCY,
            LogLevel::ALERT     => self::ALERT,
            LogLevel::CRITICAL  => self::CRITICAL,
            LogLevel::ERROR     => self::ERROR,
            LogLevel::WARNING   => self::WARNING,
            LogLevel::NOTICE    => self::NOTICE,
            LogLevel::INFO      => self::INFO,
            LogLevel::DEBUG     => self::DEBUG,
            default             => throw new InvalidArgumentException('Unknown log level: ' . $levelStr),
        };
    }
}
