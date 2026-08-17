<?php

declare(strict_types=1);

namespace Piwigo\Core;

use LogicException;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;

/**
 * Redirects PHP errors/warnings/deprecations away from the response body and
 * into HTTP response headers instead (X-PHP-Error-N), visible in DevTools ->
 * Network -> Response Headers.
 *
 * Prevents PHP notices/warnings/deprecations from corrupting JSON, XML, or
 * binary responses while keeping them inspectable in the browser. Errors
 * still reach error_log() so the Apache error log remains
 * the authoritative server-side record.
 *
 * Install once, early in the bootstrap — see Piwigo\Bootstrap\
 * RequestBootstrap's show_php_errors_on_frontend handling.
 *
 * Container-shared instance: $active/$collected are per-request instance
 * state, not static -- left static, a leftover $collected entry from one
 * request would resurface as an X-PHP-Error-N header on the next request
 * sharing the same worker process. handleError()/flush() are the only two
 * methods that read/write that state and so are instance methods;
 * label()/writeTestErrorsLog() are pure functions of their own parameters
 * (no $this access) and stay `private static`.
 */
final class ErrorCollector
{
    private bool $active = false;

    /**
     * @var list<string>
     */
    private array $collected = [];

    public function __construct(
        private readonly DeploymentPolicy $deploymentPolicy,
        private readonly Paths $paths,
    ) {}

    public function install(): void
    {
        if ($this->active) {
            return;
        }
        $this->active = true;
        $this->collected = [];

        // Never write errors inline — they corrupt non-HTML responses.
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');

        set_error_handler($this->handleError(...));
        register_shutdown_function($this->flush(...));
    }

