<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Carries a fully-built ResponseInterface up the call stack to one of the
 * 3 dispatch-context catch points (include/common.inc.php's bootstrap
 * phase, Middleware\ControllerInvokerMiddleware for the pipeline-routed
 * controller family, Admin\AdminShell::run() for the admin dispatch
 * context) instead of terminating the process directly via
 * header()+echo+exit()/die().
 *
 * A function that always throws satisfies a `: never` return type exactly
 * like one that always exit()s.
 *
 * Catching this earlier than a genuine app error is essential: a
 * redirect/403/404 is expected control flow, not a bug, so it must never
 * reach ExceptionHandlerMiddleware's Sentry-capture-as-exception path.
 * Catching it at ControllerInvokerMiddleware (the innermost/terminal
 * middleware) rather than deeper inside a controller means every outer
 * middleware (SentryMiddleware's own `finally`, ServerTimingMiddleware's
 * post-processing, SessionMiddleware's persist, SecurityHeadersMiddleware)
 * sees a normal Response return value and unwinds exactly as if the
 * controller had returned it directly. PHP's exit()/die() skip pending
 * `finally` blocks entirely, so throwing here rather than terminating the
 * process directly keeps SentryMiddleware's performance transaction and
 * the Server-Timing header intact for every redirect/error page.
 */
final class ResponseReadyException extends RuntimeException
{
    public function __construct(
        private readonly ResponseInterface $response,
    ) {
        parent::__construct('A response was constructed but not yet emitted -- this exception must be caught by one of the 3 dispatch-context catch points, never allowed to reach a generic error handler.');
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }
}
