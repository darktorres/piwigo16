<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Ws\WsParams;

/**
 * `pwg.images.addChunk` input DTO. None of these have a type flag;
 * `data`/`original_sum`/`position` are mandatory (no 'default'), `type`
 * defaults to 'file' -- all always plain strings (see
 * `Server::invoke()`'s array-rejection check).
 */
final readonly class AddChunkParams implements WsParams
{
    public function __construct(
        public string $data,
        public string $originalSum,
        public string $type,
        public string $position,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $data = $raw['data'] ?? null;
        $originalSum = $raw['original_sum'] ?? null;
        $type = $raw['type'] ?? null;
        $position = $raw['position'] ?? null;

        return new self(
            data: is_string($data) ? $data : '',
            originalSum: is_string($originalSum) ? $originalSum : '',
            type: is_string($type) ? $type : 'file',
            position: is_string($position) ? $position : '',
        );
    }
}
