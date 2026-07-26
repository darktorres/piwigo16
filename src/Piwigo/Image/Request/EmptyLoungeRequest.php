<?php

declare(strict_types=1);

namespace Piwigo\Image\Request;

/**
 * Validated `$_REQUEST['method']` shape for ImageService::emptyLounge()
 * -- P27/SEC-40 Request DTO. Read-only debug-log context (the WS method
 * name, if any, appended to this method's own log lines) with no
 * business-logic branching, so this is constructed inside emptyLounge()
 * itself rather than threaded through its own several call sites.
 */
final readonly class EmptyLoungeRequest
{
    private function __construct(
        public ?string $requestMethod,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_REQUEST);
    }

    /**
     * @param array<int|string, mixed> $request
     */
    public static function fromArray(array $request): self
    {
        $method_raw = $request['method'] ?? null;

        return new self(is_string($method_raw) ? $method_raw : null);
    }
}
