<?php

declare(strict_types=1);

namespace Piwigo\Session\Projection;

/**
 * {@see \Piwigo\Session\SessionService::getFilterCheckKey()}'s own fixed
 * `user`/`recent_period`/`time`/`date` shape -- {@see
 * \Piwigo\Filter\FilterService::initializeFromRequest()}'s cache-validity
 * marker, the only real producer/consumer of this session value.
 *
 * `fromArray()` applies the exact same per-field `is_numeric()`/
 * `is_string()` narrowing `FilterService` used to duplicate at each of its
 * own read sites (a corrupted/foreign session value's wrong-typed field
 * coerces to the same "definitely stale" fallback FilterService's own
 * downstream comparisons already treated it as, not a new behavior).
 */
final readonly class FilterCheckKey
{
    public function __construct(
        public int $user,
        public int $recentPeriod,
        public int $time,
        public string $date,
    ) {}

    /**
     * @param array<array-key, mixed> $value
     */
    public static function fromArray(array $value): ?self
    {
        if (! isset($value['user'], $value['recent_period'], $value['time'], $value['date'])) {
            return null;
        }

        return new self(
            user: is_numeric($value['user']) ? (int) $value['user'] : 0,
            recentPeriod: is_numeric($value['recent_period']) ? (int) $value['recent_period'] : 0,
            time: is_numeric($value['time']) ? (int) $value['time'] : 0,
            date: is_string($value['date']) ? $value['date'] : '',
        );
    }

    /**
     * @return array{user: int, recent_period: int, time: int, date: string}
     */
    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'recent_period' => $this->recentPeriod,
            'time' => $this->time,
            'date' => $this->date,
        ];
    }
}
