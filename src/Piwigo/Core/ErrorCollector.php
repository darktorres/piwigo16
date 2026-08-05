<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Redirects PHP errors/warnings/deprecations away from the response body and
 * into HTTP response headers instead (X-PHP-Error-N), visible in DevTools ->
 * Network -> Response Headers.
 *
 * P23 sub-batch 8f-5: real class replacing the 7 pwg_error_collector_*()
 * free functions of the deleted include/error_collector.inc.php -- that
 * file's own docblock justified staying procedural with "17.x-rewrite has
 * no src/ PSR-4 autoloading yet", long stale. Bodies ported verbatim
 * (set_error_handler()/register_shutdown_function()/headers_sent()
 * semantics unchanged); the two $pwg_error_collector_* globals became the
 * static properties below.
 *
 * Prevents PHP notices/warnings/deprecations from corrupting JSON, XML, or
 * binary responses (e.g. ws.php) while keeping them inspectable in the
 * browser. Errors still reach error_log() so the Apache error log remains
 * the authoritative server-side record.
 *
 * Install once, early in the bootstrap — see Piwigo\Bootstrap\
 * RequestBootstrap's show_php_errors_on_frontend handling.
 *
 * Container-shared instance (singleton/service-locator elimination
 * campaign, Phase 2): $active/$collected are real per-request state, a
 * genuine SEC-60 worker-mode risk if left static (a leftover $collected
 * entry from one request would otherwise resurface as an X-PHP-Error-N
 * header on the next request sharing the same worker process).
 * handleError()/flush() are the only two methods that read/write that
 * state, so only those became instance methods -- label()/
 * writeTestErrorsLog() are pure functions of their own parameters (no
 * $this access) and stay `private static`, unchanged.
 */
final class ErrorCollector
{
    private bool $active = false;

    /**
     * @var list<string>
     */
    private array $collected = [];

    public function __construct(
        private readonly \Piwigo\Config\DeploymentPolicy $deploymentPolicy,
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
     * fatalError()-reachable call graph -- originally only
     * Bootstrap\RequestBootstrap::connect() (the main HTTP pipeline);
     * Bootstrap\InstallBootstrap::boot() (install.php/upgrade.php/
     * upgrade_feed.php) needs the identical sequence for the exact same
     * reason (see HtmlService::fatalError()'s own comment: without this
     * installed, its trigger_error(E_USER_ERROR) hard-halts the script
     * before ever reaching the ResponseReadyException throw that follows
     * it) -- confirmed live, a real install.php 500 rather than the
     * intended clean error page.
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
     * TestErrorsController), which Integration tests poll via
     * IntegrationTestCase::assertNoPhpErrors() after exercising a real HTTP
     * request. Distinct from collected() (a non-destructive peek with no
     * real caller yet) so a test can drain exactly the errors its own
     * request produced without earlier requests' errors leaking forward.
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
        self::writeTestErrorsLog($entry);

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
    private static function writeTestErrorsLog(string $entry): void
    {
        if (! Env::testModeIsActive()) {
            return;
        }

        $path = CurrentPaths::get()->logs . 'test_errors.log';
        $written = @file_put_contents($path, $entry . "\n", FILE_APPEND);
        if ($written === false) {
            // _data/logs/ isn't guaranteed to exist yet -- Monolog's own
            // RotatingFileHandler (piwigo.log) self-heals via its own
            // createDir(), but this bare file_put_contents() doesn't; a
            // fresh checkout with no prior HTTP traffic hits this on the
            // very first test-mode error.
            FilesystemHelper::mkgetdir(dirname($path), FilesystemHelper::MKGETDIR_RECURSIVE);
            file_put_contents($path, $entry . "\n", FILE_APPEND);
        }
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
            self::writeTestErrorsLog($entry);
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
