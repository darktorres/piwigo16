<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Tags;

use Piwigo\Ws\WsParams;

/**
 * `pwg.tags.getList` input DTO. `sort_by_counter`: non-null bool default, `WsParamType::BOOL` -- always present.
 */
final readonly class GetListParams implements WsParams
{
    public function __construct(
        public bool $sortByCounter,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $sortByCounter = $raw['sort_by_counter'] ?? null;

        return new self(
            sortByCounter: is_bool($sortByCounter) ? $sortByCounter : false,
        );
    }
}
