<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.groups.merge` input DTO. */
final readonly class MergeParams implements WsParams
{
    /** @param list<int> $mergeGroupIds */
    public function __construct(
        public string $pwgToken,
        public int $destinationGroupId,
        public array $mergeGroupIds,
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
        $mergeRaw      = is_array($raw['merge_group_id'] ?? null) ? $raw['merge_group_id'] : [];
        $mergeGroupIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $mergeRaw,
        ));
        return new self(
            pwgToken:           $pwgToken,
            destinationGroupId: is_numeric($raw['destination_group_id'] ?? null) ? (int) $raw['destination_group_id'] : 0,
            mergeGroupIds:      $mergeGroupIds,
        );
    }
}
