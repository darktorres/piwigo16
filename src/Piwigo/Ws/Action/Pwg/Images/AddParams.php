<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.add` input DTO. */
final readonly class AddParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public string $originalSum,
        public ?string $originalFilename,
        public ?int $level,
        public bool $checkUniqueness,
        public ?string $name,
        public ?string $author,
        public ?string $comment,
        public ?string $dateCreation,
        public ?string $categories,
        public ?string $tagIds,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $sumIn  = $raw['original_sum'] ?? null;
        $fnIn   = $raw['original_filename'] ?? null;
        $nameIn = $raw['name']    ?? null;
        $autIn  = $raw['author']  ?? null;
        $comIn  = $raw['comment'] ?? null;
        $dateIn = $raw['date_creation'] ?? null;
        $catIn  = $raw['categories'] ?? null;
        $tagIn  = $raw['tag_ids'] ?? null;
        return new self(
            imageId:          is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            originalSum:      is_string($sumIn) ? $sumIn : '',
            originalFilename: is_scalar($fnIn) ? (string) $fnIn : null,
            level:            is_numeric($raw['level'] ?? null) ? (int) $raw['level'] : null,
            checkUniqueness:  (bool) ($raw['check_uniqueness'] ?? false),
            name:             array_key_exists('name', $raw) ? (is_scalar($nameIn) ? (string) $nameIn : '') : null,
            author:           array_key_exists('author', $raw) ? (is_scalar($autIn) ? (string) $autIn : '') : null,
            comment:          array_key_exists('comment', $raw) ? (is_scalar($comIn) ? (string) $comIn : '') : null,
            dateCreation:     array_key_exists('date_creation', $raw) ? (is_scalar($dateIn) ? (string) $dateIn : '') : null,
            categories:       array_key_exists('categories', $raw) ? (is_string($catIn) ? $catIn : '') : null,
            tagIds:           array_key_exists('tag_ids', $raw) ? (is_string($tagIn) ? $tagIn : '') : null,
        );
    }
}
