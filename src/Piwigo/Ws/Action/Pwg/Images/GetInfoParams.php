<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.getInfo` input DTO. */
final readonly class GetInfoParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public int $commentsPerPage,
        public int $commentsPage,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        return new self(
            imageId:         is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            commentsPerPage: is_numeric($raw['comments_per_page'] ?? null) ? (int) $raw['comments_per_page'] : 0,
            commentsPage:    is_numeric($raw['comments_page']     ?? null) ? (int) $raw['comments_page'] : 0,
        );
    }
}
