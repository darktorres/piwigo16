<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Categories;

use Piwigo\Ws\WsParams;

/**
 * `pwg.categories.getList` input DTO. All keys have a 'default' key in
 * the registration, so all are always present, and `cat_id`/`search`/
 * `limit`'s null default is a real possible runtime value since they
 * have no non-null-forcing type flag beyond `INT|POSITIVE` (which still
 * allows the null default through unchanged).
 */
final readonly class GetListParams implements WsParams
{
    public function __construct(
        public ?int $catId,
        public bool $recursive,
        public bool $public,
        public bool $treeOutput,
        public bool $fullname,
        public string $thumbnailSize,
        public ?string $search,
        public ?int $limit,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $catId = $raw['cat_id'] ?? null;
        $recursive = $raw['recursive'] ?? null;
        $public = $raw['public'] ?? null;
        $treeOutput = $raw['tree_output'] ?? null;
        $fullname = $raw['fullname'] ?? null;
        $thumbnailSize = $raw['thumbnail_size'] ?? null;
        $search = $raw['search'] ?? null;
        $limit = $raw['limit'] ?? null;

        return new self(
            catId: is_int($catId) ? $catId : null,
            recursive: is_bool($recursive) ? $recursive : false,
            public: is_bool($public) ? $public : false,
            treeOutput: is_bool($treeOutput) ? $treeOutput : false,
            fullname: is_bool($fullname) ? $fullname : false,
            thumbnailSize: is_string($thumbnailSize) ? $thumbnailSize : '',
            search: is_string($search) ? $search : null,
            limit: is_int($limit) ? $limit : null,
        );
    }
}
