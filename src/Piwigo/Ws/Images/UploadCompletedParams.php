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
 * `pwg.images.uploadCompleted` input DTO. `image_id`:
 * `WsParamFlag::ACCEPT_ARRAY` (not FORCE), no type flag, null default --
 * string, array, or null; normalized here into a `list<int>` the same
 * way the god-class method this replaces did (a null default treated as
 * an empty list, a scalar value split on `[\s,;\|]`, then filtered out
 * non-positive ids). `pwg_token`: no 'default' key -- mandatory, always
 * a plain string. `category_id`: `WsParamType::ID`, mandatory.
 *
 * @since 12
 */
final readonly class UploadCompletedParams implements WsParams
{
    /**
     * @param list<int> $imageIds
     */
    public function __construct(
        public array $imageIds,
        public string $pwgToken,
        public int $categoryId,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        $categoryId = $raw['category_id'] ?? null;
        $rawImageId = $raw['image_id'] ?? null;

        if ($rawImageId === null) {
            // documented null default (no image_id filter provided) --
            // treat the same as an empty list rather than reaching
            // preg_split() with a null subject.
            $image_id_list = [];
        } elseif (is_array($rawImageId)) {
            $image_id_list = $rawImageId;
        } else {
            $split = preg_split(
                '/[\s,;\|]/',
                is_scalar($rawImageId) ? (string) $rawImageId : '',
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($split === false) {
                throw new Exception('uploadCompleted(): preg_split() failed');
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
            categoryId: is_int($categoryId) ? $categoryId : 0,
        );
    }
}
