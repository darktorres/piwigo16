<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Users;

use Piwigo\Ws\WsParams;

/**
 * `pwg.users.preferences.set` input DTO. `param`: no 'default' key --
 * mandatory, always present. `value`: `OPTIONAL` with no 'default' key
 * -- may be entirely absent. `is_json`: non-null bool default, always
 * present.
 */
final readonly class PreferencesSetParams implements WsParams
{
    public function __construct(
        public string $param,
        public ?string $value,
        public bool $isJson,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $param = $raw['param'] ?? null;
        $value = $raw['value'] ?? null;
        $isJson = $raw['is_json'] ?? null;

        return new self(
            param: is_string($param) ? $param : '',
            value: is_string($value) ? $value : null,
            isJson: is_bool($isJson) ? $isJson : false,
        );
    }
}
