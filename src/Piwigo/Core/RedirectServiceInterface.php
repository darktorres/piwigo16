<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Real callers span every layer from L1Infrastructure through
 * L4Integration -- the same shape `TelemetrySenderInterface` already
 * solves. Lives in `Piwigo\Core` (L1Infrastructure, same direction as
 * `MailerInterface`/`HtmlRenderingInterface`/`TelemetrySenderInterface`)
 * so any layer can depend downward on this instead of the concrete
 * class. `Piwigo\Bootstrap\RedirectService` implements it; bound in
 * `config/container.php`.
 *
 * All 3 methods stay `: never` -- a `throw` satisfies that return type.
 * `RedirectService`'s implementation throws
 * `Piwigo\Http\ResponseReadyException` (carrying a real
 * `ResponseInterface`) instead of calling `header()`/`echo`/`exit()`
 * directly -- caught at one of 3 real dispatch-context catch points (see
 * that exception class's own docblock).
 */
interface RedirectServiceInterface
{
    /**
     * Redirects to the given URL using a raw HTTP Location header, with no
     * HTML fallback page. $status defaults to 302 (temporary); pass 301
     * for a permanent redirect (e.g. a permalink/canonical-URL fix-up).
     */
    public function redirectHttp(string $url, int $status = 302): never;

    /**
     * Redirects to the given URL by rendering an HTML page with a
     * meta-refresh/link fallback (used when headers are already sent, or
     * $refresh_time is non-zero). $status lets HtmlService's
     * badRequest()/pageNotFound()/pageForbidden() thread their own real
     * status code through instead of the emitted Response always reporting
     * 200.
     */
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never;

    /**
     * Redirects to the given URL, automatically choosing the HTTP or HTML
     * method based on config, $refresh_time, and whether headers were
     * already sent.
     */
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never;
}
