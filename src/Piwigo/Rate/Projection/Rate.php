<?php

declare(strict_types=1);

namespace Piwigo\Rate\Projection;

/**
 * Typed row shape for `piwigo_rate` (P17-23 Stage 1b, Rate domain --
 * `docs/PLAN.md`'s own "7 Entity types, 73 projection shapes"
 * reference). `fromRow()` centralises the `is_string($row['x']) ? ... :
 * default` narrowing {@see \Piwigo\Rate\RateRepository}'s own private
 * `toRateRow()` array-shape helper used to provide, same shape as
 * {@see \Piwigo\Category\Projection\Category}.
 *
 * `date` stays `?string`, not `\DateTimeImmutable` -- Rate domain Stage 1a
 * dropped its 1970-01-01 sentinel default (every real insert already sets
 * it explicitly via `RateRepository::insertRate()`), and every real
 * consumer already expects the raw DB DATE string form, same reasoning as
 * {@see \Piwigo\Image\Projection\Image}.
 */
final readonly class Rate
{
    public function __construct(
        public int $userId,
        public int $elementId,
        public string $anonymousId,
        public int $rate,
        public ?string $date,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_rate`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            userId: is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
            elementId: is_numeric($row['element_id'] ?? null) ? (int) $row['element_id'] : 0,
            anonymousId: is_string($row['anonymous_id'] ?? null) ? $row['anonymous_id'] : '',
            rate: is_numeric($row['rate'] ?? null) ? (int) $row['rate'] : 0,
            date: is_string($row['date'] ?? null) ? $row['date'] : null,
        );
    }

    /**
     * @return array{user_id: int, element_id: int, anonymous_id: string, rate: int, date: ?string}
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'element_id' => $this->elementId,
            'anonymous_id' => $this->anonymousId,
            'rate' => $this->rate,
            'date' => $this->date,
        ];
    }
}
