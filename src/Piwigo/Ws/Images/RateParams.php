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
 * `pwg.images.rate` input DTO. `image_id`: `WsParamType::ID`, no
 * 'default' key -- mandatory, always int. `rate`: `WsParamType::FLOAT`,
 * no 'default' key -- mandatory, always float.
 */
final readonly class RateParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public float $rate,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $imageId = $raw['image_id'] ?? null;
        $rate = $raw['rate'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
            rate: is_float($rate) ? $rate : 0.0,
        );
    }
}
