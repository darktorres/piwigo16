<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.addComment` input DTO. */
final readonly class AddCommentParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public string $author,
        public string $content,
        public string $key,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $authorIn  = $raw['author']  ?? null;
        $contentIn = $raw['content'] ?? null;
        $keyIn     = $raw['key']     ?? null;
        return new self(
            imageId: is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            author:  trim(is_string($authorIn) ? $authorIn : ''),
            content: trim(is_string($contentIn) ? $contentIn : ''),
            key:     is_string($keyIn) ? $keyIn : '',
        );
    }
}
