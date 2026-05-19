<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Users;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.users.favorites.add` input DTO. */
final readonly class FavoritesAddParams implements WsParams
{
    public function __construct(public int $imageId)
    {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $imageId = $raw['image_id'] ?? null;
        if (!is_numeric($imageId)) {
            throw new WsParamException('Missing or invalid `image_id`');
        }
        return new self(imageId: (int) $imageId);
    }
}
