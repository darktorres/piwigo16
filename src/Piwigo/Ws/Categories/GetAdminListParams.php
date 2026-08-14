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
 * `pwg.categories.getAdminList` input DTO. All keys have a 'default' key
 * in the registration, so all are always present.
 */
final readonly class GetAdminListParams implements WsParams
{
    public function __construct(
        public ?int $catId,
        public ?string $search,
        public bool $recursive,
        public ?string $additionalOutput,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $catId = $raw['cat_id'] ?? null;
        $search = $raw['search'] ?? null;
        $recursive = $raw['recursive'] ?? null;
        $additionalOutput = $raw['additional_output'] ?? null;

        return new self(
            catId: is_int($catId) ? $catId : null,
            search: is_string($search) ? $search : null,
            recursive: is_bool($recursive) ? $recursive : true,
            additionalOutput: is_string($additionalOutput) ? $additionalOutput : null,
        );
    }
}
