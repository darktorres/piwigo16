<?php

declare(strict_types=1);

namespace Piwigo\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * Convenience factory for common PSR-7 response shapes.
 */
final class ResponseFactory
{
    private static ?Psr17Factory $factory = null;

    private static function factory(): Psr17Factory
    {
        return self::$factory ??= new Psr17Factory();
    }

    public static function create(int $status = 200, string $body = ''): ResponseInterface
    {
        $f        = self::factory();
        $response = $f->createResponse($status);
        if ($body !== '') {
            $response = $response->withBody($f->createStream($body));
        }
        return $response;
    }

    public static function html(string $body, int $status = 200): ResponseInterface
    {
        return self::create($status, $body)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function json(mixed $data, int $status = 200): ResponseInterface
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return self::create($status, is_string($encoded) ? $encoded : '{}')
            ->withHeader('Content-Type', 'application/json');
    }

    public static function redirect(string $url, int $status = 302): ResponseInterface
    {
        return self::create($status)
            ->withHeader('Location', $url);
    }
}
