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
 * `pwg.images.uploadCompleted` input DTO. `image_id`: still registered
 * and accepted at the wire level (`ImagesMethodRegistrar`) for
 * client-SDK back-compat, but not carried here -- its only real reader,
 * the `WsImagesUploadCompleted` event, had zero production listeners and
 * was deleted (P32 Stage A5). `pwg_token`: no 'default' key -- mandatory,
 * always a plain string. `category_id`: `WsParamType::ID`, mandatory.
 *
 * @since 12
 */
final readonly class UploadCompletedParams implements WsParams
{
    public function __construct(
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

        return new self(
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
            categoryId: is_int($categoryId) ? $categoryId : 0,
        );
    }
}
