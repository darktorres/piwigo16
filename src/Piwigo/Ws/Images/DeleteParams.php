<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Exception;
use Piwigo\Ws\WsParams;

/**
 * `pwg.images.delete` input DTO. `image_id`: `WsParamFlag::ACCEPT_ARRAY`
 * (not FORCE), no type flag, mandatory -- a plain string or an array,
 * never null; normalized here into a `list<int>` the same way the
 * god-class method this replaces did (splitting a scalar value on
 * `[\s,;\|]`, then filtering out non-positive ids). `pwg_token`: no
 * 'default' key -- mandatory, always a plain string.
 */
final readonly class DeleteParams implements WsParams
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        $rawImageId = $raw['image_id'] ?? null;

        if (is_array($rawImageId)) {
            $image_id_list = $rawImageId;
        } else {
            $split = preg_split(
                '/[\s,;\|]/',
                is_scalar($rawImageId) ? (string) $rawImageId : '',
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($split === false) {
                throw new Exception('delete(): preg_split() failed');
            }
            $image_id_list = $split;
        }

        $image_ids = [];
        foreach ($image_id_list as $raw_image_id) {
            $image_id = is_numeric($raw_image_id) ? (int) $raw_image_id : 0;
            if ($image_id > 0) {
                $image_ids[] = $image_id;
            }
        }

        return new self(
            imageIds: $image_ids,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
