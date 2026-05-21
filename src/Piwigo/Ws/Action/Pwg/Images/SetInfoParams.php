<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.setInfo` input DTO.
 *
 * - `pwgToken` is the raw string (may be absent — handled as null).
 * - `name`/`author`/`comment`/`level`/`dateCreation`/`file` are nullable
 *   so the handler distinguishes "not supplied" from "empty string".
 * - `categories` / `tagIds` carry the raw strings; the handler keeps
 *   the original parsing rules (ValidationPattern::ID, CSV split, etc).
 */
final readonly class SetInfoParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public ?string $pwgToken,
        public ?string $name,
        public ?string $author,
        public ?string $comment,
        public mixed $level,
        public ?string $dateCreation,
        public ?string $file,
        public ?string $categories,
        public ?string $tagIds,
        public string $singleValueMode,
        public string $multipleValueMode,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgTokenIn  = $raw['pwg_token'] ?? null;
        $nameIn      = $raw['name']      ?? null;
        $authorIn    = $raw['author']    ?? null;
        $commIn      = $raw['comment']   ?? null;
        $dateIn      = $raw['date_creation'] ?? null;
        $fileIn      = $raw['file']      ?? null;
        $catIn       = $raw['categories'] ?? null;
        $tagsIn      = $raw['tag_ids']   ?? null;
        $singleIn    = $raw['single_value_mode']   ?? null;
        $multipleIn  = $raw['multiple_value_mode'] ?? null;
        return new self(
            imageId:           is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            pwgToken:          is_string($pwgTokenIn) ? $pwgTokenIn : null,
            name:              array_key_exists('name', $raw) ? (is_scalar($nameIn) ? (string) $nameIn : '') : null,
            author:            array_key_exists('author', $raw) ? (is_scalar($authorIn) ? (string) $authorIn : '') : null,
            comment:           array_key_exists('comment', $raw) ? (is_scalar($commIn) ? (string) $commIn : '') : null,
            level:             array_key_exists('level', $raw) ? $raw['level'] : null,
            dateCreation:      array_key_exists('date_creation', $raw) ? (is_string($dateIn) && $dateIn !== '' ? $dateIn : null) : null,
            file:              array_key_exists('file', $raw) ? (is_string($fileIn) ? $fileIn : '') : null,
            categories:        array_key_exists('categories', $raw) ? (is_string($catIn) ? $catIn : '') : null,
            tagIds:            array_key_exists('tag_ids', $raw) ? (is_string($tagsIn) ? $tagsIn : '') : null,
            singleValueMode:   is_string($singleIn) ? $singleIn : '',
            multipleValueMode: is_string($multipleIn) ? $multipleIn : '',
        );
    }
}
