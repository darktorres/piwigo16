<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Legacy Coupling Retirement Phase 4b: the former `redirect()`/
 * `redirect_html()`/`redirect_http()` free functions
 * (`Piwigo\Http\functions.php`, now deleted). `redirectHtml()`'s own body
 * calls `Piwigo\Bootstrap\PageTail::renderToString()` (L4Integration, the
 * top layer), but real callers span every layer from L1Infrastructure
 * through L4Integration -- the same shape `TelemetrySenderInterface`
 * already solves. Lives in `Piwigo\Core` (L1Infrastructure, same direction
 * as `MailerInterface`/`HtmlRenderingInterface`/`TelemetrySenderInterface`)
 * so any layer can depend downward on this instead of the concrete class.
 * `Piwigo\Bootstrap\RedirectService implements` it; bound in
 * `config/container.php`.
 *
 * Workstream C3: all 3 methods stay `: never` -- a `throw` satisfies that
 * return type exactly like the former `exit()` did, so real callers need
 * zero changes. `RedirectService`'s own implementation now throws
 * `Piwigo\Http\ResponseReadyException` (carrying a real
 * `ResponseInterface`) instead of calling `header()`/`echo`/`exit()`
 * directly -- caught at one of 3 real dispatch-context catch points (see
 * that exception class's own docblock).
 */
interface RedirectServiceInterface
{
    /**
     * Redirects to the given URL using a raw HTTP Location header, with no
     * HTML fallback page.
     */
    public function redirectHttp(string $url): never;

    /**
     * Redirects to the given URL by rendering an HTML page with a
     * meta-refresh/link fallback (used when headers are already sent, or
     * $refresh_time is non-zero). $status lets HtmlService's
     * badRequest()/pageNotFound()/pageForbidden() thread their own real
     * status code through instead of the emitted Response always reporting
     * 200 (Workstream C3).
     */
    public function redirectHtml(string $url, string $msg = '', int $refresh_time = 0, int $status = 200): never;

    /**
     * Redirects to the given URL, automatically choosing the HTTP or HTML
     * method based on config, $refresh_time, and whether headers were
     * already sent.
     */
    public function redirect(string $url, string $msg = '', int $refresh_time = 0): never;
}
