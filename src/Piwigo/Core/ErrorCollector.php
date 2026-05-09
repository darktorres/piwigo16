<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Redirect PHP errors/warnings away from the response body into the browser's
 * DevTools console (for HTML pages) or DevTools Network → Response Headers
 * (for non-HTML responses such as JSON/XML/binary).
 *
 * Mechanism:
 *  - ob_start() with an output-buffer callback intercepts the complete HTML
 *    before it is sent to the client. The callback injects a <script> block
 *    just before </body> so console.warn() / console.error() fire in DevTools.
 *  - For non-HTML responses that contain no </body> tag, the callback leaves
 *    the output unchanged. Errors emitted as X-PHP-Error-N response headers
 *    during the error handler (before headers are committed) remain visible
 *    in DevTools → Network → Response Headers.
 *  - Errors are also passed to error_log() so Apache's error log stays the
 *    authoritative server-side record.
 */
final class ErrorCollector
{
    /** @var list<string> */
    private static array $collected = [];

    private static bool $active = false;

    public static function install(): void
    {
        if (self::$active) {
            return;
        }
        self::$active = true;

        // Never write errors inline — they corrupt non-HTML responses.
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');

        // The ob_start callback intercepts the full output right before it is
        // sent and injects <script>console.*</script> into HTML pages.
        // No ob_get_level() guard — PHP's output_buffering INI may already have
        // created a level-1 buffer, which would prevent our callback from being
        // registered. We always add our own level so the callback fires.
        ob_start(self::injectConsoleScript(...));

        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            // Respect the @ error-suppression operator.
            if (!(error_reporting() & $errno)) {
                return false;
            }

            $label = self::label($errno);
            $short = basename($errfile) . ':' . $errline;
            $msg   = "[{$label}] {$errstr} in {$short}";

            self::$collected[] = $msg;

            // Apache error log — authoritative server-side record.
            error_log("PHP {$label}: {$errstr} in {$errfile} on line {$errline}");

            // For non-HTML responses (JSON, XML, binary) that won't go through
            // the ob_start callback, also emit as response headers so they are
            // visible in DevTools → Network → Response Headers.
            if (!headers_sent()) {
                $n    = count(self::$collected);
                $safe = substr(str_replace(["\r", "\n"], ' ', $msg), 0, 500);
                header('X-PHP-Error-' . $n . ': ' . $safe);
                header('X-PHP-Error-Count: ' . $n);
            }

            return true; // Suppress PHP's built-in inline output.
        });

        // Shutdown: catch fatals that set_error_handler() cannot intercept.
        // The response is already committed here — log only, no header/output.
        register_shutdown_function(static function (): void {
            $last = error_get_last();
            if ($last !== null && ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
                $label = self::label($last['type']);
                error_log("PHP {$label}: {$last['message']} in {$last['file']} on line {$last['line']}");
            }
        });
    }

    /**
     * ob_start() callback — called with the full buffered output right before
     * it is sent to the client. Injects a <script> block before </body> for
     * HTML responses; returns other responses unchanged.
     */
    public static function injectConsoleScript(string $output): string
    {
        if (empty(self::$collected)) {
            return $output;
        }

        // Only inject into HTML — presence of </body> is the reliable signal.
        $bodyPos = strripos($output, '</body>');
        if ($bodyPos === false) {
            return $output;
        }

        $lines = '';
        foreach (self::$collected as $msg) {
            $level = match (true) {
                str_contains($msg, '[ERROR]')      => 'error',
                str_contains($msg, '[WARNING]'),
                str_contains($msg, '[DEPRECATED]') => 'warn',
                default                            => 'info',
            };
            $jsonMsg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines .= "console.{$level}(" . ($jsonMsg !== false ? $jsonMsg : '""') . ");\n";
        }

        $count  = count(self::$collected);
        $script = "<script>\n"
            . "/* PHP {$count} error(s) — see also X-PHP-Error-N response headers */\n"
            . "console.group('PHP ({$count})');\n"
            . $lines
            . "console.groupEnd();\n"
            . '</script>';

        return substr($output, 0, $bodyPos) . $script . substr($output, $bodyPos);
    }

    public static function isActive(): bool
    {
        return self::$active;
    }

    /** @return list<string> */
    public static function collected(): array
    {
        return self::$collected;
    }

    public static function reset(): void
    {
        self::$collected = [];
        self::$active    = false;
    }

    private static function label(int $type): string
    {
        return match (true) {
            (bool) ($type & (E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR))        => 'ERROR',
            (bool) ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'WARNING',
            (bool) ($type & (E_DEPRECATED | E_USER_DEPRECATED))                               => 'DEPRECATED',
            (bool) ($type & (E_NOTICE | E_USER_NOTICE))                                       => 'NOTICE',
            default                                                                            => 'PHP',
        };
    }
}
