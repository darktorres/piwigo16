<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Modified version of KLogger 0.2.0
 *
 * @author  Kenny Katzgrau <katzgrau@gmail.com>
 */
class Logger
{
    /**
     * Error severity, from low to high. From BSD syslog RFC, section 4.1.1
     * @see http://www.faqs.org/rfcs/rfc3164.html
     */
    public const EMERGENCY = 0;  // Emergency: system is unusable

    public const ALERT = 1;  // Alert: action must be taken immediately

    public const CRITICAL = 2;  // Critical: critical conditions

    public const ERROR = 3;  // Error: error conditions

    public const WARNING = 4;  // Warning: warning conditions

    public const NOTICE = 5;  // Notice: normal but significant condition

    public const INFO = 6;  // Informational: informational messages

    public const DEBUG = 7;  // Debug: debug messages

    /**
     * Custom "disable" level.
     */
    public const OFF = -1; // Log nothing at all

    /**
     * Internal status codes.
     */
    public const STATUS_LOG_OPEN = 1;

    public const STATUS_OPEN_FAILED = 2;

    public const STATUS_LOG_CLOSED = 3;

    /**
     * Disable archive purge.
     */
    public const ARCHIVE_NO_PURGE = -1;

    /**
     * Standard messages produced by the class.
     */
    private static array $_messages = [
        'writefail' => 'The file could not be written to. Check that appropriate permissions have been set.',
        'opensuccess' => 'The log file was opened successfully.',
        'openfail' => 'The file could not be opened. Check permissions.',
    ];

    /**
     * Instance options.
     */
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
     * @var resource|null null until open() successfully opens the file
     *   (never assigned falsy on failure — that path throws instead)
     */
    private $_fileHandle;

    /**
     * @param array $options
     */
    public function __construct($options)
    {
        $this->options = array_merge($this->options, $options);

        if (is_string($this->options['severity'])) {
            $this->options['severity'] = self::codeToLevel($this->options['severity']);
        }

        if ($this->options['severity'] === self::OFF) {
            return;
        }

        $this->options['directory'] = rtrim((string) $this->options['directory'], '\\/') . DIRECTORY_SEPARATOR;

        if ($this->options['filename'] == null) {
            $this->options['filename'] = 'log_' . date('Y-m-d') . '.txt';
        }

        $this->options['filePath'] = $this->options['directory'] . $this->options['filename'];

        if ($this->options['archiveDays'] != self::ARCHIVE_NO_PURGE && mt_rand() % 97 == 0) {
            $this->purge();
        }
    }

    /**
     * Open the log file if not already oppenned
     */
    private function open(): void
    {
        if ($this->status() == self::STATUS_LOG_CLOSED) {
            if (! file_exists($this->options['directory'])) {
                mkgetdir($this->options['directory'], MKGETDIR_DEFAULT | MKGETDIR_PROTECT_HTACCESS);
            }

            if (file_exists($this->options['filePath']) && ! is_writable($this->options['filePath'])) {
                $this->_logStatus = self::STATUS_OPEN_FAILED;
                throw new RuntimeException(self::$_messages['writefail']);
            }

            if (($this->_fileHandle = fopen($this->options['filePath'], 'a')) != false) {
                $this->_logStatus = self::STATUS_LOG_OPEN;
            } else {
                $this->_logStatus = self::STATUS_OPEN_FAILED;
                throw new RuntimeException(self::$_messages['openfail']);
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
    public function severity()
    {
        return $this->options['severity'];
    }

    /**
     * Writes a $line to the log with a severity level of DEBUG.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function debug($line, $cat = null, $args = []): void
    {
        $this->log(self::DEBUG, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of INFO.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function info($line, $cat = null, $args = []): void
    {
        $this->log(self::INFO, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of NOTICE.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function notice($line, $cat = null, $args = []): void
    {
        $this->log(self::NOTICE, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of WARNING.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function warn($line, $cat = null, $args = []): void
    {
        $this->log(self::WARNING, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ERROR.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function error($line, $cat = null, $args = []): void
    {
        $this->log(self::ERROR, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ALERT.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function alert($line, $cat = null, $args = []): void
    {
        $this->log(self::ALERT, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of CRITICAL.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function critical($line, $cat = null, $args = []): void
    {
        $this->log(self::CRITICAL, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of EMERGENCY.
     *
     * @param string $line
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function emergency($line, $cat = null, $args = []): void
    {
        $this->log(self::EMERGENCY, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with the given severity.
     *
     * @param int $severity
     * @param string|array|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array $args
     */
    public function log($severity, $message, $cat = null, $args = []): void
    {
        if ($this->severity() >= $severity) {
            if (is_array($cat)) {
                $args = $cat;
                $cat = null;
            }
            $line = $this->formatMessage($severity, $message, $cat, $args);
            $this->write($line);
        }
    }

    /**
     * Directly writes a line to the log without adding level and time.
     *
     * @param string $line
     */
    public function write($line): void
    {
        $this->open();
        if ($this->status() == self::STATUS_LOG_OPEN) {
            if (fwrite($this->_fileHandle, $line) === false) {
                throw new RuntimeException(self::$_messages['writefail']);
            }
        }
    }

    /**
     * Purges files matching 'globPattern' older than 'archiveDays'.
     */
    public function purge(): void
    {
        $files = glob($this->options['directory'] . $this->options['globPattern']);
        $limit = time() - $this->options['archiveDays'] * 86400;

        foreach ($files as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    /**
     * Formats the message for logging.
     *
     * @param  int $level severity level constant (self::EMERGENCY etc.) —
     *   log()'s only caller always passes its own int $severity here
     * @param  string $message
     * @param  array  $context
     */
    private function formatMessage($level, $message, $cat, $context): string
    {
        global $page;

        if (! empty($context)) {
            $message .= "\n" . $this->indent($this->contextToString($context));
        }
        $line = '[' . $this->getTimestamp() . '][exec=' . ($page['execution_uuid'] ?? 'unkonwn') . "]\t[" . self::levelToCode($level) . "]\t";
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
        $date = new DateTime(date('Y-m-d H:i:s.' . $micro, intval($originalTime)));
        return $date->format($this->options['dateFormat']);
    }

    /**
     * Takes the given context and converts it to a string.
     *
     * @param  array $context
     */
    private function contextToString($context): string
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
     * @param  string $string The string to indent
     * @param  string $indent What to use as the indent.
     * @return string
     */
    private function indent(string $string, $indent = '  ')
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
            default => throw new RuntimeException('Unknown severity level ' . $level),
        };
    }

    /**
     * Converts level names to constant.
     *
     * @param string $code
     */
    public static function codeToLevel($code): int
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
            default => throw new RuntimeException('Unknown severity code ' . $code),
        };
    }
}
