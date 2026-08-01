<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Html;

use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseReadyException;

/**
 * A single, named, class-method call site for fatalError()'s own
 * debug_backtrace() loop -- gives frame #1 of the real backtrace a
 * predictable class+function ("HtmlServiceTestFatalErrorCaller::call").
 * debug_backtrace() frame N's file/line is the CALL SITE of frame N's
 * own function, not where that function is defined -- so frame #1's
 * file/line point at wherever the caller invokes call() from, not at
 * this file. That part stays the caller's own responsibility to record
 * (see HtmlServiceTest.php's own use of this class).
 */
final class HtmlServiceTestFatalErrorCaller
{
    /**
     * The exact message trigger_error() was called with -- captures
     * $errstr, the custom handler's own 2nd argument.
     */
    public ?string $capturedErrorMessage = null;

    /**
     * count(debug_backtrace()) taken one line above the fatalError()
     * call below -- since debug_backtrace() never counts its own
     * enclosing function's frame, fatalError()'s own debug_backtrace()
     * (called one function level deeper) sees exactly this count + 1
     * frames, at valid indices 0..preCallDepth. Lets a test assert the
     * real loop boundary (valid frame numbers 1..preCallDepth) without
     * hardcoding a specific call-stack depth that depends on exactly
     * how many frames the test runner itself adds above this call.
     */
    public int $preCallDepth = 0;

    /**
     * The real HTTP status code fatalError() built its response with.
     */
    public ?int $capturedStatusCode = null;

    public function call(HtmlService $service, string $msg, ?string $title, bool $showTrace): string
    {
        // trigger_error(E_USER_ERROR) is deprecated-but-still-fires as of
        // PHP 8.4 with no custom handler installed; production installs
        // one via error_collector.inc.php (see fatalError()'s own
        // docblock) that intercepts and returns true, letting execution
        // continue to the real `throw` below -- this mirrors that here.
        // No $error_types filter (matches ErrorCollectorTest.php's own
        // established pattern): passing E_USER_ERROR to trigger_error()
        // itself raises a separate E_DEPRECATED notice under PHP 8.4+
        // that a level-filtered handler wouldn't also catch.
        $this->capturedErrorMessage = null;
        set_error_handler(function (int $errno, string $errstr): bool {
            if ($errno === \E_USER_ERROR) {
                $this->capturedErrorMessage = $errstr;
            }

            return true;
        });
        // fatalError() is `never`-typed and always throws
        // ResponseReadyException -- no fallback return needed after the
        // try/catch (matches HtmlServiceTest.php's own established
        // precedent for other `never`-typed methods).
        try {
            $this->preCallDepth = count(debug_backtrace());
            $service->fatalError($msg, $title, $showTrace);
        } catch (ResponseReadyException $e) {
            $this->capturedStatusCode = $e->response()->getStatusCode();

            return (string) $e->response()->getBody();
        } finally {
            restore_error_handler();
        }
    }
}
