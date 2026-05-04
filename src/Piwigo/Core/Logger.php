<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Modified version of KLogger 0.2.0
 *
 * @author  Kenny Katzgrau <katzgrau@gmail.com>
 *
 * @package logger
 */

class Logger
{
    /**
     * Error severity, from low to high. From BSD syslog RFC, section 4.1.1
     * @link http://www.faqs.org/rfcs/rfc3164.html
     */
    public const EMERGENCY = 0;  // Emergency: system is unusable
    public const ALERT     = 1;  // Alert: action must be taken immediately
    public const CRITICAL  = 2;  // Critical: critical conditions
    public const ERROR     = 3;  // Error: error conditions
    public const WARNING   = 4;  // Warning: warning conditions
    public const NOTICE    = 5;  // Notice: normal but significant condition
    public const INFO      = 6;  // Informational: informational messages
    public const DEBUG     = 7;  // Debug: debug messages

    /**
     * Custom "disable" level.
     */
    public const OFF       = -1; // Log nothing at all

    /**
     * Internal status codes.
     */
    public const STATUS_LOG_OPEN  = 1;
    public const STATUS_OPEN_FAILED = 2;
    public const STATUS_LOG_CLOSED  = 3;

    /**
     * Disable archive purge.
     */
    public const ARCHIVE_NO_PURGE = -1;

    /**
     * Standard messages produced by the class.
     */
    /** @var array<string,string> */
    private static array $_messages = [
      'writefail'   => 'The file could not be written to. Check that appropriate permissions have been set.',
      'opensuccess' => 'The log file was opened successfully.',
      'openfail'  => 'The file could not be opened. Check permissions.',
    ];

    /**
     * Instance options.
     */
    /** @var array<string,mixed> */
    private array $options = [
      'directory' => null, // Log files directory
      'filename' => null, // Path to the log file
      'globPattern' => 'log_*.txt', // Pattern to select all log files with glob()
      'severity' => self::DEBUG, // Current minimum logging threshold
      'dateFormat' => 'Y-m-d G:i:s', // Date format
      'archiveDays' => self::ARCHIVE_NO_PURGE, // Number of files to keep
      ];

    /**
     * Current status of the logger.
     */
    private int $_logStatus = self::STATUS_LOG_CLOSED;
    /**
     * File handle for this instance's log file.
     * @var resource|null
     */
    private $_fileHandle = null;


    /**
     * Class constructor.
     *
     * @param array $options
     * @return void
     */
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

    /**
     * Open the log file if not already oppenned
     */
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

    /**
     * Class destructor.
     */
    public function __destruct()
    {
        if ($this->_fileHandle) {
            fclose($this->_fileHandle);
        }
    }

    /**
     * Returns logger status.
     */
    public function status(): int
    {
        return $this->_logStatus;
    }

    /**
     * Returns logger severity threshold.
     *
     * @return int
     */
    public function severity(): int
    {
        $v = $this->options['severity'];
        return is_int($v) ? $v : (is_scalar($v) ? (int) $v : self::DEBUG);
    }

    /**
     * Writes a $line to the log with a severity level of DEBUG.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function debug(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::DEBUG, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of INFO.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function info(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::INFO, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of NOTICE.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function notice(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::NOTICE, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of WARNING.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function warn(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::WARNING, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ERROR.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function error(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::ERROR, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ALERT.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function alert(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::ALERT, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of CRITICAL.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function critical(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::CRITICAL, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of EMERGENCY.
     *
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function emergency(string $line, ?string $cat = null, array $args = []): void
    {
        $this->log(self::EMERGENCY, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with the given severity.
     *
     * @param integer $severity
     * @param string $cat
     */
    /** @param array<mixed> $args */
    public function log(int $severity, string $message, ?string $cat = null, array $args = []): void
    {
        if ($this->severity() >= $severity) {
            $line = $this->formatMessage($severity, $message, $cat, $args);
            $this->write($line);
        }
    }

    /**
     * Directly writes a line to the log without adding level and time.
     *
     */
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

    /**
     * Purges files matching 'globPattern' older than 'archiveDays'.
     */
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

    /**
     * Formats the message for logging.
     *
     * @param  int $level
     * @param  array  $context
     */
    /** @param array<mixed> $context */
    private function formatMessage(int $level, string $message, ?string $cat, array $context): string
    {
        if (!empty($context)) {
            $message .= "\n" . $this->indent($this->contextToString($context));
        }
        $uuid = PageState::current()->executionUuid ?: 'unknown';
        $line = '[' . $this->getTimestamp() . '][exec=' . $uuid . "]\t[" . self::levelToCode($level) . "]\t";
        if ($cat != null) {
            $line .= '[' . $cat . "]\t";
        }
        return $line . $message . "\n";
    }

    /**
     * Gets the formatted Date/Time for the log entry.
     *
     * PHP DateTime is dumb, and you have to resort to trickery to get microseconds
     * to work correctly, so here it is.
     */
    private function getTimestamp(): string
    {
        $originalTime = microtime(true);
        $micro = sprintf('%06d', ($originalTime - floor($originalTime)) * 1000000);
        $date = new \DateTime(date('Y-m-d H:i:s.'.$micro, intval($originalTime)));
        $dateFormat = is_scalar($this->options['dateFormat']) ? (string) $this->options['dateFormat'] : 'Y-m-d G:i:s';
        return $date->format($dateFormat);
    }

    /**
     * Takes the given context and converts it to a string.
     *
     * @param  array $context
     */
    /** @param array<mixed> $context */
    private function contextToString(array $context): string
    {
        $export = '';
        foreach ($context as $key => $value) {
            $export .= $key . ': ';
            $export .= preg_replace(
                [
        '/=>\s+([a-zA-Z])/im',
        '/array\(\s+\)/im',
        '/^  |\G  /m',
        ],
                [
        '=> $1',
        'array()',
        '  ',
        ],
                str_replace('array (', 'array(', var_export($value, true))
            );
            $export .= PHP_EOL;
        }
        return str_replace(['\\\\', '\\\''], ['\\', '\''], rtrim($export));
    }

    /**
     * Indents the given string with the given indent.
     *
     * @param  string $indent What to use as the indent.
     * @return string
     */
    private function indent(string $string, string $indent = '  ')
    {
        return $indent . str_replace("\n", "\n" . $indent, $string);
    }

    /**
     * Converts level constants to string name.
     *
     * @param int $level
     */
    public static function levelToCode($level): string
    {
        return match ($level) {
            self::EMERGENCY => 'EMERGENCY',
            self::ALERT => 'ALERT',
            self::CRITICAL => 'CRITICAL',
            self::NOTICE => 'NOTICE',
            self::INFO => 'INFO',
            self::WARNING => 'WARNING',
            self::DEBUG => 'DEBUG',
            self::ERROR => 'ERROR',
            default => throw new \RuntimeException('Unknown severity level ' . $level),
        };
    }

    /**
     * Converts level names to constant.
     */
    public static function codeToLevel(string $code): int
    {
        return match (strtoupper($code)) {
            'EMERGENCY' => self::EMERGENCY,
            'ALERT' => self::ALERT,
            'CRITICAL' => self::CRITICAL,
            'NOTICE' => self::NOTICE,
            'INFO' => self::INFO,
            'WARNING' => self::WARNING,
            'DEBUG' => self::DEBUG,
            'ERROR' => self::ERROR,
            default => throw new \RuntimeException('Unknown severity code ' . $code),
        };
    }
}
