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
 * `pwg.images.exist` input DTO. `md5sum_list`/`filename_list`: no type
 * flag, null default -- both string|null.
 */
final readonly class ExistParams implements WsParams
{
    public function __construct(
        public ?string $md5sumList,
        public ?string $filenameList,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $md5sumList = $raw['md5sum_list'] ?? null;
        $filenameList = $raw['filename_list'] ?? null;

        return new self(
            md5sumList: is_string($md5sumList) ? $md5sumList : null,
            filenameList: is_string($filenameList) ? $filenameList : null,
        );
    }
}
