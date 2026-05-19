<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `date_posted` saved-search filter — preset window (last 24h /
 * 7d / 30d / 3m / 6m) OR a custom selection of year/month/day codes
 * (`y2024` / `m2024-03` / `d2024-03-15`).
 */
final readonly class DatePostedFilter
{
    /** @param list<string> $customCodes  year/month/day codes when preset === Custom */
    public function __construct(
        public DatePresetCode $preset,
        public array $customCodes,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    public static function fromArray(array $raw): ?self
    {
        $preset = DatePresetCode::tryParse($raw['preset'] ?? null);
        if ($preset === null) {
            return null;
        }
        $custom = [];
        if ($preset === DatePresetCode::Custom) {
            $customRaw = $raw['custom'] ?? null;
            if (is_array($customRaw)) {
                $custom = array_values(array_filter(
                    array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $customRaw),
                    static fn (string $c): bool => $c !== '',
                ));
            }
        }
        return new self($preset, $custom);
    }
}
