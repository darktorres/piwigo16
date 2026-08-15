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
 * `pwg.users.setMyInfo` input DTO. Every field but `pwgToken` is
 * `optionalFlag()`-registered -- null means genuinely absent from the
 * request. `newPassword`/`confNewPassword` aren't part of
 * `UserService::checkAndSaveUserInfos()`'s own `UserInfoUpdateInput` --
 * `SetMyInfoHandler` verifies them against the current password itself
 * and folds the result into `UserInfoUpdateInput::$password`.
 */
final readonly class SetMyInfoParams implements WsParams
{
    public function __construct(
        public string $pwgToken,
        public ?string $email = null,
        public ?int $nbImagePage = null,
        public ?string $theme = null,
        public ?string $language = null,
        public ?int $recentPeriod = null,
        public ?bool $expand = null,
        public ?bool $showNbComments = null,
        public ?bool $showNbHits = null,
        public ?string $password = null,
        public ?string $newPassword = null,
        public ?string $confNewPassword = null,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): static
    {
        $pwgToken = $raw['pwg_token'] ?? null;
        $email = $raw['email'] ?? null;
        $nbImagePage = $raw['nb_image_page'] ?? null;
        $theme = $raw['theme'] ?? null;
        $language = $raw['language'] ?? null;
        $recentPeriod = $raw['recent_period'] ?? null;
        $expand = $raw['expand'] ?? null;
        $showNbComments = $raw['show_nb_comments'] ?? null;
        $showNbHits = $raw['show_nb_hits'] ?? null;
        $password = $raw['password'] ?? null;
        $newPassword = $raw['new_password'] ?? null;
        $confNewPassword = $raw['conf_new_password'] ?? null;

        return new self(
            pwgToken: is_string($pwgToken) ? $pwgToken : '',
            email: is_string($email) ? $email : null,
            nbImagePage: is_int($nbImagePage) ? $nbImagePage : null,
            theme: is_string($theme) ? $theme : null,
            language: is_string($language) ? $language : null,
            recentPeriod: is_int($recentPeriod) ? $recentPeriod : null,
            expand: is_bool($expand) ? $expand : null,
            showNbComments: is_bool($showNbComments) ? $showNbComments : null,
            showNbHits: is_bool($showNbHits) ? $showNbHits : null,
            password: is_string($password) ? $password : null,
            newPassword: is_string($newPassword) ? $newPassword : null,
            confNewPassword: is_string($confNewPassword) ? $confNewPassword : null,
        );
    }
}
