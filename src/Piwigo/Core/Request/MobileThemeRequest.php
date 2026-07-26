<?php

declare(strict_types=1);

namespace Piwigo\Core\Request;

/**
 * Validated `$_GET['mobile']` shape for DeviceHelper::mobileTheme() --
 * P27/SEC-40 Request DTO.
 *
 * `mobileRaw` stays `mixed`, matching `SqlDialect::getBoolean(mixed
 * $input): bool`'s own by-design generic coercion signature (same
 * cluster as this project's already-reviewed `emptyValue(mixed $value):
 * bool` helpers) -- narrowing to a scalar here first would silently
 * change behavior for a malformed `?mobile[]=1` array value (`(bool)
 * $array` is `true` for any non-empty array, `false` for a narrowed-to-
 * null replacement), which this DTO has no business deciding on
 * getBoolean()'s behalf.
 */
final readonly class MobileThemeRequest
{
    private function __construct(
        public bool $mobilePresent,
        public mixed $mobileRaw,
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
        return new self(isset($get['mobile']), $get['mobile'] ?? null);
    }
}
