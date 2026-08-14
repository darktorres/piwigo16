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
 * `pwg.users.setInfo` input DTO -- only extracts `pwg_token` for the
 * CSRF check. Every other registered key (`user_id`/`username`/
 * `password`/`email`/`status`/`level`/`language`/`theme`/`group_id`/
 * `nb_image_page`/`recent_period`/`expand`/`show_nb_comments`/
 * `show_nb_hits`/`enabled_high`) is handed to
 * `UserService::checkAndSaveUserInfos()` as the raw `$params` array
 * unchanged, same as the god-class method this replaces -- that method's
 * own `array $params` parameter is intentionally loosely typed (it
 * already does its own per-field `isset()`/`assert()` narrowing), so
 * there's no benefit to re-modeling its whole consumed shape here.
 */
final readonly class SetInfoParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;

        return new self(
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
        );
    }
}
