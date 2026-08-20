<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Core;

use DateTime;
use LogicException;
use Piwigo\Config\CurrentConfig;
use RuntimeException;

/**
 * Modified version of KLogger 0.2.0
 *
 * Every $cat/$args/$context parameter below stays a generic
 * array<int|string, mixed> by design -- matches PSR-3 LoggerInterface's own
 * $context parameter, var_export()'d as-is by contextToString() regardless
 * of shape.
 */
final class Logger
{
    /**
     * Error severity, from low to high. From BSD syslog RFC, section 4.1.1
     * @see http://www.faqs.org/rfcs/rfc3164.html
     */
    public const int EMERGENCY = 0;  // Emergency: system is unusable

    public const int ALERT = 1;  // Alert: action must be taken immediately

    public const int CRITICAL = 2;  // Critical: critical conditions

    public const int ERROR = 3;  // Error: error conditions

    public const int WARNING = 4;  // Warning: warning conditions

    public const int NOTICE = 5;  // Notice: normal but significant condition

    public const int INFO = 6;  // Informational: informational messages

    public const int DEBUG = 7;  // Debug: debug messages

    /**
     * Custom "disable" level.
     */
    public const int OFF = -1; // Log nothing at all

    /**
     * Internal status codes.
     */
    public const int STATUS_LOG_OPEN = 1;

    public const int STATUS_OPEN_FAILED = 2;

    public const int STATUS_LOG_CLOSED = 3;

    /**
     * Disable archive purge.
     */
    public const int ARCHIVE_NO_PURGE = -1;

    /**
     * Standard messages produced by the class.
     * @var array<string, string>
     */
    private static array $messages = [
        'writefail' => 'The file could not be written to. Check that appropriate permissions have been set.',
        'opensuccess' => 'The log file was opened successfully.',
        'openfail' => 'The file could not be opened. Check permissions.',
    ];

    /**
     * Instance options. directory/filename start null and are normalized to
     * real strings by the constructor (unless severity is OFF, which
     * returns before normalizing -- every real caller passes both anyway,
     * see the two production construction sites); filePath is added by the
     * constructor, never passed in.
     *
     * @var array{directory?: string|null, filename?: string|null, globPattern?: string, severity?: int|string, dateFormat?: string, archiveDays?: int, filePath?: string}
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
    private int $logStatus = self::STATUS_LOG_CLOSED;

    /**
     * File handle for this instance's log file.
     * @var resource|null null until open() successfully opens the file
     *   (never assigned falsy on failure — that path throws instead).
     *   PHP has no native `resource` type declaration, so this stays
     *   `mixed` natively -- the docblock still does the real narrowing.
     */
    private mixed $fileHandle = null;

    /**
     * @param array{directory?: string|null, filename?: string|null, globPattern?: string, severity?: int|string, dateFormat?: string, archiveDays?: int} $options
     */
    public function __construct(array $options)
    {
        $this->options = array_merge($this->options, $options);

        if (is_string($this->options['severity'] ?? null)) {
            $this->options['severity'] = self::codeToLevel($this->options['severity']);
        }

        if (($this->options['severity'] ?? null) === self::OFF) {
            return;
        }

        $directory = $this->options['directory'] ?? null;
        $this->options['directory'] = rtrim(is_string($directory) ? $directory : '', '\\/') . DIRECTORY_SEPARATOR;

        $filename = $this->options['filename'] ?? null;
        if ($filename === null || $filename === '') {
            $filename = 'log_' . date('Y-m-d') . '.txt';
        }
        $this->options['filename'] = $filename;

        $this->options['filePath'] = $this->options['directory'] . $this->options['filename'];

        $archiveDaysOption = $this->options['archiveDays'] ?? self::ARCHIVE_NO_PURGE;
        if ($archiveDaysOption !== self::ARCHIVE_NO_PURGE && mt_rand() % 97 === 0) {
            $this->purge();
        }
    }

    /**
     * Open the log file if not already oppenned
     */
    private function open(): void
    {
        if ($this->status() === self::STATUS_LOG_CLOSED) {
            $directory = $this->options['directory'] ?? null;
            $directory = is_string($directory) ? $directory : '';

            if (! file_exists($directory)) {
                FilesystemHelper::mkgetdir($directory, $this->currentConfig(), FilesystemHelper::MKGETDIR_DEFAULT | FilesystemHelper::MKGETDIR_PROTECT_HTACCESS);
            }

            $filePath = $this->options['filePath'] ?? null;
            $filePath = is_string($filePath) ? $filePath : '';

            if (file_exists($filePath) && ! is_writable($filePath)) {
                $this->logStatus = self::STATUS_OPEN_FAILED;
                throw new RuntimeException(self::$messages['writefail']);
            }

            $handle = fopen($filePath, 'a');
            if ($handle !== false) {
                $this->fileHandle = $handle;
                $this->logStatus = self::STATUS_LOG_OPEN;
            } else {
                $this->logStatus = self::STATUS_OPEN_FAILED;
                throw new RuntimeException(self::$messages['openfail']);
            }
        }
    }

    /**
     * Class destructor.
     */
    public function __destruct()
    {
        if ((bool) $this->fileHandle) {
            fclose($this->fileHandle);
        }
    }

    /**
     * Returns logger status.
     */
    public function status(): int
    {
        return $this->logStatus;
    }

    /**
     * Returns logger severity threshold.
     */
    public function severity(): int
    {
        $severity = $this->options['severity'] ?? null;
        return is_int($severity) ? $severity : self::DEBUG;
    }

