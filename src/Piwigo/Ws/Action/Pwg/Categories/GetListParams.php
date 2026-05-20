<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.getList` input DTO.
 *
 * `limit` is nullable + has the original isset semantics tracked
 * separately so the handler can distinguish "no limit" from "limit 0".
 */
final readonly class GetListParams implements WsParams
{
    public function __construct(
        public int $catId,
        public bool $recursive,
        public bool $public,
        public string $thumbnailSize,
        public ?int $limit,
        public ?string $search,
        public bool $fullname,
        public bool $treeOutput,
    ) {
    }

    /** @param array<int|string, mixed> $raw */
    #[\Override]
    public static function fromArray(array $raw): self
    {
        $thumbIn  = $raw['thumbnail_size'] ?? null;
        $searchIn = $raw['search'] ?? null;
        $limitIn  = $raw['limit'] ?? null;
        return new self(
            catId:         is_numeric($raw['cat_id'] ?? null) ? (int) $raw['cat_id'] : 0,
            recursive:     (bool) ($raw['recursive'] ?? false),
            public:        (bool) ($raw['public'] ?? false),
            thumbnailSize: is_string($thumbIn) ? $thumbIn : '',
            limit:         is_numeric($limitIn) ? (int) $limitIn : null,
            search:        is_string($searchIn) && $searchIn !== '' ? $searchIn : null,
            fullname:      (bool) ($raw['fullname'] ?? false),
            treeOutput:    (bool) ($raw['tree_output'] ?? false),
        );
    }
}
