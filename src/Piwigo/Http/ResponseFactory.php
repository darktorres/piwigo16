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
     * $data's values are genuinely arbitrary by design -- fed straight into
     * json_encode(mixed $value), same category as ResponseEncoder's own
     * generic serialization boundary.
     *
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
     * Just a Location header + empty body -- Request-URI/Content-Location
     * headers are pointless for a redirect response and are not included.
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

    /**
     * A genuinely empty body -- the `/api/v1` surface's shape for a
     * mutation with no data worth confirming beyond the 2xx status
     * itself.
     */
    public static function noContent(): ResponseInterface
    {
        return new Response(204);
    }

    /**
     * RFC 9457 `application/problem+json` -- the `/api/v1` surface's error
     * shape. `type` defaults to the spec's own "no more specific URI"
     * placeholder; pass a real one once a problem type is documented.
     */
    public static function problem(string $title, int $status, ?string $detail = null, string $type = 'about:blank'): ResponseInterface
    {
        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
        ];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new Response(
            $status,
            [
                'Content-Type' => 'application/problem+json',
            ],
            json_encode($body, JSON_THROW_ON_ERROR)
        );
    }
}
