<?php

declare(strict_types=1);

namespace Piwigo\History\Projection;

/**
 * {@see \Piwigo\History\HistoryRepository::search()}'s own row shape.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * {@see \Piwigo\History\HistoryService::getHistory()} (the one real
 * caller is `Controller\Api\History\HistorySearchController`) appends
 * each row into a caller-supplied `$data` array, calling `toArray()`
 * before appending.
 */
final readonly class HistorySearchRow
{
    public function __construct(
        public ?string $date,
        public string $time,
        public int $userId,
        public string $ip,
        public ?string $section,
        public ?int $categoryId,
        public ?int $searchId,
        public ?string $tagIds,
        public ?int $imageId,
        public ?string $imageType,
    ) {}

    /**
     * @return array{date: ?string, time: string, user_id: int, IP: string, section: ?string, category_id: ?int, search_id: ?int, tag_ids: ?string, image_id: ?int, image_type: ?string}
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'time' => $this->time,
            'user_id' => $this->userId,
            'IP' => $this->ip,
            'section' => $this->section,
            'category_id' => $this->categoryId,
            'search_id' => $this->searchId,
            'tag_ids' => $this->tagIds,
            'image_id' => $this->imageId,
            'image_type' => $this->imageType,
        ];
    }
}
