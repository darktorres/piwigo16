<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * json()/text()/html()/redirect() -- the shapes real callers need.
 */
final class ResponseFactory
{
    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): ResponseInterface
    {
        return new Response(
            $status,
            [
                'Content-Type' => 'application/json',
            ],
            json_encode($data, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Workstream C3: fills the gap this class's own docblock used to name
     * ("No redirect() yet ... add redirect() here once a real non-exiting
     * RedirectResponse replaces [redirect_http()/redirect_html()]"). Just
     * a Location header + empty body -- the Request-URI/Content-Location
     * headers the legacy redirect_http() also sent were already identified
     * as pointless and dropped elsewhere (SEC-35's ImageDerivativeController
     * fix), not carried forward here either.
     */
    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new Response($status, [
            'Location' => $url,
        ]);
    }

    public static function text(string $body, int $status = 200): ResponseInterface
    {
        return new Response($status, [
            'Content-Type' => 'text/plain',
        ], $body);
    }

    /**
     * P22 frontend controllers' own shape: legacy Smarty rendering still
     * echoes directly (Template::pparse(), page_header.php/page_tail.php),
     * captured into a string via Piwigo\Controller\LegacyRenderCapture
     * rather than retrofitting every P17-20 renderer to return one.
     */
    public static function html(string $body, int $status = 200): ResponseInterface
    {
        return new Response($status, [
            'Content-Type' => 'text/html; charset=utf-8',
        ], $body);
    }

    /**
     * Escape hatch for responses whose Content-Type/other headers don't fit
     * json()/text()/html()'s fixed shapes (e.g. FeedController's dynamic
     * `application/rss+xml; charset=...; filename=...`).
     *
     * @param array<string, string> $headers
     */
    public static function raw(string $body, array $headers, int $status = 200): ResponseInterface
    {
        return new Response($status, $headers, $body);
    }
}
