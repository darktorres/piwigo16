<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.getImages` input DTO.
 *
 * Only captures the discrete keys; the WsHelper SQL filter/order
 * helpers continue to read from the raw `$params` array.
 */
final readonly class GetImagesParams implements WsParams
{
    /** @param list<int> $catIds */
    public function __construct(
        public array $catIds,
        public bool $recursive,
        public int $perPage,
        public int $page,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $rawCatId = is_array($raw['cat_id'] ?? null) ? $raw['cat_id'] : [];
        $catIds   = array_values(array_unique(array_map(
            static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0,
            $rawCatId,
        )));
        return new self(
            catIds:    $catIds,
            recursive: (bool) ($raw['recursive'] ?? false),
            perPage:   is_numeric($raw['per_page'] ?? null) ? (int) $raw['per_page'] : 0,
            page:      is_numeric($raw['page']     ?? null) ? (int) $raw['page'] : 0,
        );
    }
}
