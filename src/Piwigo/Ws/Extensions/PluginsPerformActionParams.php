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
 * `pwg.plugins.performAction` input DTO. `action`/`plugin`/`pwg_token`
 * are all mandatory (no 'default' key, no 'type' flag), always present
 * as scalars by the time this runs.
 */
final readonly class PluginsPerformActionParams implements WsParams
{
    public function __construct(
        public string $action,
        public string $plugin,
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $action = $raw['action'] ?? null;
        $plugin = $raw['plugin'] ?? null;
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            action: is_string($action) ? $action : '',
            plugin: is_string($plugin) ? $plugin : '',
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
