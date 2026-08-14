<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Extensions;

use Piwigo\Ws\WsParams;

/**
 * `pwg.themes.performAction` input DTO. `action`/`theme`/`pwg_token`
 * are all mandatory (no 'default' key, no 'type' flag), always present
 * as scalars by the time this runs.
 */
final readonly class ThemesPerformActionParams implements WsParams
{
    public function __construct(
        public string $action,
        public string $theme,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $action = $raw['action'] ?? null;
        $theme = $raw['theme'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            action: is_string($action) ? $action : '',
            theme: is_string($theme) ? $theme : '',
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
