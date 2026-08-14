<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Comments;

use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Ws\WsParams;

/**
 * `pwg.userComments.delete` input DTO. `comment_id` is registered
 * `FORCE_ARRAY` + `WsParamType::INT | WsParamType::POSITIVE`, so
 * `Server::invoke()` has already coerced it to a list of positive ints
 * by the time this runs; `pwg_token` is mandatory (no OPTIONAL flag,
 * unchecked type -- scalar only, same as the god-class method this
 * replaces).
 */
final readonly class DeleteParams implements WsParams
{
    /**
     * @param list<CommentId> $commentIds
     */
    public function __construct(
        public array $commentIds,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $rawIds = is_array($raw['comment_id'] ?? null) ? $raw['comment_id'] : [];
        $ids = [];
        foreach ($rawIds as $rawId) {
            if (is_int($rawId)) {
                $ids[] = $rawId;
            } elseif (is_numeric($rawId)) {
                $ids[] = (int) $rawId;
            }
        }
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            commentIds: array_values(array_map(CommentId::from(...), array_unique($ids))),
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
