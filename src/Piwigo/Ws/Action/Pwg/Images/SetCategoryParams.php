<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.images.setCategory` input DTO. */
final readonly class SetCategoryParams implements WsParams
{
    /** @param list<int> $imageIds */
    public function __construct(
        public string $pwgToken,
        public int $categoryId,
        public array $imageIds,
        public string $action,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        $rawIds   = is_array($raw['image_id'] ?? null) ? $raw['image_id'] : [];
        $imageIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $rawIds,
        ));
        $actionIn = $raw['action'] ?? null;
        return new self(
            pwgToken:   $pwgToken,
            categoryId: is_numeric($raw['category_id'] ?? null) ? (int) $raw['category_id'] : 0,
            imageIds:   $imageIds,
            action:     is_string($actionIn) ? $actionIn : '',
        );
    }
}
