<?php

declare(strict_types=1);

namespace Piwigo\Routing;

/**
 * Immutable result of a Router::dispatch() call.
 */
final readonly class RouteResult
{
    public const int FOUND              = 0;
    public const int NOT_FOUND          = 1;
    public const int METHOD_NOT_ALLOWED = 2;

    /**
     * @param array<string, string> $args  Route parameters extracted from the URL.
     */
    public function __construct(
        public int    $status,
        public string $handler = '',
        public array  $args    = [],
    ) {
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
