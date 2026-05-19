<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParamException;
use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.move` input DTO.
 *
 * `categoryIds` accepts a list or a separator-joined string (commas,
 * spaces, semicolons, pipes); both normalize to a non-empty list of
 * positive ints. `parentId === 0` means "move to root".
 */
final readonly class MoveParams implements WsParams
{
    /** @param list<int<1, max>> $categoryIds */
    public function __construct(
        public array $categoryIds,
        public int $parentId,
        public string $pwgToken,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $rawCatId = $raw['category_id'] ?? null;
        if (is_array($rawCatId)) {
            $catIdList = $rawCatId;
        } elseif (is_string($rawCatId)) {
            $split     = preg_split('/[\s,;\|]/', $rawCatId, -1, PREG_SPLIT_NO_EMPTY);
            $catIdList = $split !== false ? $split : [];
        } else {
            $catIdList = [];
        }
        $categoryIds = array_values(array_filter(
            array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catIdList),
            static fn (int $v): bool => $v > 0,
        ));
        if ($categoryIds === []) {
            throw new WsParamException('Invalid category_id input parameter, no category to move');
        }
        $pwgToken = $raw['pwg_token'] ?? null;
        if (!is_string($pwgToken)) {
            throw new WsParamException('Missing pwg_token');
        }
        return new self(
            categoryIds: $categoryIds,
            parentId:    is_numeric($raw['parent'] ?? null) ? (int) $raw['parent'] : 0,
            pwgToken:    $pwgToken,
        );
    }
}
