<?php

declare(strict_types=1);

namespace Piwigo\Search\Projection;

/**
 * `$search['fields']['date_posted']`/`date_created'`. `$preset` stays a
 * plain `string`, not nullable -- both real producers can legitimately
 * write `''` as a "no preset chosen yet" placeholder (see
 * {@see \Piwigo\Controller\SearchController}'s own seed value), and
 * every real consumer already treats `''` and "absent" as the same
 * "not really active" outcome. `$custom`'s own elements are coerced to
 * `string` here (an int entry only ever came from a hand-edited/legacy
 * row) -- {@see \Piwigo\Search\SearchService::dateFilterClause()} did
 * the identical int->string coercion inline before this class existed.
 */
final class DateRule
{
    /**
     * @param list<string> $custom
     */
    public function __construct(
        public string $preset = '',
        public array $custom = [],
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $preset = $row['preset'] ?? null;
        $custom = $row['custom'] ?? null;

        $normalizedCustom = [];
        if (is_array($custom)) {
            foreach ($custom as $v) {
                if (is_string($v)) {
                    $normalizedCustom[] = $v;
                } elseif (is_int($v)) {
                    $normalizedCustom[] = (string) $v;
                }
            }
        }

        return new self(
            preset: is_string($preset) ? $preset : '',
            custom: $normalizedCustom,
        );
    }

    /**
     * @return array{preset: string, custom: list<string>}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'custom' => $this->custom,
        ];
    }
}
