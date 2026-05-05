<?php

declare(strict_types=1);

namespace Piwigo\Routing;

/**
 * Immutable result of a Router::dispatch() call.
 */
final class RouteResult
{
    public const FOUND              = 0;
    public const NOT_FOUND          = 1;
    public const METHOD_NOT_ALLOWED = 2;

    /**
     * @param array<string, string> $args  Route parameters extracted from the URL.
     */
    public function __construct(
        public readonly int    $status,
        public readonly string $handler = '',
        public readonly array  $args    = [],
    ) {
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
