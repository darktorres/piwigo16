<?php

declare(strict_types=1);

namespace Piwigo\Notification\Projection;

/** (uppercats, img_count) row from NotificationRepository::findRecentCategoriesForDate(). */
final readonly class RecentCategoryRow
{
    public function __construct(
        public string $uppercats,
        public int $imgCount,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            imgCount:  is_numeric($row['img_count'] ?? null) ? (int) $row['img_count'] : 0,
        );
    }
}
