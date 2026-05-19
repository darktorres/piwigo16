<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Tags;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.tags.duplicate` input DTO. */
final readonly class DuplicateParams implements WsParams
{
    public function __construct(
        public int $tagId,
        public string $copyName,
        public string $pwgToken,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $tagId = $raw['tag_id'] ?? null;
        if (!is_numeric($tagId)) {
            throw new WsParamException('Missing or invalid `tag_id`');
        }
        $copyName = $raw['copy_name'] ?? null;
        if (!is_string($copyName) || $copyName === '') {
            throw new WsParamException('Missing or invalid `copy_name`');
        }
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        return new self(tagId: (int) $tagId, copyName: $copyName, pwgToken: $pwgToken);
    }
}
