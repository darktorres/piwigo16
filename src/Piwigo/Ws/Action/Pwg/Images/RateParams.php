<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParams;

/** `pwg.images.rate` input DTO. */
final readonly class RateParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public int $rate,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        return new self(
            imageId: is_numeric($raw['image_id'] ?? null) ? (int) $raw['image_id'] : 0,
            rate:    is_numeric($raw['rate']     ?? null) ? (int) $raw['rate'] : 0,
        );
    }
}
