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
 * `pwg.images.formats.searchImage` input DTO. `filename_list`: no
 * 'default' key -- mandatory, always a plain string.
 */
final readonly class FormatsSearchImageParams implements WsParams
{
    public function __construct(
        public string $filenameList,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $filenameList = $raw['filename_list'] ?? null;

        return new self(
            filenameList: is_string($filenameList) ? $filenameList : '',
        );
    }
}
