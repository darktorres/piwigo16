<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\History;

/**
 * `POST /api/v1/history/log` body DTO -- `imageId` is mandatory, everything
 * else optional.
 */
final readonly class HistoryLogInput
{
    public function __construct(
        public int $imageId,
        public ?int $catId,
        public ?string $section,
        public ?string $tagsString,
        public bool $isDownload,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $imageId = $raw['imageId'] ?? null;
        $catId = $raw['catId'] ?? null;
        $section = $raw['section'] ?? null;
        $tagsString = $raw['tagsString'] ?? null;
        $isDownload = $raw['isDownload'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
            catId: is_int($catId) ? $catId : null,
            section: is_string($section) && $section !== '' ? $section : null,
            tagsString: is_string($tagsString) && $tagsString !== '' ? $tagsString : null,
            isDownload: is_bool($isDownload) ? $isDownload : false,
        );
    }
}
