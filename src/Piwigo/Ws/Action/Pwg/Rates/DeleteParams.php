<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Rates;

use Piwigo\Ws\WsParams;

/** `pwg.rates.delete` input DTO. */
final readonly class DeleteParams implements WsParams
{
    public function __construct(
        public int $userId,
        public ?string $anonymousId,
        public ?int $imageId,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        return new self(
            userId:      is_numeric($raw['user_id']     ?? null) ? (int) $raw['user_id'] : 0,
            anonymousId: !empty($raw['anonymous_id']) && is_string($raw['anonymous_id']) ? $raw['anonymous_id'] : null,
            imageId:     !empty($raw['image_id'])     && is_numeric($raw['image_id']) ? (int) $raw['image_id'] : null,
        );
    }
}
