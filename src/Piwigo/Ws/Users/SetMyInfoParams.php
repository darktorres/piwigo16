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
 * `pwg.users.setMyInfo` input DTO -- only extracts `pwg_token` for the
 * CSRF check. Every other registered key (`email`/`nb_image_page`/
 * `theme`/`language`/`recent_period`/`expand`/`show_nb_comments`/
 * `show_nb_hits`/`password`/`new_password`/`conf_new_password`) is read
 * and conditionally `unset()` directly off the raw `$params` array
 * before it's handed to `UserService::checkAndSaveUserInfos()`, same as
 * the god-class method this replaces -- see `SetInfoParams`'s own
 * docblock for why that method's loosely-typed `array $params` isn't
 * worth re-modeling here.
 */
final readonly class SetMyInfoParams implements WsParams
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