    /**
     * Writes a $line to the log with a severity level of DEBUG.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function debug(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::DEBUG, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of INFO.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function info(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::INFO, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of NOTICE.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function notice(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::NOTICE, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of WARNING.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function warn(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::WARNING, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ERROR.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function error(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::ERROR, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of ALERT.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function alert(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::ALERT, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of CRITICAL.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function critical(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::CRITICAL, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with a severity level of EMERGENCY.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function emergency(string $line, array|string|null $cat = null, array $args = []): void
    {
        $this->log(self::EMERGENCY, $line, $cat, $args);
    }

    /**
     * Writes a $line to the log with the given severity.
     *
     * @param array<int|string, mixed>|string|null $cat some call sites (e.g.
     *   admin/include/functions.php's $logger->debug('taglist_before', $taglist_before))
     *   pass an array here as a 2-arg debug(message, data) convenience —
     *   log() detects this and treats it as $args instead
     * @param array<int|string, mixed> $args
     */
    public function log(int $severity, string $message, array|string|null $cat = null, array $args = []): void
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
     */
    public function write(string $line): void
    {
        $this->open();
        if ($this->status() === self::STATUS_LOG_OPEN) {
            // status() only reaches STATUS_LOG_OPEN in open()'s branch that
            // also assigns fileHandle a real resource.
            assert($this->fileHandle !== null);
            if (fwrite($this->fileHandle, $line) === false) {
                throw new RuntimeException(self::$messages['writefail']);
            }
        }
    }

    /**
     * Purges files matching 'globPattern' older than 'archiveDays'.
     */
    public function purge(): void
    {
        $directory = $this->options['directory'] ?? null;
        $directory = is_string($directory) ? $directory : '';
        $globPattern = $this->options['globPattern'] ?? null;
        $globPattern = is_string($globPattern) ? $globPattern : '';

        $files = glob($directory . $globPattern);
        if ($files === false) {
            return;
        }

        $archiveDays = $this->options['archiveDays'] ?? self::ARCHIVE_NO_PURGE;
        $limit = time() - $archiveDays * 86400;

        foreach ($files as $file) {
            if (@filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }

    /**
     * Same "container resolve, not a constructor property" reasoning as
     * Activity\ActivityService's own currentUser()/currentConfig() --
     * ~80 real `new Logger(...)` construction sites (too many for a
     * required constructor param), and Logger itself is constructed
     * before Kernel::boot() in some contexts (CurrentLogger's own
     * pre-boot fallback), so this can't assume the container exists
     * either. RequestMetrics' constructor is a genuinely trivial no-arg
     * `public function __construct() {}` (unlike ImageStdParams, which
     * eagerly queries the DB) -- a fresh, unmemoized fallback instance is
     * safe here, only ever degrading formatMessage()'s own executionUuid
     * correlation field to its empty default.
     */
    private function requestMetrics(): RequestMetrics
    {
        if (Kernel::isBooted()) {
            $requestMetrics = Kernel::container()->get(RequestMetrics::class);
            if (! $requestMetrics instanceof RequestMetrics) {
                throw new LogicException('Container returned an unexpected type for ' . RequestMetrics::class);
            }

            return $requestMetrics;
        }

        return new RequestMetrics();
    }

    /**
     * Same "container resolve, not a constructor property" reasoning as
     * requestMetrics() above -- used only inside open()'s own mkgetdir() call
     * below. Constructing a CurrentConfig does no DB read, so a fresh,
     * unmemoized instance here is safe.
     */
    private function currentConfig(): CurrentConfig
    {
        if (Kernel::isBooted()) {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }

            return $currentConfig;
        }

        return new CurrentConfig();
    }

    /**
     * Formats the message for logging.
     *
     * @param  int $level severity level constant (self::EMERGENCY etc.) —
     *   log()'s only caller always passes its own int $severity here
     * @param  ?string $cat log()'s only caller has already resolved an
     *   array $cat into $args and reset $cat to null before this call
     * @param  array<int|string, mixed>  $context
     */
    private function formatMessage(int $level, string $message, ?string $cat, array $context): string
    {
        if ($context !== []) {
            $message .= "\n" . $this->indent($this->contextToString($context));
        }
        $executionUuid = $this->requestMetrics()
            ->executionUuid;
        $executionUuid = $executionUuid !== '' ? $executionUuid : 'unkonwn';
        $line = '[' . $this->getTimestamp() . '][exec=' . $executionUuid . "]\t[" . self::levelToCode($level) . "]\t";
        if ($cat !== null) {
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
        $micro = sprintf('%06d', (int) (($originalTime - floor($originalTime)) * 1000000.0));
        $date = new DateTime(date('Y-m-d H:i:s.' . $micro, intval($originalTime)));

        $dateFormat = $this->options['dateFormat'] ?? null;
        $dateFormat = is_string($dateFormat) ? $dateFormat : 'Y-m-d G:i:s';

        return $date->format($dateFormat);
    }

    /**
     * Takes the given context and converts it to a string.
     *
     * @param  array<int|string, mixed> $context
     */
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
     * @param  string $string The string to indent
     * @param  string $indent What to use as the indent.
     */
    private function indent(string $string, string $indent = '  '): string
    {
        return $indent . str_replace("\n", "\n" . $indent, $string);
    }

    /**
     * Converts level constants to string name.
     */
    public static function levelToCode(int $level): string
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
            default => throw new RuntimeException('Unknown severity code ' . $code),
        };
    }
}
