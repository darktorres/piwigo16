<?php

declare(strict_types=1);

namespace Piwigo\Core\Request;

/**
 * Validated `$_GET['mobile']` shape for DeviceHelper::mobileTheme().
 *
 * `mobileRaw` is forwarded to `SqlDialect::getBoolean(mixed $input):
 * bool`'s own by-design generic coercion signature (same cluster as
 * this project's already-reviewed `emptyValue(mixed $value): bool`
 * helpers) untouched -- narrowing to a scalar here first would silently
 * change behavior for a malformed `?mobile[]=1` array value (`(bool)
 * $array` is `true` for any non-empty array, `false` for a narrowed-to-
 * null replacement), which this DTO has no business deciding on
 * getBoolean()'s behalf. `array<array-key, mixed>|string|null` (not a
 * bare `mixed`) since that's the real, exhaustive shape a `$_GET` leaf
 * can ever take -- PHP's own query-string parser never produces a raw
 * bool/int/float there.
 */
final readonly class MobileThemeRequest
{
    /**
     * @param array<array-key, mixed>|string|null $mobileRaw
     */
    private function __construct(
        public bool $mobilePresent,
        public array|string|null $mobileRaw,
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
        $raw = $get['mobile'] ?? null;
        $mobileRaw = is_string($raw) || is_array($raw) ? $raw : null;

        return new self(isset($get['mobile']), $mobileRaw);
    }
}
