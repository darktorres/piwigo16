<?php

declare(strict_types=1);

namespace Piwigo\Ws\Request;

/**
 * Validated `$_GET['format']` for `WsInitializer::init()`.
 * Defaults to `'rest'` when absent, matching the request
 * format (this app only ever registers a REST request handler). No
 * pattern validation needed: `WsInitializer::init()`'s own switch
 * already leaves the encoder null for any unrecognized format before
 * passing it to `PwgServer::setEncoder()`.
 */
final readonly class WsFormatRequest
{
    private function __construct(
        public string $responseFormat,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        $format = $source['format'] ?? null;
        if ($format === null) {
            return new self('rest');
        }

        // $_GET['format'] could be an array for a malformed ?format[]=x
        // request -- PwgServer::setEncoder() requires a string.
        return new self(is_string($format) ? $format : '');
    }
}
