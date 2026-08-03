<?php

declare(strict_types=1);

namespace Piwigo\Filter\Request;

/**
 * Validated `$_GET['filter']` shape for FilterService::initializeFromRequest()
 * -- P26/SEC-40 Request DTO. `filterPresent` stays separate from
 * `filterValue`: the original branches on mere presence of
 * `$_GET['filter']` (falling back to the session-stored filter state
 * when absent), independent of whether the value is actually a string.
 */
final readonly class RecentFilterRequest
{
    private function __construct(
        public bool $filterPresent,
        public ?string $filterValue,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<int|string, mixed> $get
     */
    public static function fromArray(array $get): self
    {
        $filter_raw = $get['filter'] ?? null;

        return new self(isset($get['filter']), is_string($filter_raw) ? $filter_raw : null);
    }
}
