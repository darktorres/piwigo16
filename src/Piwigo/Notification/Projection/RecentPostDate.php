<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/**
 * {@see \Piwigo\Notification\NotificationRepository::findRecentPostDates()}'s
 * own row shape -- {@see \Piwigo\Notification\NotificationService::
 * getRecentPostDates()}'s real (and only) consumer, its "what's new"
 * digest.
 *
 * `toArray()` preserves the exact original snake_case shape: that consumer
 * splices `elements`/`categories` keys onto each row before caching the
 * whole result as a plain array (a PSR-6 cache pool boundary genuinely
 * needing a flat, mutable-shaped array), so it `toArray()`s each DTO
 * before extending it, same reasoning as every other DTO in this codebase
 * that keeps a `toArray()` unwrap at its actual serialization boundary.
 */
final readonly class RecentPostDate
{
    public function __construct(
        public ?string $dateAvailable,
        public int $nbElements,
        public int $nbCats,
    ) {}

    /**
     * @return array{date_available: ?string, nb_elements: int, nb_cats: int}
     */
    public function toArray(): array
    {
        return [
            'date_available' => $this->dateAvailable,
            'nb_elements' => $this->nbElements,
            'nb_cats' => $this->nbCats,
        ];
    }
}
