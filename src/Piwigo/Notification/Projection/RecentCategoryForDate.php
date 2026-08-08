<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/**
 * {@see \Piwigo\Notification\NotificationRepository::findRecentCategoriesForDate()}'s
 * own row shape -- {@see \Piwigo\Notification\NotificationService::
 * getRecentPostDates()}'s real (and only) consumer, spliced onto a
 * {@see RecentPostDate} row's `categories` key.
 *
 * `toArray()` exists for that same cache-boundary reason
 * {@see RecentPostDate} documents.
 */
final readonly class RecentCategoryForDate
{
    public function __construct(
        public string $uppercats,
        public int $imgCount,
    ) {}

    /**
     * @return array{uppercats: string, img_count: int}
     */
    public function toArray(): array
    {
        return [
            'uppercats' => $this->uppercats,
            'img_count' => $this->imgCount,
        ];
    }
}
