<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Comments;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/** `pwg.comments.delete` input DTO — list of comment ids to delete + csrf token. */
final readonly class DeleteParams implements WsParams
{
    /** @param list<int> $commentIds */
    public function __construct(
        public array $commentIds,
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
        $rawIds = is_array($raw['comment_id'] ?? null) ? $raw['comment_id'] : [];
        $strIds = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $rawIds);
        $commentIds = array_values(array_map(static fn (string $v): int => (int) $v, array_unique($strIds)));
        return new self(commentIds: $commentIds, pwgToken: $pwgToken);
    }
}
