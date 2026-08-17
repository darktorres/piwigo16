<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

/**
 * Metadata sidecar for one in-progress tus upload -- everything
 * `TusUploadCompletionService` needs once the last byte arrives, parsed
 * once at creation time (`create()`) from the `Upload-Metadata` header
 * and round-tripped through `TusUploadStore`'s on-disk JSON sidecar
 * (`toArray()`/`fromArray()`) for every later `HEAD`/`PATCH`/`DELETE`.
 */
final readonly class TusUploadSession
{
    /**
     * @param list<int> $categoryIds
     * @param list<int> $tagIds
     */
    private function __construct(
        public string $id,
        public int $uploadLength,
        public int $createdByUserId,
        public string $filename,
        public array $categoryIds,
        public int $level,
        public ?string $name,
        public ?string $author,
        public ?string $comment,
        public ?string $dateCreation,
        public array $tagIds,
        public ?int $imageId,
        public ?int $formatOf,
    ) {}

    /**
     * @param array<string, string> $metadata parsed `Upload-Metadata` header
     */
    public static function create(string $id, int $uploadLength, int $userId, array $metadata): self
    {
        return new self(
            id: $id,
            uploadLength: $uploadLength,
            createdByUserId: $userId,
            filename: $metadata['filename'] ?? '',
            categoryIds: self::intList($metadata['category'] ?? null),
            level: isset($metadata['level']) && is_numeric($metadata['level']) ? (int) $metadata['level'] : 0,
            name: self::nonEmpty($metadata['name'] ?? null),
            author: self::nonEmpty($metadata['author'] ?? null),
            comment: self::nonEmpty($metadata['comment'] ?? null),
            dateCreation: self::nonEmpty($metadata['dateCreation'] ?? null),
            tagIds: self::intList($metadata['tags'] ?? null),
            imageId: isset($metadata['imageId']) && is_numeric($metadata['imageId']) ? (int) $metadata['imageId'] : null,
            formatOf: isset($metadata['formatOf']) && is_numeric($metadata['formatOf']) ? (int) $metadata['formatOf'] : null,
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            id: is_string($raw['id'] ?? null) ? $raw['id'] : '',
            uploadLength: is_int($raw['uploadLength'] ?? null) ? $raw['uploadLength'] : 0,
            createdByUserId: is_int($raw['createdByUserId'] ?? null) ? $raw['createdByUserId'] : 0,
            filename: is_string($raw['filename'] ?? null) ? $raw['filename'] : '',
            categoryIds: self::intArray($raw['categoryIds'] ?? null),
            level: is_int($raw['level'] ?? null) ? $raw['level'] : 0,
            name: is_string($raw['name'] ?? null) ? $raw['name'] : null,
            author: is_string($raw['author'] ?? null) ? $raw['author'] : null,
            comment: is_string($raw['comment'] ?? null) ? $raw['comment'] : null,
            dateCreation: is_string($raw['dateCreation'] ?? null) ? $raw['dateCreation'] : null,
            tagIds: self::intArray($raw['tagIds'] ?? null),
            imageId: is_int($raw['imageId'] ?? null) ? $raw['imageId'] : null,
            formatOf: is_int($raw['formatOf'] ?? null) ? $raw['formatOf'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uploadLength' => $this->uploadLength,
            'createdByUserId' => $this->createdByUserId,
            'filename' => $this->filename,
            'categoryIds' => $this->categoryIds,
            'level' => $this->level,
            'name' => $this->name,
            'author' => $this->author,
            'comment' => $this->comment,
            'dateCreation' => $this->dateCreation,
            'tagIds' => $this->tagIds,
            'imageId' => $this->imageId,
            'formatOf' => $this->formatOf,
        ];
    }

    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @return list<int>
     */
    private static function intList(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', $csv) as $piece) {
            if (is_numeric($piece)) {
                $ids[] = (int) $piece;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function intArray(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $v) {
            if (is_int($v)) {
                $ids[] = $v;
            }
        }

        return $ids;
    }
}
