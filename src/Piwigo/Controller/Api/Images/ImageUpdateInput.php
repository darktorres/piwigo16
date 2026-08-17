<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `PATCH /api/v1/images/{id}` body DTO. `singleValueMode` ("fillIfEmpty"
 * or "replace") and `multipleValueMode` ("append" or "replace") each
 * default to their first listed value.
 */
final readonly class ImageUpdateInput
{
    public function __construct(
        public ?string $file,
        public ?string $name,
        public ?string $author,
        public ?string $dateCreation,
        public ?string $comment,
        public ?string $categories,
        public ?string $tagIds,
        public ?int $level,
        public string $singleValueMode,
        public string $multipleValueMode,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $file = $raw['file'] ?? null;
        $name = $raw['name'] ?? null;
        $author = $raw['author'] ?? null;
        $dateCreation = $raw['dateCreation'] ?? null;
        $comment = $raw['comment'] ?? null;
        $categories = $raw['categories'] ?? null;
        $tagIds = $raw['tagIds'] ?? null;
        $level = $raw['level'] ?? null;
        $singleValueMode = $raw['singleValueMode'] ?? null;
        $multipleValueMode = $raw['multipleValueMode'] ?? null;

        return new self(
            file: is_string($file) ? $file : null,
            name: is_string($name) ? $name : null,
            author: is_string($author) ? $author : null,
            dateCreation: is_string($dateCreation) ? $dateCreation : null,
            comment: is_string($comment) ? $comment : null,
            categories: is_string($categories) ? $categories : null,
            tagIds: is_string($tagIds) ? $tagIds : null,
            level: is_int($level) ? $level : null,
            singleValueMode: is_string($singleValueMode) ? $singleValueMode : 'fillIfEmpty',
            multipleValueMode: is_string($multipleValueMode) ? $multipleValueMode : 'append',
        );
    }
}
