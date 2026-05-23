<?php

declare(strict_types=1);

namespace Piwigo\Http;

/**
 * Single source of truth for Piwigo's security response headers.
 *
 * Consumed two ways:
 *   - `SecurityHeadersMiddleware` — applies them to PSR-7 responses on the
 *     main pipeline.
 *   - `emitDirect()` — emits them via PHP's `header()` for the fast-path
 *     branches in `index.php` (install, upgrade, upgrade_feed, i/) that
 *     short-circuit before the pipeline runs.
 *
 * See `docs/SECURITY.md` "Response headers reference" for the header
 * shapes and rationale.
 */
final class SecurityHeaders
{
    public const string CSP = "default-src 'self'; "
        . "img-src 'self' data: blob:; "
        . "style-src 'self'; "
        . "style-src-elem 'self'; "
        . "style-src-attr 'unsafe-inline'; "
        . "script-src 'self'; "
        . "frame-ancestors 'self'; "
        . "form-action 'self'";

    /** @return array<string,string> */
    public static function headerMap(): array
    {
        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Frame-Options'         => 'SAMEORIGIN',
            'X-Content-Type-Options'  => 'nosniff',
            'Referrer-Policy'         => 'strict-origin-when-cross-origin',
            'Permissions-Policy'      => 'geolocation=(), microphone=(), camera=()',
        ];
        if (RequestScheme::isHttps()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        return $headers;
    }

    /**
     * Emit the security headers via PHP's `header()` builtin. Safe to call
     * before any output; a no-op if headers have already been sent (e.g.
     * because an earlier handler started streaming the body).
     */
    public static function emitDirect(): void
    {
        if (headers_sent()) {
            return;
        }
        foreach (self::headerMap() as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }
}
