<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * json()/text()/html() -- the shapes real callers need. No redirect() yet;
 * legacy redirect()/access_denied() (include/functions.inc.php) still exit
 * directly rather than returning a Response (P22's own accepted
 * limitation, see docs/plan/manifest.yaml's P22 entry) -- add redirect()
 * here once a real non-exiting RedirectResponse replaces them.
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
