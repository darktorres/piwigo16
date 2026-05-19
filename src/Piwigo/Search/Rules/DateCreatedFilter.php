<?php

declare(strict_types=1);

namespace Piwigo\Search\Rules;

/**
 * `date_created` saved-search filter — preset window (last 7d /
 * 30d / 3m / 6m / 12m) OR custom selection of year/month/day codes.
 * Same shape as DatePostedFilter; carried as a distinct VO so each
 * filter's "active?" flag is independent.
 */
final readonly class DateCreatedFilter
{
    /** @param list<string> $customCodes */
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
