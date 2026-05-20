<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.uploadAsync` input DTO. */
final readonly class UploadAsyncParams implements WsParams
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public string $originalSum,
        public int $imageId,
        public int $chunk,
        public int $chunks,
        public string $chunkSum,
        public ?string $filename,
        public array $categoryIds,
        public ?int $level,
        public ?int $imageIdForUpload,
        public ?string $name,
        public ?string $author,
        public ?string $comment,
        public ?string $dateCreation,
        public ?string $tagIds,
        public mixed $rawLevel,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $sumIn      = $raw['original_sum'] ?? null;
        $chunkSumIn = $raw['chunk_sum']    ?? null;
        $filenameIn = $raw['filename']     ?? null;
        $catRaw     = is_array($raw['category'] ?? null) ? $raw['category'] : [];
        $categoryIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $catRaw,
        ));
        $nameIn     = $raw['name']          ?? null;
        $authorIn   = $raw['author']        ?? null;
        $commIn     = $raw['comment']       ?? null;
        $dateIn     = $raw['date_creation'] ?? null;
        $tagsIn     = $raw['tag_ids']       ?? null;
        return new self(
            originalSum:      is_string($sumIn) ? $sumIn : '',
            imageId:          is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            chunk:            is_numeric($raw['chunk']    ?? null) ? (int) $raw['chunk'] : 0,
            chunks:           is_numeric($raw['chunks']   ?? null) ? (int) $raw['chunks'] : 0,
            chunkSum:         is_string($chunkSumIn) ? $chunkSumIn : '',
            filename:         is_scalar($filenameIn) ? (string) $filenameIn : null,
            categoryIds:      $categoryIds,
            level:            is_numeric($raw['level'] ?? null) ? (int) $raw['level'] : null,
            imageIdForUpload: is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : null,
            name:             array_key_exists('name', $raw) ? (is_scalar($nameIn) ? (string) $nameIn : '') : null,
            author:           array_key_exists('author', $raw) ? (is_scalar($authorIn) ? (string) $authorIn : '') : null,
            comment:          array_key_exists('comment', $raw) ? (is_scalar($commIn) ? (string) $commIn : '') : null,
            dateCreation:     array_key_exists('date_creation', $raw) ? (is_scalar($dateIn) ? (string) $dateIn : '') : null,
            tagIds:           array_key_exists('tag_ids', $raw) ? (is_string($tagsIn) ? $tagsIn : '') : null,
            rawLevel:         $raw['level'] ?? null,
        );
    }
}
