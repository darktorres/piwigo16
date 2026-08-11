<?php

declare(strict_types=1);

namespace Piwigo\History\Projection;

/**
 * {@see \Piwigo\History\HistoryRepository::search()}'s own row shape.
 *
 * `toArray()` preserves the exact original snake_case shape:
 * {@see \Piwigo\History\HistoryService::getHistory()} appends each row
 * into a caller-supplied `$data` array that a plugin's own `GetHistory`
 * event handler can also append genuinely heterogeneous
 * `array<string, mixed>` rows into (`Ws\Core::historyGet()` dispatches
 * via `dispatchChange()`) -- `getHistory()` calls `toArray()` before
 * appending, so this DTO never leaks into that plugin-extensible
 * boundary.
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
