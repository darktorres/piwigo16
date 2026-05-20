<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.addSimple` input DTO.
 *
 * `tags` is the raw value (may be a list or comma-separated string)
 * because the parsing rule (backslash-escaped commas) is intertwined
 * with the handler's tag-name lookups.
 */
final readonly class AddSimpleParams implements WsParams
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public int $imageId,
        public array $categoryIds,
        public ?string $name,
        public ?string $author,
        public ?string $comment,
        public mixed $level,
        public ?string $dateCreation,
        public mixed $tags,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $catRaw      = is_array($raw['category'] ?? null) ? $raw['category'] : [];
        $categoryIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $catRaw,
        ));
        $nameIn    = $raw['name']          ?? null;
        $authorIn  = $raw['author']        ?? null;
        $commIn    = $raw['comment']       ?? null;
        $dateIn    = $raw['date_creation'] ?? null;
        return new self(
            imageId:      is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            categoryIds:  $categoryIds,
            name:         is_string($nameIn) ? $nameIn : null,
            author:       is_string($authorIn) ? $authorIn : null,
            comment:      is_string($commIn) ? $commIn : null,
            level:        $raw['level'] ?? null,
            dateCreation: is_string($dateIn) ? $dateIn : null,
            tags:         $raw['tags']  ?? null,
        );
    }
}
