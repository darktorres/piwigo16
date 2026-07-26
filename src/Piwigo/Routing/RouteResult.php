<?php

declare(strict_types=1);

namespace Piwigo\Routing;

final readonly class RouteResult
{
    /**
     * $args' values are genuinely arbitrary by design -- Router::dispatch()
     * builds this straight from Symfony UrlMatcher::match()'s own
     * vendor-declared array<string, mixed> return (route param values,
     * which route config can default to any type, not just strings); every
     * real consumer reads its own params defensively.
     *
     * @param array<string, mixed> $args
     */
    private function __construct(
        public RouteMatchStatus $status,
        public ?string $handler = null,
        public array $args = [],
    ) {}

    /**
     * $args' values are genuinely arbitrary by design -- Router::dispatch()
     * builds this straight from Symfony UrlMatcher::match()'s own
     * vendor-declared array<string, mixed> return (route param values,
     * which route config can default to any type, not just strings); every
     * real consumer reads its own params defensively.
     *
     * @param array<string, mixed> $args
     */
    public static function found(string $handler, array $args): self
    {
        return new self(RouteMatchStatus::Found, $handler, $args);
    }

    public static function notFound(): self
    {
        return new self(RouteMatchStatus::NotFound);
    }

    public static function methodNotAllowed(): self
    {
        return new self(RouteMatchStatus::MethodNotAllowed);
    }
}
