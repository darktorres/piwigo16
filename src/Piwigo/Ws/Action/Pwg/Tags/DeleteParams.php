<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.tags.delete` input DTO. */
final readonly class DeleteParams implements WsParams
{
    /** @param list<int> $tagIds */
    public function __construct(
        public array $tagIds,
        public string $pwgToken,
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
        $tagIdsRaw = is_array($raw['tag_id'] ?? null) ? $raw['tag_id'] : [];
        $tagIds = array_values(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $tagIdsRaw,
        ));
        return new self(tagIds: $tagIds, pwgToken: $pwgToken);
    }
}
