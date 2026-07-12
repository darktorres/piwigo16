<?php

declare(strict_types=1);

namespace Piwigo\Telemetry;

/**
 * Aggregate counts only -- no image paths, category names, usernames, or
 * comment content ever cross into this DTO.
 */
final readonly class GalleryStats
{
    public function __construct(
        public int $imageCount,
        public int $categoryCount,
        public int $userCount,
        public int $commentCount,
    ) {}

    /**
     * @return array{image_count: int, category_count: int, user_count: int, comment_count: int}
     */
    public function toArray(): array
    {
        return [
            'image_count' => $this->imageCount,
            'category_count' => $this->categoryCount,
            'user_count' => $this->userCount,
            'comment_count' => $this->commentCount,
        ];
    }
}
