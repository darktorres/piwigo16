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
 * `pwg.images.syncMetadata` input DTO. `image_id`:
 * `WsParamFlag::ACCEPT_ARRAY`, no type flag, mandatory (no 'default')
 * -- a plain string or an array, never null; split into a `list<string>`
 * here the same way the god-class method this replaces did (splitting a
 * scalar value on `[\s,;\|]`) rather than validated/coerced. `pwg_token`:
 * no 'default' key -- mandatory, always string.
 */
final readonly class SyncMetadataParams implements WsParams
{
    /**
     * @param list<string> $imageIds
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
                is_string($rawImageId) ? $rawImageId : '',
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($split === false) {
                throw new Exception('syncMetadata(): preg_split() failed');
            }
            $image_id_list = $split;
        }

        $imageIds = [];
        foreach ($image_id_list as $imageId) {
            if (is_scalar($imageId)) {
                $imageIds[] = (string) $imageId;
            }
        }

        return new self(
            imageIds: $imageIds,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
