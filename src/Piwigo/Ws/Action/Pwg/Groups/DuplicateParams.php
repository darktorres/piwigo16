<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Groups;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.groups.duplicate` input DTO. */
final readonly class DuplicateParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public int $groupId,
        public string $copyName,
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
        $copyNameIn = $raw['copy_name'] ?? null;
        return new self(
            pwgToken: $pwgToken,
            groupId:  is_numeric($raw['group_id'] ?? null) ? (int) $raw['group_id'] : 0,
            copyName: is_string($copyNameIn) ? $copyNameIn : '',
        );
    }
}
