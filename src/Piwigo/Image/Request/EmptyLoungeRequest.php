<?php

declare(strict_types=1);

namespace Piwigo\Image\Request;

/**
 * Validated `$_REQUEST['method']` shape for ImageService::emptyLounge().
 * Read-only debug-log context there (the requested method name, if any,
 * appended to that method's own log lines) with no business-logic
 * branching, so
 * it's constructed inside emptyLounge() itself rather than threaded
 * through its own several call sites.
 *
 * Also reused by LoungeMaintenance::needsEmptying() -- same
 * `$_REQUEST['method']` shape, this second caller just branches on it
 * (skips the automatic-emptying check during an in-progress
 * pwg.images.upload/uploadAsync request) instead of only logging it.
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
