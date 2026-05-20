<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\History;

use Piwigo\Ws\WsParams;

/** `pwg.history.log` input DTO. */
final readonly class LogParams implements WsParams
{
    public function __construct(
        public ?string $section,
        public mixed $catId,
        public string $tagsString,
        public ?int $imageId,
        public bool $isDownload,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $sectionIn = $raw['section']     ?? null;
        $tagsIn    = $raw['tags_string'] ?? null;
        $imageIn   = $raw['image_id']    ?? null;
        return new self(
            section:    is_string($sectionIn) && $sectionIn !== '' ? $sectionIn : null,
            catId:      $raw['cat_id'] ?? null,
            tagsString: is_string($tagsIn) ? $tagsIn : '',
            imageId:    is_numeric($imageIn) && (int) $imageIn !== 0 ? (int) $imageIn : null,
            isDownload: (bool) ($raw['is_download'] ?? false),
        );
    }
}
