<?php

declare(strict_types=1);

namespace Piwigo\Core\Request;

/**
 * Validated `$_REQUEST['method']` shape for LoungeMaintenance::needsEmptying()
 * -- P27/SEC-40 Request DTO.
 */
final readonly class LoungeMaintenanceRequest
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
