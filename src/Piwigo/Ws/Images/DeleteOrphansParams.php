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
 * `pwg.images.deleteOrphans` input DTO. `block_size`:
 * `WsParamType::INT|POSITIVE`, default 1000 (non-null) -- always int.
 * `pwg_token`: no 'default' key -- mandatory, always string.
 */
final readonly class DeleteOrphansParams implements WsParams
{
    public function __construct(
        public int $blockSize,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $blockSize = $raw['block_size'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            blockSize: is_int($blockSize) ? $blockSize : 1000,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
