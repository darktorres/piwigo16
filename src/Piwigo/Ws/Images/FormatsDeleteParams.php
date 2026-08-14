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
 * `pwg.images.formats.delete` input DTO. `format_id`:
 * `WsParamType::ID + WsParamFlag::ACCEPT_ARRAY`, null default -- a plain
 * int, a list of ints, or null; normalized here into a `list<int>` the
 * same way the god-class method this replaces did (splitting a scalar
 * value on `[\s,;\|]`, then filtering out negative ids). `pwg_token`: no
 * 'default' key -- mandatory, always a plain string.
 *
 * @since 13
 */
final readonly class FormatsDeleteParams implements WsParams
{
    /**
     * @param list<int> $formatIds
     */
    public function __construct(
        public array $formatIds,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        $rawFormatId = $raw['format_id'] ?? null;

        if (is_array($rawFormatId)) {
            $format_id_list = $rawFormatId;
        } else {
            $split = preg_split(
                '/[\s,;\|]/',
                is_scalar($rawFormatId) ? (string) $rawFormatId : '',
                -1,
                PREG_SPLIT_NO_EMPTY
            );
            if ($split === false) {
                throw new Exception('formatsDelete(): preg_split() failed');
            }
            $format_id_list = $split;
        }

        $format_ids = [];
        foreach ($format_id_list as $raw_format_id) {
            $format_id = is_numeric($raw_format_id) ? (int) $raw_format_id : 0;
            if ($format_id >= 0) {
                $format_ids[] = $format_id;
            }
        }

        return new self(
            formatIds: $format_ids,
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
