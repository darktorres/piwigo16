<?php

declare(strict_types=1);

namespace Piwigo\Routing;

final readonly class RouteResult
{
    /**
     * @param array<string, mixed> $args
     */
    private function __construct(
        public RouteMatchStatus $status,
        public ?string $handler = null,
        public array $args = [],
    ) {}

    /**
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