    /**
     * Installs (per the same show_php_errors/show_php_errors_on_frontend
     * deployment-policy gate) from every bootstrap that has its own
     * fatalError()-reachable call graph: `Bootstrap\RequestBootstrap::
     * connect()` (the main HTTP pipeline) and `Bootstrap\InstallBootstrap::
     * boot()` (install.php/upgrade.php/upgrade_feed.php) both need it --
     * without it, HtmlService::fatalError()'s trigger_error(E_USER_ERROR)
     * hard-halts the script before ever reaching the ResponseReadyException
     * throw that follows it.
     */
    public function installIfConfigured(): void
    {
        $policy = $this->deploymentPolicy;
        if ($policy->showPhpErrors !== 0) {
            @ini_set('error_reporting', $policy->showPhpErrors);
            if ($policy->showPhpErrorsOnFrontend) {
                $this->install();
            }
        }
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * @return list<string>
     */
    public function collected(): array
    {
        return $this->collected;
    }

    /**
     * Returns the collected buffer, then clears it -- the read side of the
     * `GET /__test/errors` test-mode route (see Piwigo\Controller\
     * TestErrorsController), exercised directly by
     * tests/Browser/TestErrorsControllerTest.php. Distinct from collected()
     * (a non-destructive peek with no real caller yet) so a test can drain
     * exactly the errors its own request produced without earlier requests'
     * errors leaking forward.
     *
     * @return list<string>
     */
    public function drain(): array
    {
        $collected = $this->collected;
        $this->collected = [];

        return $collected;
    }

    public function reset(): void
    {
        $this->active = false;
        $this->collected = [];
    }

    /**
     * Records a fatal application-level error directly, bypassing
     * trigger_error() + set_error_handler() interception entirely -- PHP
     * 8.4 deprecates passing E_USER_ERROR to trigger_error(), which is
     * the mechanism HtmlService::fatalError() used to rely on to let its
     * own throw continue running past a "fatal" signal instead of PHP
     * hard-halting the script right there. HtmlService::fatalError() is
     * this method's only real caller, and calls it unconditionally
     * (regardless of isActive()) -- when inactive, this just accumulates
     * into $collected harmlessly (nothing ever flush()es it without
     * install() having registered the shutdown hook) and still reaches
     * error_log(), matching handleError()'s own "Apache error log is the
     * authoritative record" reasoning either way.
     *
     * Doesn't call writeTestErrorsLog() (unlike handleError()): that log
     * is a belt-and-suspenders fallback specifically for errors whose
     * response never reaches the client (a hard PHP fatal, an exit()).
     * fatalError()'s own caller always gets back a real 500 response
     * instead, so the normal collected()/flush()-header path this method
     * already feeds is never bypassed here.
     */
    public function recordFatal(string $message): void
    {
        $this->collected[] = '[ERROR] ' . $message;
        error_log('PHP Fatal error: ' . $message);
    }

    /**
     * @internal registered via set_error_handler() — trailing params are
     * optional to match the callable(int, string, string=, int=, array=):bool
     * signature set_error_handler() expects.
     * @param array<array-key, mixed> $errcontext unused, kept for signature parity
     */
    private function handleError(int $errno, string $errstr, string $errfile = '', int $errline = 0, array $errcontext = []): bool
    {
        // Respect the @ error-suppression operator.
        if (! (bool) (error_reporting() & $errno)) {
            return false;
        }

        $label = self::label($errno);
        $short = basename($errfile) . ':' . $errline;
        $entry = "[{$label}] {$errstr} in {$short}";
        $this->collected[] = $entry;

        // Keep the Apache error log as the authoritative server-side record.
        error_log("PHP {$label}: {$errstr} in {$errfile} on line {$errline}");
        self::writeTestErrorsLog($entry, $this->paths);

        return true; // Suppress PHP's built-in inline output.
    }

    /**
     * Belt-and-suspenders record of every collected error, independent of
     * the per-request `GET /__test/errors` drain -- covers the case where a
     * fatal error/exit prevents the response (and its X-PHP-Error-N
     * headers) from ever reaching the test client. Test-mode only: this is
     * not a general-purpose log, `_data/logs/piwigo.log` (Monolog) already
     * is one.
     */
    private static function writeTestErrorsLog(string $entry, Paths $paths): void
    {
        if (! Env::testModeIsActive()) {
            return;
        }

        $path = $paths->logs . 'test_errors.log';
        $written = @file_put_contents($path, $entry . "\n", FILE_APPEND);
        if ($written === false) {
            // _data/logs/ isn't guaranteed to exist yet -- Monolog's own
            // RotatingFileHandler (piwigo.log) self-heals via its own
            // createDir(), but this bare file_put_contents() doesn't; a
            // fresh checkout with no prior HTTP traffic hits this on the
            // very first test-mode error.
            FilesystemHelper::mkgetdir(dirname($path), self::currentConfig(), FilesystemHelper::MKGETDIR_RECURSIVE);
            file_put_contents($path, $entry . "\n", FILE_APPEND);
        }
    }

    /**
     * Resolves via the container rather than being carried as a
     * constructor property: writeTestErrorsLog() is deliberately kept a
     * pure, `private static` function of its own params (see this class's
     * own docblock), and this rare fallback mkgetdir() call (only reached
     * on a fresh checkout's very first test-mode error, before
     * _data/logs/ exists) is its sole reason to need CurrentConfig at all
     * -- not worth rippling into this class's own constructor for one
     * defensive path. A fresh, unmemoized CurrentConfig instance is safe
     * here because its constructor performs no DB read.
     */
    private static function currentConfig(): CurrentConfig
    {
        if (Kernel::isBooted()) {
            $instance = Kernel::container()->get(CurrentConfig::class);
            if (! $instance instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }

            return $instance;
        }

        return new CurrentConfig();
    }

    /**
     * @internal registered via register_shutdown_function()
     */
    private function flush(): void
    {
        // Catch fatal errors that set_error_handler() cannot intercept.
        $last = error_get_last();
        if ($last !== null && (bool) ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
            $label = self::label($last['type']);
            $short = basename($last['file']) . ':' . $last['line'];
            $entry = "[{$label}] {$last['message']} in {$short}";
            $this->collected[] = $entry;
            self::writeTestErrorsLog($entry, $this->paths);
        }

        if ($this->collected === [] || headers_sent()) {
            return;
        }

        // Emit one header per error — DevTools shows each on its own line.
        foreach ($this->collected as $i => $msg) {
            // Strip newlines (invalid in header values) and cap length.
            $safe = substr(str_replace(["\r", "\n"], ' ', $msg), 0, 500);
            header('X-PHP-Error-' . ($i + 1) . ': ' . $safe);
        }
        header('X-PHP-Error-Count: ' . count($this->collected));
    }

    private static function label(int $type): string
    {
        return match (true) {
            (bool) ($type & (E_ERROR | E_USER_ERROR | E_CORE_ERROR | E_COMPILE_ERROR)) => 'ERROR',
            (bool) ($type & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'WARNING',
            (bool) ($type & (E_DEPRECATED | E_USER_DEPRECATED)) => 'DEPRECATED',
            (bool) ($type & (E_NOTICE | E_USER_NOTICE)) => 'NOTICE',
            default => 'PHP',
        };
    }
}
