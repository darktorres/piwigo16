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
 * `pwg.images.addFile` input DTO. `image_id`: `WsParamType::ID`,
 * mandatory. `type`: no type flag, defaults to 'file'. `sum` is a
 * registered param (mandatory, always a plain string) but deliberately
 * NOT a field here -- the god-class method this replaces never actually
 * read it (the real checksum comparison happens against
 * `UploadInfo::$md5sum`, not the client-sent `sum`), so there's nothing
 * to read it into.
 */
final readonly class AddFileParams implements WsParams
{
    public function __construct(
        public int $imageId,
        public string $type,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $imageId = $raw['image_id'] ?? null;
        $type = $raw['type'] ?? null;

        return new self(
            imageId: is_int($imageId) ? $imageId : 0,
            type: is_string($type) ? $type : 'file',
        );
    }
}
