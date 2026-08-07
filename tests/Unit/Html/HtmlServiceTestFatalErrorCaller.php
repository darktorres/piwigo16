<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Html;

use LogicException;
use Piwigo\Core\ErrorCollector;
use Piwigo\Core\Kernel;
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
     * fatalError()'s $this->errorCollector->recordFatal() message,
     * captured via the container-shared instance's collected() -- the
     * recording path it uses instead of trigger_error(E_USER_ERROR)
     * (deprecated as of PHP 8.4). Called unconditionally by fatalError()
     * regardless of isActive(), so no install()/Reflection state setup is
     * needed here. Resolved from the container (not a fresh `new
     * ErrorCollector()`) so this sees the exact same instance
     * HtmlServiceTestFactory::build() resolved as one of HtmlService's
     * required constructor collaborators -- HtmlServiceTest.php's own
     * beforeEach() already boots Kernel.
     */
    public ?string $capturedErrorMessage = null;

    private function errorCollector(): ErrorCollector
    {
        $errorCollector = Kernel::container()->get(ErrorCollector::class);
        if (! $errorCollector instanceof ErrorCollector) {
            throw new LogicException('Container returned an unexpected type for ' . ErrorCollector::class);
        }

        return $errorCollector;
    }

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
        $this->capturedErrorMessage = null;
        $this->errorCollector()->drain();
        // fatalError() is `never`-typed and always throws
        // ResponseReadyException -- no fallback return needed after the
        // try/catch (matches HtmlServiceTest.php's own established
        // precedent for other `never`-typed methods).
        try {
            $this->preCallDepth = count(debug_backtrace());
            $service->fatalError($msg, $title, $showTrace);
        } catch (ResponseReadyException $e) {
            $collected = $this->errorCollector()->drain();
            $this->capturedErrorMessage = $collected === [] ? null : preg_replace('/^\[ERROR\] /', '', $collected[0]);
            $this->capturedStatusCode = $e->response()->getStatusCode();

            return (string) $e->response()->getBody();
        }
    }
}
