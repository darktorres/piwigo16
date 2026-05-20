<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.tags.merge` input DTO. */
final readonly class MergeParams implements WsParams
{
    /** @param list<int> $mergeTagIds */
    public function __construct(
        public int $destinationTagId,
        public array $mergeTagIds,
        public string $pwgToken,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $destId = $raw['destination_tag_id'] ?? null;
        if (!is_numeric($destId)) {
            throw new WsParamException('Missing or invalid `destination_tag_id`');
        }
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        return new self(
            destinationTagId: (int) $destId,
            mergeTagIds:      array_values(array_map(
                static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
                is_array($raw['merge_tag_id'] ?? null) ? $raw['merge_tag_id'] : [],
            )),
            pwgToken: $pwgToken,
        );
    }
}
